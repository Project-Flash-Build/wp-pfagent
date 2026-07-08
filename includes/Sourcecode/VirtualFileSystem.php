<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Sourcecode;

/**
 * The virtual filesystem the authoring LLM sees.
 *
 * Path layout:
 *   /lib/nodes.d.ts                          (read-only, auto-maintained)
 *   /lib/manage.d.ts                         (read-only, auto-maintained)
 *   /workflows/<id>__<slug>.pfflow           (read+write, real workflows)
 *   /workflows/new__<slug>.pfflow            (write to create a new workflow)
 *   /templates/<slug>.pfflow                 (read-only, built-in templates)
 *
 * Every TS-flavoured artefact is pre-built and cached at the moment
 * its source-of-truth changes — never on read. Specifically:
 *   - /lib/nodes.d.ts   is built by wp-pfworkflow's TypingsBuilder
 *                       and pulled through the filter
 *                       `projectflash_workflow_typings_dts`.
 *   - /lib/manage.d.ts  is built by wp-pfmanagement's TypingsBuilder
 *                       and pulled through the filter
 *                       `projectflash_management_typings_dts`.
 *   - /workflows/*      come from `DecompileCache` (postmeta-cached
 *                       per workflow id, refreshed on each save).
 *   - /templates/*      come from `TemplateDecompileCache` (option-
 *                       cached map keyed by slug, refreshed on plugin
 *                       upgrade).
 *
 * Write/edit semantics: writes update a per-path DRAFT buffer (transient
 * storage) AND compile to produce a preview. They do NOT touch the
 * underlying workflow in wp-pfworkflow. The customer commits the draft by
 * pressing Apply in the chat UI, which calls the `apply` REST endpoint.
 *
 * Reads return: the draft if one exists, otherwise the live source from
 * the cache.
 */
final class VirtualFileSystem
{
    public const PATH_PREFIX_WORKFLOWS = '/workflows/';
    public const PATH_PREFIX_TEMPLATES = '/templates/';
    public const PATH_LIB_NODES = '/lib/nodes.d.ts';
    public const PATH_LIB_MANAGE = '/lib/manage.d.ts';
    public const PATH_LIB_VARIABLES = '/lib/variables.d.ts';

    /**
     * @return array<int, array<string, mixed>>  list of { path, kind, writable, sizeBytes? }
     */
    public function list(string $prefix = '/'): array
    {
        $out = [];
        if ($prefix === '/' || strncmp($prefix, '/lib', 4) === 0) {
            $out[] = [
                'path' => self::PATH_LIB_NODES,
                'kind' => 'library',
                'writable' => false,
                'description' => 'TypeScript declarations of every workflow verb (the call surface).',
            ];
            $out[] = [
                'path' => self::PATH_LIB_MANAGE,
                'kind' => 'library',
                'writable' => false,
                'description' => 'TypeScript declarations of pfmanagement entities, events and actions (the data-model surface).',
            ];
            $out[] = [
                'path' => self::PATH_LIB_VARIABLES,
                'kind' => 'library',
                'writable' => false,
                'description' => 'TypeScript declarations of the current workflow\'s variables (the variable editor surface, regenerated per workflow). Pass workflow_id when reading this file outside an active editing session.',
            ];
        }
        if ($prefix === '/' || strncmp($prefix, self::PATH_PREFIX_WORKFLOWS, strlen(self::PATH_PREFIX_WORKFLOWS)) === 0 || $prefix === rtrim(self::PATH_PREFIX_WORKFLOWS, '/')) {
            // Synthetic hint entry: always shown so the LLM sees the
            // exact path shape expected for creating a new workflow,
            // BEFORE it tries write_file with a malformed path.
            $out[] = [
                'path' => self::PATH_PREFIX_WORKFLOWS . 'new__<slug>.pfflow',
                'kind' => 'workflow_new_hint',
                'writable' => true,
                'description' => 'Write to this path (substituting <slug> with lowercase letters/digits/underscore/hyphen) to create a brand-new workflow. The .pfflow extension is required.',
            ];
            foreach ($this->listWorkflows() as $entry) {
                $out[] = $entry;
            }
        }
        if ($prefix === '/' || strncmp($prefix, self::PATH_PREFIX_TEMPLATES, strlen(self::PATH_PREFIX_TEMPLATES)) === 0 || $prefix === rtrim(self::PATH_PREFIX_TEMPLATES, '/')) {
            foreach ($this->listTemplates() as $entry) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>  { path, source, draft?, lastError?, kind }
     */
    public function read(string $path, ?int $workflow_id = null): array
    {
        $path = $this->normalizePath($path);
        if ($path === self::PATH_LIB_NODES) {
            return [
                'path' => $path,
                'source' => LibraryBuilder::nodesLibrary(),
                'kind' => 'library',
                'writable' => false,
            ];
        }
        if ($path === self::PATH_LIB_MANAGE) {
            return [
                'path' => $path,
                'source' => LibraryBuilder::manageLibrary(),
                'kind' => 'library',
                'writable' => false,
            ];
        }
        if ($path === self::PATH_LIB_VARIABLES) {
            // Per-workflow. Resolve order:
            //   1. explicit workflow_id arg (caller knows which workflow it
            //      is editing — typical when the agent is iterating on a
            //      file under /workflows/<id>__*.pfflow);
            //   2. most-recently-edited workflow path in the draft index
            //      (covers the "just wrote a draft, now reads the variables"
            //      pattern without forcing the agent to repeat the id);
            //   3. null → stub explaining there is no workflow in context.
            $resolved_id = $workflow_id > 0 ? $workflow_id : $this->inferActiveWorkflowId();
            $source = LibraryBuilder::variablesLibrary($resolved_id);
            return [
                'path' => $path,
                'source' => $source,
                'kind' => 'library',
                'writable' => false,
                'workflowId' => $resolved_id,
            ];
        }
        if ($wf_id = $this->workflowIdFromPath($path)) {
            $live = DecompileCache::read($wf_id);
            return [
                'path' => $path,
                'workflowId' => $wf_id,
                'kind' => 'workflow',
                'writable' => true,
                'source' => $live,
                'draft' => false,
                'liveSource' => $live,
                'lastError' => DecompileCache::readError($wf_id),
            ];
        }
        if ($this->isNewWorkflowPath($path)) {
            // The `new__<slug>` form means "the LLM hasn't written this
            // yet OR it's iterating on a draft it just wrote". If a
            // workflow was already created in this VFS for this slug,
            // read its current source from the live record; otherwise
            // return the empty-draft stub so the LLM knows it's a
            // greenfield path.
            $wf_id = $this->resolveWorkflowIdFromPath($path);
            if ($wf_id !== null) {
                $live = DecompileCache::read($wf_id);
                return [
                    'path' => $path,
                    'workflowId' => $wf_id,
                    'kind' => 'workflow',
                    'writable' => true,
                    'source' => $live,
                    'draft' => false,
                    'liveSource' => $live,
                    'lastError' => DecompileCache::readError($wf_id),
                ];
            }
            return [
                'path' => $path,
                'kind' => 'workflow_new',
                'writable' => true,
                'source' => "// pfflow v1\n// Empty draft. Declare a trigger to begin.\n",
                'draft' => false,
            ];
        }
        if (strncmp($path, self::PATH_PREFIX_TEMPLATES, strlen(self::PATH_PREFIX_TEMPLATES)) === 0) {
            $slug = $this->slugFromPath($path);
            if ($slug === null) {
                throw new \RuntimeException(sprintf('Template not found: %s', $path));
            }
            $source = TemplateDecompileCache::source($slug);
            if ($source === null) {
                throw new \RuntimeException(sprintf('Template not found: %s', $path));
            }
            return [
                'path' => $path,
                'kind' => 'template',
                'writable' => false,
                'source' => $source,
            ];
        }
        throw new \RuntimeException(sprintf('File not found: %s', $path));
    }

    /**
     * Write a complete file. Compiles and, on success, persists the
     * workflow into wp-pfworkflow directly as a real draft (post_status
     * 'draft' equivalent — the wp-pfworkflow status field is forced to
     * 'draft' regardless of any value the source declared). The
     * operator sees it immediately in the workflow list filtered by
     * Draft, the same place every other draft lives. Activating the
     * workflow (draft → active) is a separate, side-effecting tool
     * (`activate_workflow`) that always asks for confirmation.
     *
     * If the path is `/workflows/<id>__<slug>.pfflow` the existing
     * workflow is updated. If the path is `/workflows/new__<slug>.pfflow`
     * we look up any workflow already published by this conversation
     * with the same slug (post_name) — same slug means same workflow,
     * so iterations of the LLM during one turn rewrite the same draft
     * instead of creating a fresh row each compile cycle.
     *
     * @return array<string, mixed>  { path, source, workflowId, created, status, name }
     */
    public function write(string $path, string $source): array
    {
        $path = $this->normalizePath($path);
        if ($path === self::PATH_LIB_NODES || $path === self::PATH_LIB_MANAGE || strncmp($path, self::PATH_PREFIX_TEMPLATES, strlen(self::PATH_PREFIX_TEMPLATES)) === 0) {
            throw new \RuntimeException(sprintf('File is read-only: %s', $path));
        }
        $wfIdFromPath = $this->workflowIdFromPath($path);
        if (!$wfIdFromPath && !$this->isNewWorkflowPath($path)) {
            throw new \RuntimeException($this->writeRejectMessage($path));
        }

        // Resolve the workflow id BEFORE compiling. The compiler asks
        // the variables resolver for `<Var>$Variable$Get/Set` entries
        // keyed by workflow_id; if we pass 0 here on a `new__<slug>`
        // path that already maps to a real workflow with variables,
        // the compiler can't see them and rejects every `Var$Variable$Get`
        // identifier as unknown_virtual_node — even though create_variable
        // ran successfully against the same workflow earlier in the
        // turn. Use the same path-→-workflow lookup we use for upsert
        // so compilation and persistence are aligned.
        $slug = $this->slugFromPath($path);
        $targetWorkflowId = $this->resolveWorkflowIdFromPath($path);
        $wf_id_for_compile = (int) ($wfIdFromPath ?? $targetWorkflowId ?? 0);
        $compiled = Compiler::compile($source, $wf_id_for_compile);
        $graph = is_array($compiled['graph'] ?? null) ? $compiled['graph'] : [];

        $is_new = $targetWorkflowId === null;
        $name = (string) ($compiled['workflow']['name'] ?? $this->humanizeSlug($slug ?? 'workflow'));
        // Status is always 'draft' coming out of write_file — activating
        // a workflow requires the operator's explicit consent via the
        // activate_workflow tool (sideEffect=true). The source language
        // CAN declare `status 'active'`, but write_file overrides it to
        // honour the discipline.
        $payload = [
            'workflow' => [
                'id' => $is_new ? -1 : $targetWorkflowId,
                'name' => $name,
                'status' => 'draft',
            ],
            'graph' => $this->mergeStudioVariables($graph, $targetWorkflowId),
        ];

        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'agent_workflow_apply')) {
            throw new \RuntimeException('wp-pfworkflow service unavailable.');
        }
        $envelope = $service->agent_workflow_apply($payload);
        if (is_wp_error($envelope)) {
            throw new \RuntimeException($envelope->get_error_message());
        }
        $persistedId = (int) ($envelope['content']['workflow']['id'] ?? 0);

        // Stamp the path slug as postmeta so later read/activate/delete
        // calls on the same `/workflows/new__<slug>.pfflow` path resolve
        // back to the same workflow. We can't rely on post_name —
        // WordPress derives it from the title, which has spaces and
        // gets a different slug than the path declares ("Status check"
        // → post_name `status-check` but the path says `status-check`
        // only when the title happens to match; usually they diverge).
        if ($persistedId > 0 && $slug !== null) {
            update_post_meta($persistedId, '_pfa_path_slug', $slug);
        }

        return [
            'path' => $path,
            'source' => $source,
            'workflowId' => $persistedId,
            'created' => $is_new,
            'status' => 'draft',
            'name' => $name,
        ];
    }

    /**
     * Merge editor-declared workflow variables (studio.variables on the
     * live wp-pfworkflow record) into the compiled graph. The compiler
     * emits a graph from source alone and does not see operator-declared
     * variables; without this merge the apply would overwrite
     * studio.variables with the compiler's (empty when source only
     * READS variables.X.get()) view, wiping the editor-declared
     * variables on every iteration.
     *
     * @param array<string, mixed> $graph
     * @return array<string, mixed>
     */
    private function mergeStudioVariables(array $graph, ?int $existingWorkflowId): array
    {
        if ($existingWorkflowId === null) {
            return $graph;
        }
        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'get_workflow')) {
            return $graph;
        }
        $live = $service->get_workflow($existingWorkflowId);
        $live_graph = is_array($live) && is_array($live['graph'] ?? null) ? $live['graph'] : [];
        $live_studio = is_array($live_graph['studio'] ?? null) ? $live_graph['studio'] : [];
        $live_vars = is_array($live_studio['variables'] ?? null)
            ? array_values(array_filter($live_studio['variables'], 'is_array'))
            : [];
        $studio = is_array($graph['studio'] ?? null) ? $graph['studio'] : [];
        $compiled_vars = is_array($studio['variables'] ?? null)
            ? array_values(array_filter($studio['variables'], 'is_array'))
            : [];
        $merged = [];
        $seen = [];
        foreach ($live_vars as $v) {
            $id = (string) ($v['id'] ?? '');
            if ($id === '' || isset($seen[$id])) { continue; }
            $seen[$id] = true;
            $merged[] = $v;
        }
        foreach ($compiled_vars as $v) {
            $id = (string) ($v['id'] ?? '');
            if ($id === '' || isset($seen[$id])) { continue; }
            $seen[$id] = true;
            $merged[] = $v;
        }
        $studio['variables'] = $merged;
        $graph['studio'] = $studio;
        return $graph;
    }

    /**
     * Edit by string replacement. Reads the current draft (or live source),
     * performs the substitution, then writes back.
     *
     * @return array<string, mixed>
     */
    public function edit(string $path, string $oldStr, string $newStr): array
    {
        $current = $this->read($path);
        $source = (string) $current['source'];
        if ($oldStr === '') {
            throw new \RuntimeException('edit_file requires a non-empty oldStr to locate the replacement.');
        }
        $count = substr_count($source, $oldStr);
        if ($count === 0) {
            throw new \RuntimeException(sprintf('edit_file did not find oldStr in %s. Ensure the snippet matches exactly (including indentation).', $path));
        }
        if ($count > 1) {
            throw new \RuntimeException(sprintf('edit_file oldStr appears %d times in %s. Make it unique by including more surrounding context.', $count, $path));
        }
        $updated = str_replace($oldStr, $newStr, $source);
        return $this->write($path, $updated);
    }

    public function move(string $from, string $to): void
    {
        $from = $this->normalizePath($from);
        $to = $this->normalizePath($to);
        $wf_id = $this->workflowIdFromPath($from);
        if (!$wf_id) {
            throw new \RuntimeException('move_file is only supported for /workflows/<id>__<slug>.pfflow paths.');
        }
        // Rename → update the workflow's name on the live record. The slug
        // portion of the path is derived from the name on the next backfill.
        $new_slug = $this->slugFromPath($to);
        if ($new_slug === null) {
            throw new \RuntimeException('move_file destination must be /workflows/<id>__<slug>.pfflow.');
        }
        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'update_workflow')) {
            throw new \RuntimeException('wp-pfworkflow service unavailable.');
        }
        $existing = $service->get_workflow($wf_id);
        if (!is_array($existing)) {
            throw new \RuntimeException(sprintf('Workflow #%d not found.', $wf_id));
        }
        $service->update_workflow($wf_id, [
            'name' => $this->humanizeSlug($new_slug),
            'status' => (string) ($existing['status'] ?? 'draft'),
            'graph' => is_array($existing['graph'] ?? null) ? $existing['graph'] : [],
        ]);
    }

    public function delete(string $path): void
    {
        $path = $this->normalizePath($path);
        $wf_id = $this->resolveWorkflowIdFromPath($path);
        if ($wf_id === null) {
            throw new \RuntimeException('delete_file is only supported for /workflows/... paths backed by a real workflow.');
        }
        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'delete_workflow')) {
            throw new \RuntimeException('wp-pfworkflow service unavailable.');
        }
        $service->delete_workflow($wf_id, true);
    }

    /**
     * Activate a workflow that's currently in `draft` status: switches it
     * to `active`. This is the only place draft → active happens, and
     * the calling tool is marked sideEffect=true so the runtime opens
     * the confirmation modal automatically. Path → workflow id resolves
     * via the same rule write/read use (explicit id in the path or
     * post_name match for the `new__<slug>` form).
     *
     * @return array<string, mixed>
     */
    public function activate(string $path): array
    {
        $path = $this->normalizePath($path);
        $wf_id = $this->resolveWorkflowIdFromPath($path);
        if ($wf_id === null) {
            throw new \RuntimeException(sprintf('No workflow found for %s.', $path));
        }
        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'agent_workflow_apply')) {
            throw new \RuntimeException('wp-pfworkflow service unavailable.');
        }
        $existing = $service->get_workflow($wf_id);
        if (!is_array($existing)) {
            throw new \RuntimeException(sprintf('Workflow #%d not found.', $wf_id));
        }
        $envelope = $service->agent_workflow_apply([
            'workflow' => [
                'id' => $wf_id,
                'name' => (string) ($existing['name'] ?? $this->humanizeSlug($this->slugFromPath($path) ?? 'workflow')),
                'status' => 'active',
            ],
            'graph' => is_array($existing['graph'] ?? null) ? $existing['graph'] : [],
        ]);
        if (is_wp_error($envelope)) {
            throw new \RuntimeException($envelope->get_error_message());
        }

        // Confirmar por READ-BACK, no reportar 'active' a ciegas. El apply puede
        // devolver un envelope sin error y AUN ASÍ dejar el workflow en draft
        // (visto tras un write_file grande de re-estructura): un success
        // silencioso que engaña al agente. Releemos el estado REAL persistido y,
        // si no cuajó la activación, fallamos RUIDOSO para que el agente
        // reintente en vez de creer que quedó vivo.
        $after = $service->get_workflow($wf_id);
        $actual_status = is_array($after) ? (string) ($after['status'] ?? '') : '';
        if ($actual_status !== 'active') {
            throw new \RuntimeException(sprintf(
                'activate_workflow did not stick: workflow #%d is still "%s" after apply (expected "active"). Re-run activate_workflow on %s.',
                $wf_id,
                $actual_status !== '' ? $actual_status : 'unknown',
                $path
            ));
        }

        return [
            'workflowId' => $wf_id,
            'name' => (string) (is_array($after) ? ($after['name'] ?? $existing['name'] ?? '') : ($existing['name'] ?? '')),
            'status' => $actual_status,
            'wasStatus' => (string) ($existing['status'] ?? 'draft'),
        ];
    }

    /**
     * Resolve the wp-pfworkflow id behind a /workflows/... path. The
     * explicit `<id>__<slug>` form wins; the `new__<slug>` form falls
     * back to a postmeta `_pfa_path_slug` lookup, the slug we stamped
     * when write_file first persisted the workflow. We don't use
     * post_name because WordPress derives it from the title and the
     * two only line up by coincidence.
     */
    private function resolveWorkflowIdFromPath(string $path): ?int
    {
        $explicit = $this->workflowIdFromPath($path);
        if ($explicit !== null) {
            return $explicit;
        }
        $slug = $this->slugFromPath($path);
        if ($slug === null) {
            return null;
        }
        $existing = get_posts([
            'post_type' => 'pfw_workflow',
            'post_status' => 'any',
            'meta_key' => '_pfa_path_slug',
            'meta_value' => $slug,
            'numberposts' => 1,
            'fields' => 'ids',
        ]);
        return is_array($existing) && !empty($existing) ? (int) $existing[0] : null;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path !== '' && $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $path;
    }

    public function workflowIdFromPath(string $path): ?int
    {
        if (preg_match('#^' . preg_quote(self::PATH_PREFIX_WORKFLOWS, '#') . '(\d+)__[a-z0-9_\-]+\.pfflow$#i', $path, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Find the most-recently-modified workflow the operator has in
     * wp-pfworkflow. Used as a fallback when the agent reads
     * /lib/variables.d.ts without an explicit workflow_id; the
     * assumption is the agent just wrote or is editing that workflow.
     * Returns null on a fresh install with zero workflows.
     */
    public function inferActiveWorkflowId(): ?int
    {
        $rows = get_posts([
            'post_type' => 'pfw_workflow',
            'post_status' => 'any',
            'numberposts' => 1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
        ]);
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        return (int) $rows[0];
    }

    private function isNewWorkflowPath(string $path): bool
    {
        return (bool) preg_match('#^' . preg_quote(self::PATH_PREFIX_WORKFLOWS, '#') . 'new__[a-z0-9_\-]+\.pfflow$#i', $path);
    }

    /**
     * Build a targeted error message for write_file calls that landed on a
     * path the VFS does not accept. The LLM dropped the `.pfflow` extension
     * repeatedly in early runs and the generic "Unsupported path" message
     * gave it nothing to fix-forward on; this surfaces the exact pattern
     * needed for the most likely intent.
     */
    private function writeRejectMessage(string $path): string
    {
        $under_workflows = strncmp($path, self::PATH_PREFIX_WORKFLOWS, strlen(self::PATH_PREFIX_WORKFLOWS)) === 0;
        if ($under_workflows) {
            $remainder = substr($path, strlen(self::PATH_PREFIX_WORKFLOWS));
            $missing_ext = $remainder !== '' && !preg_match('/\.pfflow$/i', $remainder);
            if ($missing_ext) {
                return sprintf(
                    'Unsupported path: %s — the .pfflow extension is required. To create a new workflow write to /workflows/new__<slug>.pfflow (slug = lowercase letters, digits, underscore or hyphen). To edit an existing workflow write to /workflows/<id>__<slug>.pfflow exactly as listed by list_files.',
                    $path
                );
            }
            return sprintf(
                'Unsupported path: %s — does not match /workflows/new__<slug>.pfflow (for a new workflow) nor /workflows/<id>__<slug>.pfflow (for an existing one, as listed by list_files). Slug must be lowercase letters, digits, underscore or hyphen.',
                $path
            );
        }
        return sprintf(
            'Unsupported path: %s — write_file only accepts /workflows/new__<slug>.pfflow (new workflow) or /workflows/<id>__<slug>.pfflow (existing workflow listed by list_files). Files under /lib and /templates are read-only.',
            $path
        );
    }

    private function slugFromPath(string $path): ?string
    {
        if (preg_match('#/([^/]+)\.pfflow$#i', $path, $m)) {
            $stem = $m[1];
            $pos = strpos($stem, '__');
            return $pos === false ? $stem : substr($stem, $pos + 2);
        }
        return null;
    }

    private function humanizeSlug(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listWorkflows(): array
    {
        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'agent_workflow_list')) {
            return [];
        }
        // Use a lean listing (no graphs in the result we use, just metadata).
        $envelope = $service->agent_workflow_list(['limit' => 200]);
        $items = is_array($envelope['content'] ?? null) ? $envelope['content'] : [];
        $out = [];
        foreach ($items as $item) {
            $content = is_array($item['content'] ?? null) ? $item['content'] : [];
            $wf = is_array($content['workflow'] ?? null) ? $content['workflow'] : [];
            $id = (int) ($wf['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $slug = $this->slugify((string) ($wf['name'] ?? ('workflow-' . $id)));
            $out[] = [
                'path' => self::PATH_PREFIX_WORKFLOWS . $id . '__' . $slug . '.pfflow',
                'kind' => 'workflow',
                'writable' => true,
                'workflowId' => $id,
                'name' => (string) ($wf['name'] ?? ''),
                'status' => (string) ($wf['status'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listTemplates(): array
    {
        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'agent_template_list')) {
            return [];
        }
        $envelope = $service->agent_template_list();
        $items = is_array($envelope['content'] ?? null) ? $envelope['content'] : [];
        $out = [];
        foreach ($items as $item) {
            $content = is_array($item['content'] ?? null) ? $item['content'] : [];
            $wf = is_array($content['workflow'] ?? null) ? $content['workflow'] : [];
            $slug = (string) ($wf['slug'] ?? $this->slugify((string) ($wf['name'] ?? 'template')));
            $out[] = [
                'path' => self::PATH_PREFIX_TEMPLATES . $slug . '.pfflow',
                'kind' => 'template',
                'writable' => false,
                'name' => (string) ($wf['name'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function templateFromPath(string $path): ?array
    {
        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'agent_template_list')) {
            return null;
        }
        $slug = $this->slugFromPath($path);
        if ($slug === null) {
            return null;
        }
        $envelope = $service->agent_template_list();
        $items = is_array($envelope['content'] ?? null) ? $envelope['content'] : [];
        foreach ($items as $item) {
            $content = is_array($item['content'] ?? null) ? $item['content'] : [];
            $wf = is_array($content['workflow'] ?? null) ? $content['workflow'] : [];
            if ((string) ($wf['slug'] ?? '') === $slug || $this->slugify((string) ($wf['name'] ?? '')) === $slug) {
                return [
                    'name' => (string) ($wf['name'] ?? ''),
                    'status' => 'draft',
                    'graph' => is_array($content['graph'] ?? null) ? $content['graph'] : [],
                ];
            }
        }
        return null;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug === '' ? 'workflow' : $slug;
    }
}

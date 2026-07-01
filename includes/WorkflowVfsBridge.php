<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use ProjectFlash\Agent\Sourcecode\CompileError;
use ProjectFlash\Agent\Sourcecode\VirtualFileSystem;
use WP_Error;

/**
 * Dispatches the file tools the authoring LLM uses to read / mutate
 * workflow source files in its virtual filesystem.
 *
 * Tool surface — registered in `config/agent-tools.json`:
 *   list_files(prefix?)           -> array of file entries
 *   read_file(path)               -> { path, source, ... }
 *   write_file(path, contents)    -> { path, workflowId, created, status, name }
 *   edit_file(path, oldStr, newStr) -> { path, workflowId, created, status, name }
 *   move_file(from, to)           -> { from, to } (side effect)
 *   delete_file(path)             -> { path, deleted: true } (side effect)
 *
 * The bridge is invisible to the customer. The chat UI must NOT surface
 * the source / contents arguments — see [[feedback_client_never_sees_dsl]].
 */
final class WorkflowVfsBridge
{
    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $tool
     * @return array<string, mixed>|WP_Error
     */
    public function execute(string $tool_name, array $arguments, array $tool)
    {
        if (!WorkflowDependency::is_active()) {
            return new WP_Error('pfa_workflow_inactive', __('WP PFWorkflow plugin is not active.', 'wp-pfagent'), ['status' => 409]);
        }

        $vfs = new VirtualFileSystem();

        try {
            $payload = match ($tool_name) {
                'list_files' => $this->listFiles($vfs, $arguments),
                'read_file' => $this->readFile($vfs, $arguments),
                'write_file' => $this->writeFile($vfs, $arguments),
                'edit_file' => $this->editFile($vfs, $arguments),
                'move_file' => $this->moveFile($vfs, $arguments),
                'delete_file' => $this->deleteFile($vfs, $arguments),
                'activate_workflow' => $this->activateWorkflow($vfs, $arguments),
                'create_variable' => $this->createVariable($arguments),
                default => new WP_Error('pfa_agent_tool_not_allowed', __('Tool is not executable by the VFS bridge.', 'wp-pfagent'), ['status' => 400]),
            };
            if ($payload instanceof WP_Error) {
                return $payload;
            }
            // F19: wrap every successful VFS bridge return in the canonical
            // `{content, contextForYou}` envelope so the LLM's mental model
            // matches the PFM bridge surface. Without this the agent saw
            // flat `{entries, schema, ...}` from VFS but `{content, contextForYou}`
            // from PFM, which created two parallel parsing paths in the
            // system prompt and caused tool_arguments drift.
            return self::wrapEnvelope($tool_name, $payload);
        } catch (CompileError $e) {
            return new WP_Error(
                'pfa_source_compile_error',
                $e->getMessage(),
                ['status' => 422, 'compile' => $e->toArray()]
            );
        } catch (\Throwable $e) {
            return new WP_Error('pfa_vfs_error', $e->getMessage(), ['status' => 400]);
        }
    }

    /**
     * F19 helper: wrap a flat VFS tool return in the canonical
     * `{content, contextForYou}` shape. The contextForYou prose is
     * tool-specific so the LLM gets the same kind of advisory text it
     * sees on PFM bridge calls.
     *
     * @param array<string, mixed> $payload
     * @return array{content: array<string, mixed>, contextForYou: string}
     */
    private static function wrapEnvelope(string $tool_name, array $payload): array
    {
        $contextForYou = match ($tool_name) {
            'list_files' => 'A /lib listing is always cheap; workflow listings reflect both active and draft. Prefer read_file on a specific path over re-listing the prefix.',
            'read_file' => 'Read /lib/nodes.d.ts and /lib/manage.d.ts before authoring new source so the typed identifiers compile on the first try.',
            'write_file' => 'write_file persists a DRAFT. The workflow does not fire until activate_workflow runs on the same path.',
            'edit_file' => 'edit_file is a single-shot substitution. If oldStr appears multiple times the call fails — quote enough context to be unique.',
            'move_file' => 'move_file preserves the workflow_id and status. Use it to rename a draft path before activation.',
            'delete_file' => 'delete_file is destructive and unrecoverable. Confirm with the operator before invoking on an active workflow.',
            'activate_workflow' => 'activate_workflow flips a draft to active. Live runs may fire immediately on the next matching trigger event.',
            'create_variable' => 'Variables exist at workflow scope. Reference the returned getter / setter identifiers in workflow body code; the operator can edit defaults in the variable editor afterwards.',
            default => '',
        };
        return [
            'content' => $payload,
            'contextForYou' => $contextForYou,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function listFiles(VirtualFileSystem $vfs, array $arguments): array
    {
        $prefix = isset($arguments['prefix']) ? (string) $arguments['prefix'] : '/';
        return [
            'schema' => 'projectflash.agent.vfs.list',
            'schemaVersion' => 1,
            'prefix' => $prefix,
            'entries' => $vfs->list($prefix),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function readFile(VirtualFileSystem $vfs, array $arguments): array
    {
        $path = (string) ($arguments['path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('read_file requires "path".');
        }
        $workflow_id = null;
        if (isset($arguments['workflow_id']) && is_numeric($arguments['workflow_id'])) {
            $workflow_id = (int) $arguments['workflow_id'];
        }
        $entry = $vfs->read($path, $workflow_id);
        return [
            'schema' => 'projectflash.agent.vfs.read',
            'schemaVersion' => 1,
        ] + $entry;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function writeFile(VirtualFileSystem $vfs, array $arguments): array
    {
        $path = (string) ($arguments['path'] ?? '');
        $contents = $arguments['contents'] ?? null;
        if ($path === '' || !is_string($contents)) {
            throw new \RuntimeException('write_file requires "path" and string "contents".');
        }
        $result = $vfs->write($path, $contents);
        return [
            'schema' => 'projectflash.agent.vfs.write',
            'schemaVersion' => 1,
            'path' => $result['path'],
            'workflowId' => $result['workflowId'] ?? null,
            'created' => $result['created'] ?? false,
            'status' => $result['status'] ?? 'draft',
            'name' => $result['name'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function editFile(VirtualFileSystem $vfs, array $arguments): array
    {
        $path = (string) ($arguments['path'] ?? '');
        $old = $arguments['oldStr'] ?? null;
        $new = $arguments['newStr'] ?? null;
        if ($path === '' || !is_string($old) || !is_string($new)) {
            throw new \RuntimeException('edit_file requires "path", "oldStr", "newStr".');
        }
        $result = $vfs->edit($path, $old, $new);
        return [
            'schema' => 'projectflash.agent.vfs.edit',
            'schemaVersion' => 1,
            'path' => $result['path'],
            'workflowId' => $result['workflowId'] ?? null,
            'created' => $result['created'] ?? false,
            'status' => $result['status'] ?? 'draft',
            'name' => $result['name'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function moveFile(VirtualFileSystem $vfs, array $arguments): array
    {
        $from = (string) ($arguments['from'] ?? '');
        $to = (string) ($arguments['to'] ?? '');
        if ($from === '' || $to === '') {
            throw new \RuntimeException('move_file requires "from" and "to".');
        }
        $vfs->move($from, $to);
        return ['schema' => 'projectflash.agent.vfs.move', 'schemaVersion' => 1, 'from' => $from, 'to' => $to];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function deleteFile(VirtualFileSystem $vfs, array $arguments): array
    {
        $path = (string) ($arguments['path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('delete_file requires "path".');
        }
        $vfs->delete($path);
        return ['schema' => 'projectflash.agent.vfs.delete', 'schemaVersion' => 1, 'path' => $path, 'deleted' => true];
    }

    /**
     * Declare a workflow variable so the LLM can read/write it from a
     * `.pfflow` file. Once persisted the variable surfaces in
     * /lib/variables.d.ts on the next read_file call (the LLM should
     * refresh after creating one), and the compiler resolves
     * `<Capitalized>$Variable$Get` / `...$Set` identifiers back to
     * workflow.get_variable / workflow.set_variable automatically.
     *
     * Args: { workflow_id, name, type, value? }.
     *   name: any human form ("Estado buscado", "estadoBuscado",
     *         "estado_buscado") — normalised to a stable snake_case
     *         slug before storage (sanitize_key in wp-pfworkflow).
     *   type: one of the enriched types accepted by wp-pfworkflow
     *         (see system prompt for the list).
     *   value: optional default value (becomes both `default` and
     *         `defaultValue` on the persisted record).
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function createVariable(array $arguments): array
    {
        $workflow_id = isset($arguments['workflow_id']) ? (int) $arguments['workflow_id'] : 0;
        if ($workflow_id <= 0) {
            throw new \RuntimeException('create_variable requires a positive "workflow_id".');
        }
        $rawName = (string) ($arguments['name'] ?? '');
        if (trim($rawName) === '') {
            throw new \RuntimeException('create_variable requires a non-empty "name".');
        }
        $type = (string) ($arguments['type'] ?? 'string');
        $value = $arguments['value'] ?? '';

        // Normalise the human-supplied name to a stable slug. We
        // accept three common forms and collapse them to the same
        // snake_case identifier so the LLM doesn't have to worry
        // about which casing convention to use:
        //   "Estado Buscado"   → estado_buscado
        //   "estadoBuscado"    → estado_buscado
        //   "estado_buscado"   → estado_buscado (no-op)
        // The wp-pfworkflow side also runs sanitize_key as a final
        // safety net but doing it here keeps the label readable.
        $slug = self::slugifyVariableName($rawName);
        $label = self::humanizeVariableName($rawName);

        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'agent_add_variable')) {
            throw new \RuntimeException('wp-pfworkflow service unavailable.');
        }
        $payload = [
            'id' => $slug,
            'name' => $slug,
            'label' => $label,
            'type' => $type,
            'defaultValue' => is_scalar($value) ? (string) $value : '',
        ];
        $result = $service->agent_add_variable($workflow_id, $payload);
        if (is_object($result) && $result instanceof \WP_Error) {
            throw new \RuntimeException($result->get_error_message());
        }

        // Build the TS identifier the same way VariablesTypingsBuilder
        // does (first letter capitalised) so the response tells the
        // LLM exactly what to type in the .pfflow file.
        $tsIdent = ucfirst($slug);
        return [
            'schema' => 'projectflash.agent.workflow.variable_created',
            'schemaVersion' => 1,
            'workflowId' => $workflow_id,
            'name' => $slug,
            'label' => $label,
            'type' => $type,
            'identifier' => $tsIdent,
            'getterIdentifier' => $tsIdent . '$Variable$Get',
            'setterIdentifier' => $tsIdent . '$Variable$Set',
        ];
    }

    /**
     * Normalise a human-supplied variable name to a stable snake_case
     * slug. Handles spaces, camelCase, PascalCase, and existing
     * snake_case uniformly.
     */
    private static function slugifyVariableName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }
        // Split camelCase / PascalCase boundaries with underscores
        // (`estadoBuscado` → `estado_Buscado`).
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $trimmed) ?? $trimmed;
        // Replace any non-alphanumeric run with a single underscore.
        $under = preg_replace('/[^A-Za-z0-9]+/', '_', $spaced) ?? $spaced;
        $slug = strtolower(trim((string) $under, '_'));
        return $slug === '' ? 'variable' : $slug;
    }

    /**
     * Build a human-readable label from a raw variable name. First
     * word capitalised, the rest lowercase, separators replaced by
     * spaces ("estado_buscado" / "estadoBuscado" / "Estado Buscado"
     * → "Estado buscado").
     */
    private static function humanizeVariableName(string $name): string
    {
        $slug = self::slugifyVariableName($name);
        if ($slug === '') {
            return '';
        }
        return ucfirst(str_replace('_', ' ', $slug));
    }

    /**
     * Activate a workflow that lives in wp-pfworkflow as a draft so it
     * starts firing on its trigger. This is the only tool that promotes
     * a workflow's lifecycle status (draft → active). Side effect:
     * runs are now produced live, so the runtime opens the confirmation
     * modal before invoking.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function activateWorkflow(VirtualFileSystem $vfs, array $arguments): array
    {
        $path = (string) ($arguments['path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('activate_workflow requires "path".');
        }
        $result = $vfs->activate($path);
        return [
            'schema' => 'projectflash.agent.workflow.activate',
            'schemaVersion' => 1,
            'path' => $path,
        ] + $result;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Public snake_case method surface — what the Framework's FilterBridgeTool
    // invokes after resolving the `projectflash_agent_vfs_bridge` filter.
    // FilterBridgeTool calls $service->list_files($args) etc. directly, so
    // the bridge needs methods that match the names declared in
    // config/agent-tools.json's phpService.method. Each delegates to the
    // existing execute() dispatcher (same WP_Error / CompileError handling,
    // same VFS instance per call). Pre-Framework, AgentRuntime called
    // execute('list_files', $args, $tool) directly via an injected bridge;
    // post-Sprint-C the injection vanished and the LLM saw bridge_unavailable
    // for every VFS call. These wrappers restore the bridge surface for the
    // new path without touching the internal dispatcher.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|\WP_Error
     */
    public function list_files(array $arguments)
    {
        return $this->execute('list_files', $arguments, []);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|\WP_Error
     */
    public function read_file(array $arguments)
    {
        return $this->execute('read_file', $arguments, []);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|\WP_Error
     */
    public function write_file(array $arguments)
    {
        return $this->execute('write_file', $arguments, []);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|\WP_Error
     */
    public function edit_file(array $arguments)
    {
        return $this->execute('edit_file', $arguments, []);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|\WP_Error
     */
    public function move_file(array $arguments)
    {
        return $this->execute('move_file', $arguments, []);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|\WP_Error
     */
    public function delete_file(array $arguments)
    {
        return $this->execute('delete_file', $arguments, []);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|\WP_Error
     */
    public function activate_workflow(array $arguments)
    {
        return $this->execute('activate_workflow', $arguments, []);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|\WP_Error
     */
    public function create_variable(array $arguments)
    {
        return $this->execute('create_variable', $arguments, []);
    }
}

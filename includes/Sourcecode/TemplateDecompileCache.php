<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Sourcecode;

/**
 * Keeps every wp-pfworkflow template's decompiled source up to date in
 * a single wp_option so VFS reads are O(1) — no decompile on read.
 *
 * Templates are static data inside wp-pfworkflow (TemplateCatalog) and
 * change only when the plugin is upgraded. Backfill runs on plugin
 * activation AND whenever the cached `pfa_templates_pfw_version`
 * stamp drifts from the running `PFW_VERSION`. That second check
 * fires at most once per request and only writes when the deploy
 * version actually changed — so a steady-state agent read is a
 * single get_option call.
 *
 * Storage shape (option `pfa_templates_source`):
 *   {
 *     '<slug>' => '<decompiled .pfflow source>',
 *     ...
 *   }
 */
final class TemplateDecompileCache
{
    public const OPTION_SOURCE = 'pfa_templates_source';
    public const OPTION_AT = 'pfa_templates_generated_at';
    public const OPTION_VERSION = 'pfa_templates_pfw_version';

    public static function init(): void
    {
        add_action('init', [self::class, 'ensure_fresh'], 5);
    }

    public static function activate(): void
    {
        self::backfill();
    }

    /**
     * One-shot per-request check that the cached source corresponds to
     * the running wp-pfworkflow version. Cheap — a single option read
     * + string compare. Only writes if drifted.
     */
    public static function ensure_fresh(): void
    {
        if (!defined('PFW_VERSION')) {
            return;
        }
        $cached = (string) get_option(self::OPTION_VERSION, '');
        if ($cached === PFW_VERSION) {
            return;
        }
        self::backfill();
    }

    /**
     * Decompile every registered template and persist the map.
     *
     * @return array{processed:int, failed:int}
     */
    public static function backfill(): array
    {
        $processed = 0;
        $failed = 0;
        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'agent_template_list')) {
            return ['processed' => 0, 'failed' => 0];
        }
        $envelope = $service->agent_template_list();
        $items = is_array($envelope['content'] ?? null) ? $envelope['content'] : [];

        $map = [];
        foreach ($items as $item) {
            $content = is_array($item['content'] ?? null) ? $item['content'] : [];
            $wf = is_array($content['workflow'] ?? null) ? $content['workflow'] : [];
            $slug = (string) ($wf['slug'] ?? '');
            if ($slug === '') {
                $slug = self::slugify((string) ($wf['name'] ?? ''));
            }
            if ($slug === '') {
                $failed++;
                continue;
            }
            $template = [
                'name' => (string) ($wf['name'] ?? ''),
                'status' => 'draft',
                'graph' => is_array($content['graph'] ?? null) ? $content['graph'] : [],
            ];
            try {
                $source = Decompiler::decompile($template);
                $map[$slug] = $source;
                $processed++;
            } catch (\Throwable $e) {
                // Skip the broken template; logged for diagnostics. The
                // other templates still cache fine.
                error_log('[pfagent] template "' . $slug . '" decompile failed: ' . $e->getMessage());
                $failed++;
            }
        }

        update_option(self::OPTION_SOURCE, $map, false);
        update_option(self::OPTION_AT, gmdate(DATE_ATOM), false);
        if (defined('PFW_VERSION')) {
            update_option(self::OPTION_VERSION, PFW_VERSION, false);
        }
        return ['processed' => $processed, 'failed' => $failed];
    }

    /**
     * @return array<string, string>  slug → decompiled source
     */
    public static function all(): array
    {
        $map = get_option(self::OPTION_SOURCE, []);
        return is_array($map) ? array_filter($map, 'is_string') : [];
    }

    public static function source(string $slug): ?string
    {
        $map = self::all();
        return isset($map[$slug]) && is_string($map[$slug]) ? $map[$slug] : null;
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }
}

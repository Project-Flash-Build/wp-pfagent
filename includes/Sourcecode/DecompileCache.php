<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Sourcecode;

/**
 * Keeps each workflow's decompiled source up to date in postmeta so the
 * LLM's virtual filesystem reads are O(1) (no decompile on read).
 *
 * Hooked on `projectflash_workflow_changed` — wp-pfworkflow fires this
 * action whenever a workflow is saved (via the designer or via
 * pfagent's apply tool). We re-decompile and persist as `_pfa_source`
 * + `_pfa_source_at` postmeta on the pfw_workflow post.
 *
 * Plugin activation triggers a one-shot backfill of every existing
 * workflow.
 */
final class DecompileCache
{
    public const META_SOURCE = '_pfa_source';
    public const META_SOURCE_AT = '_pfa_source_at';
    public const META_SOURCE_VERSION = '_pfa_source_version';
    public const META_ERROR = '_pfa_source_error';
    public const SOURCE_FORMAT_VERSION = 1;

    public static function init(): void
    {
        add_action('projectflash_workflow_changed', [self::class, 'on_workflow_changed'], 20, 1);
    }

    public static function activate(): void
    {
        self::backfill();
    }

    /**
     * Re-decompile and persist for the given workflow id. Called from the
     * workflow-changed hook; also exposed for the manual REST refresh
     * endpoint and the backfill loop.
     */
    public static function refresh(int $workflow_id): bool
    {
        if ($workflow_id <= 0) {
            return false;
        }
        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'agent_workflow_full')) {
            return false;
        }
        $envelope = $service->agent_workflow_full($workflow_id);
        if (!is_array($envelope) || !is_array($envelope['content'] ?? null)) {
            return false;
        }
        $content = $envelope['content'];
        $workflow = [
            'id' => $workflow_id,
            'name' => (string) ($content['workflow']['name'] ?? ''),
            'status' => (string) ($content['workflow']['status'] ?? 'draft'),
            'graph' => is_array($content['graph'] ?? null) ? $content['graph'] : [],
        ];

        try {
            $source = Decompiler::decompile($workflow);
        } catch (CompileError $e) {
            update_post_meta($workflow_id, self::META_ERROR, $e->getMessage());
            return false;
        }

        delete_post_meta($workflow_id, self::META_ERROR);
        update_post_meta($workflow_id, self::META_SOURCE, $source);
        update_post_meta($workflow_id, self::META_SOURCE_AT, gmdate(DATE_ATOM));
        update_post_meta($workflow_id, self::META_SOURCE_VERSION, self::SOURCE_FORMAT_VERSION);
        return true;
    }

    /**
     * Re-decompile every existing workflow. Called on activation and from
     * a manual REST endpoint.
     *
     * @return array{processed:int, failed:int}
     */
    public static function backfill(): array
    {
        $processed = 0;
        $failed = 0;

        $query = new \WP_Query([
            'post_type' => 'pfw_workflow',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        foreach ($query->posts as $id) {
            $ok = self::refresh((int) $id);
            if ($ok) {
                $processed++;
            } else {
                $failed++;
            }
        }
        return ['processed' => $processed, 'failed' => $failed];
    }

    public static function on_workflow_changed($workflow_id): void
    {
        $id = is_array($workflow_id) ? (int) ($workflow_id['id'] ?? 0) : (int) $workflow_id;
        if ($id > 0) {
            self::refresh($id);
        }
    }

    public static function read(int $workflow_id): string
    {
        $cached = (string) get_post_meta($workflow_id, self::META_SOURCE, true);
        if ($cached !== '') {
            return $cached;
        }
        // Lazy populate if missing (e.g. workflow created before the cache
        // existed, or backfill missed it).
        self::refresh($workflow_id);
        return (string) get_post_meta($workflow_id, self::META_SOURCE, true);
    }

    public static function readError(int $workflow_id): ?string
    {
        $err = (string) get_post_meta($workflow_id, self::META_ERROR, true);
        return $err === '' ? null : $err;
    }
}

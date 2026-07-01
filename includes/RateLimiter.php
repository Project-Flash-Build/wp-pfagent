<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

/**
 * Per-user fixed-window rate limiter backed by WordPress transients.
 *
 * Buckets are scoped by current_user_id so unauthenticated callers and
 * different operators do not share quota. The window resets every
 * $window_seconds; the counter is tracked under a transient keyed on the
 * current window start so counters never accumulate across windows.
 */
final class RateLimiter
{
    private const TRANSIENT_PREFIX = 'pfa_rate_';

    /**
     * Default per-bucket limits. Tunable through the
     * `pfa_rate_limit_defaults` filter at runtime.
     *
     * @var array<string, array{limit: int, window: int}>
     */
    private const DEFAULTS = [
        'reads' => ['limit' => 60, 'window' => 60],
        'config' => ['limit' => 20, 'window' => 60],
        // Agents legitimately chain many tool-call rounds + confirmation
        // round-trips per minute on multi-step build tasks; the prior caps
        // (10/min) stalled long marathons mid-phase. Bumped to 120/min;
        // operators can still throttle via the `pfa_rate_limit_defaults`
        // filter if a deployment needs tighter quotas.
        'llm' => ['limit' => 120, 'window' => 60],
        'agent_turn' => ['limit' => 120, 'window' => 60],
    ];

    /**
     * Consume one slot from the bucket. Returns true on success or a
     * WP_Error with status 429 when the bucket is exhausted for the
     * current window.
     */
    public function consume(string $bucket_key): bool|WP_Error
    {
        $bucket_key = sanitize_key($bucket_key);
        if ($bucket_key === '') {
            return true;
        }

        $config = $this->config_for($bucket_key);
        $limit = (int) ($config['limit'] ?? 0);
        $window_seconds = (int) ($config['window'] ?? 0);
        if ($limit <= 0 || $window_seconds <= 0) {
            return true;
        }

        $user_id = get_current_user_id();
        $window_start = (int) (floor(time() / $window_seconds) * $window_seconds);
        $transient_key = self::TRANSIENT_PREFIX . $bucket_key . '_' . $user_id . '_' . $window_start;

        $current = get_transient($transient_key);
        $count = is_numeric($current) ? (int) $current : 0;

        if ($count >= $limit) {
            $retry_after = max(1, ($window_start + $window_seconds) - time());

            return new WP_Error(
                'pfa_rate_limited',
                sprintf(__('Rate limit reached for "%s" (%d per %ds). Retry in %ds.', 'wp-pfagent'), $bucket_key, $limit, $window_seconds, $retry_after),
                [
                    'status' => 429,
                    'retryAfter' => $retry_after,
                    'limit' => $limit,
                    'windowSeconds' => $window_seconds,
                    'bucket' => $bucket_key,
                ]
            );
        }

        // Expire one window past the boundary so concurrent calls inside the
        // same window still see the counter even if the WP cache lags.
        set_transient($transient_key, $count + 1, $window_seconds * 2);

        return true;
    }

    /**
     * Reset every counter (useful in smoke tests). Use with care: this
     * deletes only transients owned by this rate limiter.
     */
    public function reset_all(): void
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return;
        }

        $like_value = $wpdb->esc_like('_transient_' . self::TRANSIENT_PREFIX) . '%';
        $like_timeout = $wpdb->esc_like('_transient_timeout_' . self::TRANSIENT_PREFIX) . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like_value,
                $like_timeout
            )
        );
    }

    /**
     * @return array{limit: int, window: int}
     */
    private function config_for(string $bucket_key): array
    {
        $defaults = apply_filters('pfa_rate_limit_defaults', self::DEFAULTS);
        if (!is_array($defaults) || !isset($defaults[$bucket_key]) || !is_array($defaults[$bucket_key])) {
            return self::DEFAULTS[$bucket_key] ?? ['limit' => 0, 'window' => 0];
        }

        return [
            'limit' => (int) ($defaults[$bucket_key]['limit'] ?? 0),
            'window' => (int) ($defaults[$bucket_key]['window'] ?? 0),
        ];
    }
}

<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

/**
 * Retry an upstream LLM call with exponential backoff that respects the
 * `retry-after` response header. Wraps the transport call inside
 * LlmGateway so transient 429 / 529 / 5xx errors don't surface as
 * user-visible failures when waiting 1-2 seconds would have worked.
 *
 * 4xx errors other than 429 are NOT retried — those are caller bugs and
 * will keep failing.
 *
 * Wire in LlmGateway::http_post() (or wherever the transport call lives):
 *
 *   return ProviderBackoff::call(fn () => $this->transport->post(...));
 *
 * See docs/AGENT_RETROFIT_GUIDE.md §3.
 */
final class ProviderBackoff
{
    public const DEFAULT_MAX_ATTEMPTS = 5;
    public const DEFAULT_BASE_DELAY_MS = 500;
    public const MAX_DELAY_MS = 30_000;

    /**
     * @param callable():array{status:int,headers?:array<string,string>,body?:string} $call
     *                Returning shape that LlmGateway already uses. The
     *                function MUST return the response array (not throw)
     *                even on 4xx/5xx so we can inspect status here.
     * @param int     $maxAttempts  Total attempts including the first one.
     * @return array<string, mixed>  The successful (or final) response.
     */
    public static function call(callable $call, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS): array
    {
        $delayMs = self::DEFAULT_BASE_DELAY_MS;
        $last = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $call();
            $last = $response;
            $status = (int) ($response['status'] ?? 0);

            if (!self::is_retryable($status)) {
                return $response;
            }

            if ($attempt === $maxAttempts) {
                break;
            }

            $waitMs = self::compute_wait_ms($response, $delayMs);
            usleep($waitMs * 1000);
            $delayMs = min($delayMs * 2, self::MAX_DELAY_MS);
        }

        return $last ?? ['status' => 0, 'body' => '', 'headers' => []];
    }

    public static function is_retryable(int $status): bool
    {
        if ($status === 429 || $status === 529) {
            return true;
        }
        if ($status >= 500 && $status < 600) {
            return true;
        }
        return false;
    }

    /**
     * Convenience wrapper for callers using the WordPress HTTP API:
     * pass a closure that calls wp_remote_post / wp_remote_get and we
     * retry on 429 / 529 / 5xx honouring `retry-after`. Non-retryable
     * 4xx, network errors and 2xx responses return immediately.
     *
     * @param callable():(array|WP_Error) $call
     * @return array|WP_Error  Same shape the wp_remote_* call returned.
     */
    public static function call_wp_remote(callable $call, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS)
    {
        $delayMs = self::DEFAULT_BASE_DELAY_MS;
        $last = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $call();
            $last = $response;

            if (is_wp_error($response)) {
                // Transport-level failure (DNS, TCP). Retry with backoff.
                if ($attempt === $maxAttempts) {
                    break;
                }
                usleep($delayMs * 1000);
                $delayMs = min($delayMs * 2, self::MAX_DELAY_MS);
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if (!self::is_retryable($status)) {
                return $response;
            }

            if ($attempt === $maxAttempts) {
                break;
            }

            $retryAfter = (string) wp_remote_retrieve_header($response, 'retry-after');
            $waitMs = $retryAfter !== '' && is_numeric($retryAfter)
                ? ((int) ceil((float) $retryAfter)) * 1000
                : $delayMs;
            $waitMs = max($waitMs, $delayMs);
            usleep($waitMs * 1000);
            $delayMs = min($delayMs * 2, self::MAX_DELAY_MS);
        }

        return $last;
    }

    /**
     * Honor the `retry-after` header (Anthropic, OpenAI both use it).
     * Value is seconds (RFC 9110 §10.2.3 also allows HTTP-date but no
     * provider returns that shape here). Falls back to the rolling
     * exponential backoff floor.
     *
     * @param array<string, mixed> $response
     */
    public static function compute_wait_ms(array $response, int $floorMs): int
    {
        $headers = (array) ($response['headers'] ?? []);
        $retryAfter = self::find_header($headers, 'retry-after');
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            $waitMs = ((int) ceil((float) $retryAfter)) * 1000;
            return max($waitMs, $floorMs);
        }
        return $floorMs;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private static function find_header(array $headers, string $name): ?string
    {
        $needle = strtolower($name);
        foreach ($headers as $k => $v) {
            if (is_string($k) && strtolower($k) === $needle) {
                return is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
            }
        }
        return null;
    }
}

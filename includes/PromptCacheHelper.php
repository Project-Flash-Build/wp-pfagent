<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

/**
 * Inject Anthropic ephemeral cache_control breakpoints into an outgoing
 * Messages API request body so the stable prefix (tools + system + the
 * last stable assistant turn) is read at 0.10x base price on every turn
 * after the first. See docs/AGENT_RETROFIT_GUIDE.md §1.
 *
 * Usage in LlmGateway, right before the JSON encode:
 *
 *   $body = PromptCacheHelper::inject($body, $model);
 *
 * The helper is a no-op on bodies that look like OpenAI or Gemini
 * (those families either auto-cache or do not expose cache_control in
 * this shape), and on bodies whose cumulative input is below the
 * per-model minimum that Anthropic actually caches.
 */
final class PromptCacheHelper
{
    public const MAX_BREAKPOINTS = 4;

    /**
     * Per-model minimum input-token threshold below which Anthropic
     * silently refuses to cache. Below the floor we skip the helper
     * entirely so we don't pay the 1.25x write surcharge for nothing.
     *
     * Source: https://docs.claude.com/en/docs/build-with-claude/prompt-caching
     */
    private const MIN_TOKENS = [
        'claude-opus-4'        => 4096,
        'claude-opus-4-5'      => 4096,
        'claude-opus-4-6'      => 4096,
        'claude-opus-4-7'      => 4096,
        'claude-sonnet-3-7'    => 1024,
        'claude-sonnet-4'      => 1024,
        'claude-sonnet-4-5'    => 1024,
        'claude-sonnet-4-6'    => 2048,
        'claude-haiku-3-5'     => 2048,
        'claude-haiku-4-5'     => 4096,
    ];

    private const DEFAULT_MIN_TOKENS = 1024;

    /**
     * @param array<string, mixed> $body  Anthropic-shaped request body.
     * @return array<string, mixed>       Body with cache_control breakpoints,
     *                                    or the original body unchanged when
     *                                    caching wouldn't apply.
     */
    public static function inject(array $body, string $model): array
    {
        if (!self::looks_like_anthropic($body)) {
            return $body;
        }

        $approx_tokens = self::estimate_input_tokens($body);
        $minimum = self::min_tokens_for($model);
        if ($approx_tokens < $minimum) {
            return $body;
        }

        $breakpoints = 0;

        // 1. Mark the last tool definition.
        if (isset($body['tools']) && is_array($body['tools']) && $body['tools'] !== []) {
            $last = array_key_last($body['tools']);
            if (is_array($body['tools'][$last])) {
                $body['tools'][$last]['cache_control'] = ['type' => 'ephemeral'];
                $breakpoints++;
            }
        }

        // 2. Normalise the system block. Anthropic accepts `system` either
        //    as a plain string or an array of TextBlock objects, but
        //    cache_control can only be attached to a TextBlock. Convert
        //    string-form to array-form so we can carry the breakpoint.
        if (isset($body['system']) && is_string($body['system']) && $body['system'] !== '') {
            $body['system'] = [[
                'type' => 'text',
                'text' => $body['system'],
            ]];
        }

        // 3. Mark the last system block.
        if ($breakpoints < self::MAX_BREAKPOINTS && isset($body['system']) && is_array($body['system']) && $body['system'] !== []) {
            $last = array_key_last($body['system']);
            if (is_array($body['system'][$last])) {
                $body['system'][$last]['cache_control'] = ['type' => 'ephemeral'];
                $breakpoints++;
            }
        }

        // 3. Mark the last assistant message in the conversation. This
        //    moves the cache breakpoint forward each turn so the entire
        //    history-up-to-here gets cached together.
        if ($breakpoints < self::MAX_BREAKPOINTS && isset($body['messages']) && is_array($body['messages'])) {
            $last_assistant_idx = null;
            foreach ($body['messages'] as $idx => $msg) {
                if (is_array($msg) && ($msg['role'] ?? '') === 'assistant') {
                    $last_assistant_idx = $idx;
                }
            }
            if ($last_assistant_idx !== null) {
                $body['messages'][$last_assistant_idx] = self::add_breakpoint_to_message(
                    $body['messages'][$last_assistant_idx]
                );
                $breakpoints++;
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private static function add_breakpoint_to_message(array $message): array
    {
        $content = $message['content'] ?? null;

        // String content → wrap into a single text block carrying the breakpoint.
        if (is_string($content)) {
            $message['content'] = [[
                'type' => 'text',
                'text' => $content,
                'cache_control' => ['type' => 'ephemeral'],
            ]];
            return $message;
        }

        // Array content → mark the last block.
        if (is_array($content) && $content !== []) {
            $last = array_key_last($content);
            if (is_array($content[$last])) {
                $content[$last]['cache_control'] = ['type' => 'ephemeral'];
                $message['content'] = $content;
            }
        }

        return $message;
    }

    /**
     * Detect Anthropic-family request shape: array body with `messages`
     * (Anthropic Messages API) and either `system` or `tools` we can mark.
     * OpenAI uses `messages[].role === 'system'` (no top-level system) so
     * an absent top-level system is a strong hint we're not on Anthropic;
     * but the presence of `system` plus `messages` is unambiguous.
     */
    private static function looks_like_anthropic(array $body): bool
    {
        if (!isset($body['messages']) || !is_array($body['messages'])) {
            return false;
        }
        if (isset($body['system'])) {
            return true;
        }
        // Heuristic: if tools are an array of {name, input_schema} objects,
        // it's the Anthropic shape (OpenAI wraps in {type:'function', function:{...}}).
        if (isset($body['tools']) && is_array($body['tools']) && $body['tools'] !== []) {
            $first = $body['tools'][0] ?? null;
            if (is_array($first) && isset($first['name']) && (isset($first['input_schema']) || isset($first['parameters']))) {
                return !isset($first['type']) || $first['type'] !== 'function';
            }
        }
        return false;
    }

    /**
     * Cheap byte-based approximation of input tokens. Good enough for the
     * "should we even bother caching" gate; we don't need the real tokenizer
     * here. ~4 chars per token is the conventional rough constant.
     *
     * @param array<string, mixed> $body
     */
    private static function estimate_input_tokens(array $body): int
    {
        $payload_bytes = strlen((string) wp_json_encode($body));
        return (int) ceil($payload_bytes / 4);
    }

    private static function min_tokens_for(string $model): int
    {
        $model = strtolower($model);
        // Strip provider prefix (e.g. "anthropic/claude-opus-4-7" → "claude-opus-4-7").
        if (str_contains($model, '/')) {
            $model = substr($model, (int) strrpos($model, '/') + 1);
        }
        // Try the longest-prefix match so versioned IDs like
        // "claude-opus-4-7-20260301" still resolve to the right minimum.
        foreach (self::MIN_TOKENS as $prefix => $min) {
            if (str_starts_with($model, $prefix)) {
                return $min;
            }
        }
        return self::DEFAULT_MIN_TOKENS;
    }
}

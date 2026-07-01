<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Llm;

/**
 * Inject provider-specific ephemeral cache markers into an outgoing LLM
 * request body so the stable prefix (tools + system + last assistant turn,
 * or last few non-system messages depending on family) is billed at the
 * cache-hit rate on every turn after the first.
 *
 * Two strategies are implemented today:
 *
 *  - injectIfWorthIt() — Anthropic message-level `cache_control` on the
 *    last tool, last system block, and last assistant message. Max
 *    4 breakpoints per request (Anthropic's limit). Used by
 *    AnthropicGateway.
 *
 *  - injectOpenAiCompat() — content-level `cache_control: {type:"ephemeral"}`
 *    on the first 1-2 system content parts and the last 2 non-system
 *    messages. Used by OpenAiCompatibleGateway for providers whose
 *    catalog entry declares a cache feature flag (DeepSeek auto-caches
 *    regardless of markers; Qwen / OpenRouter / Together / Fireworks
 *    honour the markers when present).
 *
 * No-op for both when the estimated input is below the per-model cache
 * floor (silent refusal would cost the 1.25× write surcharge for nothing).
 *
 * Future strategies (OpenRouter camelCase `cacheControl`, Bedrock
 * `cachePoint`, Copilot `copilot_cache_control`, Alibaba native
 * `cacheControl`) follow the same shape and can land as sibling methods
 * — see Kilo Code provider/transform.ts:335-384 (Tier 1.10).
 */
final class PromptCacheInjector
{
    public const MAX_BREAKPOINTS = 4;

    /**
     * @param array<string, mixed> $body Anthropic-shaped body
     */
    public static function injectIfWorthIt(array $body, string $model, int $minTokens): array
    {
        if ($minTokens <= 0) {
            // Model has no declared floor — skip rather than risk a paid
            // miss. If you want to force-cache, set minCacheTokens=1 in
            // the catalog.
            return $body;
        }

        if (!self::looksLikeAnthropic($body)) {
            return $body;
        }

        $approxTokens = self::estimateInputTokens($body);
        if ($approxTokens < $minTokens) {
            return $body;
        }

        $breakpoints = 0;

        // 1. Last tool definition.
        if (isset($body['tools']) && is_array($body['tools']) && $body['tools'] !== []) {
            $last = array_key_last($body['tools']);
            if (is_array($body['tools'][$last])) {
                $body['tools'][$last]['cache_control'] = ['type' => 'ephemeral'];
                $breakpoints++;
            }
        }

        // 2. Normalise + mark the last system block.
        if (isset($body['system']) && is_string($body['system']) && $body['system'] !== '') {
            $body['system'] = [[
                'type' => 'text',
                'text' => $body['system'],
            ]];
        }
        if ($breakpoints < self::MAX_BREAKPOINTS && isset($body['system']) && is_array($body['system']) && $body['system'] !== []) {
            $last = array_key_last($body['system']);
            if (is_array($body['system'][$last])) {
                $body['system'][$last]['cache_control'] = ['type' => 'ephemeral'];
                $breakpoints++;
            }
        }

        // 3. Last assistant message in the conversation.
        if ($breakpoints < self::MAX_BREAKPOINTS && isset($body['messages']) && is_array($body['messages'])) {
            $lastAssistantIdx = null;
            foreach ($body['messages'] as $idx => $msg) {
                if (is_array($msg) && ($msg['role'] ?? '') === 'assistant') {
                    $lastAssistantIdx = $idx;
                }
            }
            if ($lastAssistantIdx !== null) {
                $body['messages'][$lastAssistantIdx] = self::addBreakpointToMessage(
                    $body['messages'][$lastAssistantIdx]
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
    private static function addBreakpointToMessage(array $message): array
    {
        $content = $message['content'] ?? null;

        if (is_string($content)) {
            $message['content'] = [[
                'type' => 'text',
                'text' => $content,
                'cache_control' => ['type' => 'ephemeral'],
            ]];
            return $message;
        }

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
     * @param array<string, mixed> $body
     */
    private static function looksLikeAnthropic(array $body): bool
    {
        if (!isset($body['messages']) || !is_array($body['messages'])) {
            return false;
        }
        if (isset($body['system'])) {
            return true;
        }
        if (isset($body['tools']) && is_array($body['tools']) && $body['tools'] !== []) {
            $first = $body['tools'][0] ?? null;
            if (is_array($first) && isset($first['name'])
                && (isset($first['input_schema']) || isset($first['parameters']))
                && (!isset($first['type']) || $first['type'] !== 'function')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cheap byte-based approximation. ~4 chars/token is the conventional
     * rough constant. Good enough for the "is it worth caching" gate; we
     * don't need the real tokenizer here.
     *
     * @param array<string, mixed> $body
     */
    private static function estimateInputTokens(array $body): int
    {
        $bytes = strlen((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return (int) ceil($bytes / 4);
    }

    /**
     * Inject OpenAI-compatible content-level cache markers
     * (`cache_control: {type: "ephemeral"}`). Strategy: mark up to 2 of
     * the most stable content parts (last system content + last non-system
     * message). Providers that honour the marker (DeepSeek thinking models,
     * Qwen DashScope compat, Together, Fireworks, OpenRouter passthrough)
     * will cache; providers that ignore unknown fields (vanilla OpenAI)
     * simply drop it on the floor.
     *
     * Caller (OpenAiCompatibleGateway) is responsible for the
     * model-feature gate so we don't pollute requests for providers
     * known to reject extra fields.
     *
     * @param array<string, mixed> $body OpenAI-compatible chat completions body
     * @return array<string, mixed>
     */
    public static function injectOpenAiCompat(array $body, int $minTokens = 0): array
    {
        if (!isset($body['messages']) || !is_array($body['messages']) || $body['messages'] === []) {
            return $body;
        }
        if ($minTokens > 0 && self::estimateInputTokens($body) < $minTokens) {
            return $body;
        }

        $marker = ['type' => 'ephemeral'];
        $applied = 0;

        // 1. Last system message — most stable prefix, big win when system
        //    prompt is long (tool catalog, framework discipline, etc.).
        $lastSystemIdx = null;
        foreach ($body['messages'] as $idx => $msg) {
            if (is_array($msg) && ($msg['role'] ?? '') === 'system') {
                $lastSystemIdx = $idx;
            }
        }
        if ($lastSystemIdx !== null) {
            $body['messages'][$lastSystemIdx] = self::addContentLevelMarker(
                $body['messages'][$lastSystemIdx],
                $marker,
            );
            $applied++;
        }

        // 2. Last non-system message — picks up the most recent assistant or
        //    tool result, cached so the NEXT turn can reuse it.
        $lastNonSystemIdx = null;
        foreach ($body['messages'] as $idx => $msg) {
            if (is_array($msg) && ($msg['role'] ?? '') !== 'system') {
                $lastNonSystemIdx = $idx;
            }
        }
        if ($lastNonSystemIdx !== null && $lastNonSystemIdx !== $lastSystemIdx && $applied < self::MAX_BREAKPOINTS) {
            $body['messages'][$lastNonSystemIdx] = self::addContentLevelMarker(
                $body['messages'][$lastNonSystemIdx],
                $marker,
            );
        }

        return $body;
    }

    /**
     * Promote a `string` content to a single-element content array and tag
     * the (now sole) content part with the supplied marker. If content is
     * already an array, tag the last part. Other shapes are left alone.
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed> $marker
     * @return array<string, mixed>
     */
    private static function addContentLevelMarker(array $message, array $marker): array
    {
        $content = $message['content'] ?? null;

        if (is_string($content) && $content !== '') {
            $message['content'] = [[
                'type' => 'text',
                'text' => $content,
                'cache_control' => $marker,
            ]];
            return $message;
        }

        if (is_array($content) && $content !== []) {
            $last = array_key_last($content);
            if (is_array($content[$last])) {
                $content[$last]['cache_control'] = $marker;
                $message['content'] = $content;
            }
        }

        return $message;
    }
}

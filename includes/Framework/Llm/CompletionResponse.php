<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Llm;

/**
 * Output envelope for Gateway::complete.
 *
 * `finishReason` ∈ stop | length | tool_calls | content_filter | refusal | unknown
 *
 * When the gateway auto-continued past a `length` truncation, `continued=true`
 * tells the loop how to log the round.
 *
 * `systemFingerprint` is the provider-side backend version tag. When it
 * changes between rounds the seed no longer guarantees determinism — the
 * Loop logs it on every round so we can spot rotations after the fact.
 *
 * `logprobs` carries the OpenAI-shaped logprobs payload when
 * CompletionRequest::logprobs was set. The shape is provider-defined; we
 * pass it through as a raw array so the Loop / trace can do post-hoc
 * analysis without us inventing a schema for something only used in
 * diagnostics.
 */
final class CompletionResponse
{
    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}> $toolCalls
     * @param array{promptTokens?: int, completionTokens?: int, totalTokens?: int, cacheHitTokens?: int, cacheMissTokens?: int} $usage
     *        DeepSeek (and OpenRouter-fronted providers) extend the usage
     *        block with `prompt_cache_hit_tokens` / `prompt_cache_miss_tokens`
     *        — billed at a fraction of the normal input rate. We expose
     *        them as `cacheHitTokens` / `cacheMissTokens` so the host can
     *        chart cache efficiency without having to know each provider's
     *        wire field name. Zero/absent when the provider doesn't emit
     *        them.
     * @param array<string, mixed>|null $logprobs
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly string $finishReason,
        public readonly string $reasoning = '',
        public readonly array $usage = [],
        public readonly int $costMicros = 0,
        public readonly bool $continued = false,
        public readonly string $rawModel = '',
        public readonly string $systemFingerprint = '',
        public readonly ?array $logprobs = null,
        /**
         * True when the catalog could not price this round's model (the model
         * id, after longest-prefix match, is absent from the catalog). In that
         * case `costMicros` is a SILENT 0 that the dashboards would otherwise
         * read as "free round" rather than "uncounted round". The Loop turns
         * this flag into a `cost_unknown` trace so the undercount is visible.
         * Charging behaviour is unchanged — cost is still banked as 0.
         */
        public readonly bool $costUnknown = false,
    ) {
    }
}

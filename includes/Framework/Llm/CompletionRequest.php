<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Llm;

use ProjectFlash\Agent\Framework\Message;

/**
 * Input envelope for Gateway::complete. Carries only what the LLM call needs;
 * no loop state. The Loop builds one of these per round.
 *
 * Every behavioural lever the OpenAI-compatible API exposes is a property
 * here. The gateway is the one that decides how to wire each into the body
 * (and whether a given provider supports it). Null / empty values are
 * omitted from the wire so we don't accidentally pin an OpenAI default on
 * providers that interpret it differently.
 */
final class CompletionRequest
{
    /**
     * @param list<Message> $messages
     * @param list<array<string, mixed>> $tools LLM wire shape (OpenAI-compatible function tools)
     * @param string|array{type: string, function?: array{name: string}} $toolChoice
     *        'auto' | 'none' | 'required' | {type:'function', function:{name:...}}
     * @param array<int, int> $logitBias map of integer token ID → bias (-100..100)
     * @param list<string> $stop stop sequences (max ~4 across providers)
     * @param array<string, mixed>|null $responseFormat structured-outputs envelope (response_format wire shape)
     * @param array<string, mixed> $extraBody arbitrary additional fields the host
     *        wants the gateway to merge into the request body. Used for
     *        provider-specific quirks the framework deliberately doesn't model
     *        (e.g. DeepSeek `thinking: {type:"enabled"}`, Anthropic
     *        `anthropic-beta` markers, OpenRouter `provider` preferences).
     *        Wins over framework-managed fields on key collision — the host
     *        knows the provider better than we do.
     */
    public function __construct(
        public readonly string $model,
        public readonly array $messages,
        public readonly array $tools = [],
        public readonly int $maxOutputTokens = 0,
        /**
         * Nullable: when null, the gateway falls back to the per-model
         * defaults the wizard saved on the credential (Kilo Tier 1.9). When
         * that's also absent, the field is omitted from the wire so the
         * provider's own default wins (Anthropic 1.0, OpenAI 1.0, Gemini 1.0,
         * etc.). Hosts that genuinely need 0.2 must pass it explicitly.
         */
        public readonly ?float $temperature = null,
        public readonly ?float $topP = null,
        /**
         * Gemini-only knob; OpenAI-compatible providers ignore it on the
         * wire. Nullable for the same per-model-defaults rationale as
         * temperature.
         */
        public readonly ?int $topK = null,
        public readonly ?float $presencePenalty = null,
        public readonly ?float $frequencyPenalty = null,
        public readonly string|array $toolChoice = 'auto',
        public readonly ?bool $parallelToolCalls = null,
        public readonly ?int $seed = null,
        public readonly bool $logprobs = false,
        public readonly ?int $topLogprobs = null,
        public readonly array $logitBias = [],
        public readonly array $stop = [],
        public readonly ?array $responseFormat = null,
        public readonly ?string $reasoningEffort = null,
        public readonly array $extraBody = [],
    ) {
    }
}

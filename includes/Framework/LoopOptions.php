<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * Tunables for Loop::run / Loop::resume. All defaults are conservative;
 * tighten or loosen per workload.
 *
 * Behaviour-control levers map 1:1 to OpenAI-compatible API parameters
 * (see docs/api_levers_plan.md). Defaults are picked for an agentic loop
 * driving a customer-facing reply, not for raw chat — i.e. modest
 * `presencePenalty` + `frequencyPenalty` to fight RLHF verbosity, plus
 * structured-final-reply on so the terminal text round goes through
 * grammar-constrained decoding.
 */
final class LoopOptions
{
    /**
     * @param array<int, int> $logitBias map of integer token ID → bias (-100..100).
     *        Token IDs are tokenizer-specific (cl100k ≠ DeepSeek ≠ Anthropic);
     *        the framework can extend this map at runtime via
     *        $forbiddenTokenStrings + the discovered-bias persistence in
     *        conversation metadata.
     * @param list<string> $forbiddenTokenStrings strings (e.g. "?", "¿") the
     *        Loop should attempt to learn the token IDs for via the response
     *        logprobs and add to `logitBias`. Requires `$logprobs = true`.
     * @param list<string> $stopSequences applied only to text-final rounds
     *        (no tools). Truncating mid-tool-call would corrupt the wire,
     *        so the Loop strips these when emitting a tool-bearing request.
     */
    public function __construct(
        public readonly int $maxTurns = 32,
        public readonly int $maxFingerprintRepeats = 3,
        public readonly int $maxRewrites = 3,
        public readonly int $maxBudgetMicros = 0,
        /**
         * How many tool calls the loop will honour per LLM response. The
         * discipline prompt tells the model to cap itself; this is the
         * hard runtime ceiling. Extras in the same response are silently
         * dropped — the model sees only the executed tool_result messages
         * and decides what's left to do.
         */
        public readonly int $maxToolCallsPerResponse = 3,
        public readonly ?ApprovalStore $approvalStore = null,
        /** Override the framework discipline preamble. Pass empty string to disable. */
        public readonly ?string $frameworkDiscipline = null,

        // ── Sampling controls ──────────────────────────────────────────
        /**
         * Nullable: null = let per-model defaults (saved on the credential
         * via the wizard) decide; gateway falls back to provider default
         * when no per-model entry exists. Hosts pinning a value (e.g. 0.0
         * for deterministic test runs) must pass it explicitly. Same
         * semantics for topP and topK below. (Kilo Tier 1.9.)
         */
        public readonly ?float $temperature = null,
        public readonly ?float $topP = null,
        public readonly ?int $topK = null,
        // Penalties tightened from 0.3 / 0.2 after probe round 3: the LLM
        // kept finding new permission phrasings ("Si necesitas...", "Dame
        // una instrucción y procedo") that the OutputFilter caught only
        // post-hoc, costing a rewrite per turn. Higher penalties make
        // those tokens slightly more costly, so the model picks
        // alternatives earlier in the decoding without us having to ban
        // them outright.
        public readonly ?float $presencePenalty = 0.5,
        public readonly ?float $frequencyPenalty = 0.3,
        public readonly ?int $seed = null,

        // ── Diagnostics ────────────────────────────────────────────────
        public readonly bool $logprobs = false,
        public readonly ?int $topLogprobs = null,

        // ── Token-level suppression ────────────────────────────────────
        public readonly array $logitBias = [],
        public readonly array $forbiddenTokenStrings = [],
        public readonly array $stopSequences = [],

        // ── Tool-call shape ────────────────────────────────────────────
        /** 'auto' (default) | 'none' | 'required' | ['type'=>'function', 'function'=>['name'=>...]] */
        public readonly string|array $toolChoice = 'auto',
        public readonly ?bool $parallelToolCalls = null,

        // ── Reasoning models ───────────────────────────────────────────
        public readonly ?string $reasoningEffort = null,

        // ── Structured outputs ─────────────────────────────────────────
        /**
         * When true, the Loop wraps any tools=[] round (currently: the
         * forced-final at maxTurns and any future explicit terminal round)
         * with response_format=json_schema strict, requiring the LLM to
         * emit {"text": "..."} — grammar-enforced anti-permission-asking.
         * The Loop parses the JSON and surfaces only the inner text.
         */
        public readonly bool $useStructuredFinalReply = true,

        // ── Provider quirks ────────────────────────────────────────────
        /**
         * Arbitrary additional fields merged into every CompletionRequest's
         * wire body. The framework deliberately doesn't model
         * provider-specific knobs (DeepSeek `thinking`, Anthropic
         * `anthropic-beta`, OpenRouter `provider`, etc.) — hosts plug those
         * in here. Wins over framework-managed fields on key collision.
         *
         * @var array<string, mixed>
         */
        public readonly array $extraBody = [],
    ) {
    }
}

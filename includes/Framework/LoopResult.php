<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * The terminal value of one Loop::run() call.
 *
 *   subtype = success | needs_confirmation | error_max_turns | error_max_budget |
 *             error_fingerprint_loop | error_llm | error_tool | refusal
 *
 * On `needs_confirmation`, `pendingToolCall` is set and the caller is expected
 * to either confirm (call Loop::resume) or deny (call Loop::abort).
 */
final class LoopResult
{
    public const SUBTYPE_SUCCESS = 'success';
    public const SUBTYPE_NEEDS_CONFIRMATION = 'needs_confirmation';
    public const SUBTYPE_ERROR_MAX_TURNS = 'error_max_turns';
    public const SUBTYPE_ERROR_MAX_BUDGET = 'error_max_budget';
    public const SUBTYPE_ERROR_FINGERPRINT_LOOP = 'error_fingerprint_loop';
    public const SUBTYPE_ERROR_LLM = 'error_llm';
    public const SUBTYPE_REFUSAL = 'refusal';

    /**
     * @param array{toolCallId: string, name: string, arguments: array<string, mixed>}|null $pendingToolCall
     * @param array<string, mixed> $usage
     */
    public function __construct(
        public readonly string $subtype,
        public readonly int $conversationId,
        public readonly string $finalText,
        public readonly int $rounds,
        public readonly array $usage,
        public readonly int $costMicros = 0,
        public readonly ?array $pendingToolCall = null,
        public readonly string $confirmationToken = '',
        public readonly string $errorMessage = '',
    ) {
    }
}

<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * Host-side decision maker for side-effect tools. Three terminal verdicts:
 *
 *   DECISION_ALLOW   → execute the tool now, no human in the loop.
 *   DECISION_DENY    → reject; the loop surfaces the rejection to the model
 *                       so it can pick another path.
 *   DECISION_PENDING → defer to a human; loop pauses, returns
 *                       needs_confirmation with a token, caller persists
 *                       its UI state and later invokes Loop::resume.
 *
 * Implementations:
 *   - AutoApproveGate (tests): always returns ALLOW.
 *   - AutoDenyGate (tests): always returns DENY.
 *   - WordPress\HumanModalGate (runtime): returns PENDING and persists the
 *     pending call so a wp-admin modal can pick it up.
 */
interface ApprovalGate
{
    public const DECISION_ALLOW = 'allow';
    public const DECISION_DENY = 'deny';
    public const DECISION_PENDING = 'pending';

    /** @param array<string, mixed> $arguments */
    public function request(string $toolName, array $arguments, Conversation $conversation): string;
}

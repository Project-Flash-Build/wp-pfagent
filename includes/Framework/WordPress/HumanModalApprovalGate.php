<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\WordPress;

use ProjectFlash\Agent\Framework\ApprovalGate;
use ProjectFlash\Agent\Framework\Conversation;
use ProjectFlash\Agent\Framework\PermissionRuleset;

/**
 * Production approval gate for wp-pfagent. Consults the operator-configured
 * PermissionRuleset first (Kilo Tier 2.2); only falls through to PENDING
 * when no rule resolves the call. The user resolves PENDING via
 * Loop::resume(token, approved=true|false).
 *
 * Verdict mapping:
 *   - PermissionRuleset::VERDICT_ALLOW → DECISION_ALLOW (tool runs silently)
 *   - PermissionRuleset::VERDICT_DENY  → DECISION_DENY  (refusal to the model)
 *   - PermissionRuleset::VERDICT_ASK   → DECISION_PENDING (human modal)
 *
 * The PENDING token is stored by the Loop in the configured ApprovalStore
 * (TransientApprovalStore in production). The REST layer reads it back to
 * route the user's verdict to the right paused call.
 */
final class HumanModalApprovalGate implements ApprovalGate
{
    public function __construct(private readonly PermissionRuleset $ruleset = new PermissionRuleset())
    {
    }

    public function request(string $toolName, array $arguments, Conversation $conversation): string
    {
        $verdict = $this->ruleset->evaluate($toolName, $arguments);
        return match ($verdict) {
            PermissionRuleset::VERDICT_ALLOW => self::DECISION_ALLOW,
            PermissionRuleset::VERDICT_DENY => self::DECISION_DENY,
            default => self::DECISION_PENDING,
        };
    }
}

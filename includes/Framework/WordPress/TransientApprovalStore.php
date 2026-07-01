<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\WordPress;

use ProjectFlash\Agent\Framework\ApprovalStore;

/**
 * WordPress-native ApprovalStore using transients (24 h TTL). Pending
 * side-effect tool calls survive across HTTP requests until the user resolves
 * the modal or the transient expires.
 *
 * Tokens are 32-char hex. Transient keys are prefixed `pfa_approval_` so they
 * can be inspected / cleaned independently from other plugin state.
 */
final class TransientApprovalStore implements ApprovalStore
{
    private const KEY_PREFIX = 'pfa_approval_';
    private const TTL_SECONDS = 86400; // 24h

    public function savePending(array $payload): string
    {
        $token = bin2hex(random_bytes(16));
        set_transient(self::KEY_PREFIX . $token, $payload, self::TTL_SECONDS);
        return $token;
    }

    public function loadPending(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $data = get_transient(self::KEY_PREFIX . $token);
        return is_array($data) ? $data : null;
    }

    public function resolve(string $token): void
    {
        if ($token === '') {
            return;
        }
        delete_transient(self::KEY_PREFIX . $token);
    }
}

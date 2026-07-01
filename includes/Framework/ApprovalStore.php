<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * Side-effect tool calls that need human approval are held here between
 * the original turn (returns needs_confirmation) and the user's verdict
 * (Loop::resume).
 *
 * v0 in-memory implementation lives in tests/. WordPress uses transients.
 */
interface ApprovalStore
{
    /** @param array<string, mixed> $payload @return string opaque token */
    public function savePending(array $payload): string;

    /** @return array<string, mixed>|null */
    public function loadPending(string $token): ?array;

    public function resolve(string $token): void;
}

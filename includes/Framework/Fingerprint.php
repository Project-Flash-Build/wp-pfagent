<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * Loop-oscillation detector. Hashes (tool_name + canonical_args) so the loop
 * can detect when the agent is calling the same tool with the same arguments
 * in a row (the "Ralph Wiggum drift" Steve Kinney describes — agent keeps
 * busy producing the same nothing).
 *
 * Used by Loop:
 *   - Compute fingerprint of every pending tool call.
 *   - Persist it in pfaf_tool_calls.
 *   - On each call, ask Store::countFingerprint(); if ≥ threshold consecutive
 *     within the same turn, break the loop with error_fingerprint_loop.
 */
final class Fingerprint
{
    /** @param array<string, mixed> $arguments */
    public static function of(string $toolName, array $arguments): string
    {
        $canonical = self::canonicalise($arguments);
        return substr(hash('sha256', $toolName . '|' . $canonical), 0, 32);
    }

    /** @param mixed $value */
    private static function canonicalise(mixed $value): string
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            if ($isList) {
                $parts = array_map(self::canonicalise(...), $value);
                return '[' . implode(',', $parts) . ']';
            }
            ksort($value);
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = json_encode((string) $k, JSON_UNESCAPED_UNICODE) . ':' . self::canonicalise($v);
            }
            return '{' . implode(',', $parts) . '}';
        }
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

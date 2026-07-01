<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * Tool-execution policy. Lets the host decide which side-effect tool
 * calls go through silently, which get denied outright, and which still
 * pause for a human.
 *
 * Three verdicts mirror ApprovalGate's:
 *   - 'allow' → tool runs without surfacing a modal.
 *   - 'deny'  → tool refuses, the model sees the refusal and picks a
 *               different path.
 *   - 'ask'   → defer to the existing human-modal flow (PENDING).
 *
 * The ruleset is a nested map keyed by tool name, with a magical `"*"`
 * key meaning "this tool / default for all tools". For each tool entry
 * the value is either:
 *
 *   - A bare verdict string ("allow" / "deny" / "ask")  → applies to
 *     every call of that tool regardless of arguments.
 *   - A map with a `default` verdict + `patterns` array, where each
 *     pattern matches against `<arg>=<glob>` (e.g. `kind=record`,
 *     `path=/tmp/*`). First matching pattern wins; if none match the
 *     `default` is used; if no default is set, the global `"*"` wins.
 *
 * Example:
 *
 *   [
 *     '*' => 'ask',                              // default: human-in-the-loop
 *     'pfm_get_contract' => 'allow',             // pure read, always fine
 *     'pfm_apply' => [
 *       'default' => 'ask',
 *       'patterns' => [
 *         ['match' => 'kind=record', 'verdict' => 'allow'],
 *         ['match' => 'kind=entity', 'verdict' => 'ask'],
 *       ],
 *     ],
 *   ]
 *
 * Kilo Code Tier 2.2. Hierarchical merge (defaults < built-ins < user
 * config) is the host's responsibility — see PermissionRulesetLoader in
 * the WP integration for how that's stitched together.
 */
final class PermissionRuleset
{
    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_DENY = 'deny';
    public const VERDICT_ASK = 'ask';

    /** @param array<string, mixed> $rules */
    public function __construct(private readonly array $rules = [])
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Decide what to do with a tool call. Returns one of VERDICT_*.
     * Defaults to 'ask' when the ruleset has nothing to say.
     *
     * @param array<string, mixed> $arguments
     */
    public function evaluate(string $toolName, array $arguments): string
    {
        $entry = $this->rules[$toolName] ?? null;

        if (is_string($entry) && self::isVerdict($entry)) {
            return $entry;
        }

        if (is_array($entry)) {
            $patterns = is_array($entry['patterns'] ?? null) ? $entry['patterns'] : [];
            foreach ($patterns as $pattern) {
                if (!is_array($pattern)) {
                    continue;
                }
                $match = (string) ($pattern['match'] ?? '');
                $verdict = (string) ($pattern['verdict'] ?? '');
                if ($match === '' || !self::isVerdict($verdict)) {
                    continue;
                }
                if (self::patternMatches($match, $arguments)) {
                    return $verdict;
                }
            }
            $default = (string) ($entry['default'] ?? '');
            if (self::isVerdict($default)) {
                return $default;
            }
        }

        $catchAll = $this->rules['*'] ?? null;
        if (is_string($catchAll) && self::isVerdict($catchAll)) {
            return $catchAll;
        }

        return self::VERDICT_ASK;
    }

    /**
     * `<arg>=<glob>` matchers. The arg lookup is shallow: dotted keys are
     * traversed (`tool_input.payload.kind=record`). Glob supports `*`
     * (any chars) and `?` (one char). Comparison is case-insensitive,
     * the arg value is coerced to string. A literal `=` in the glob is
     * not supported — use the wider key path instead.
     *
     * @param array<string, mixed> $arguments
     */
    private static function patternMatches(string $pattern, array $arguments): bool
    {
        $eq = strpos($pattern, '=');
        if ($eq === false) {
            return false;
        }
        $argPath = substr($pattern, 0, $eq);
        $glob = substr($pattern, $eq + 1);

        $value = self::dig($arguments, $argPath);
        if ($value === null) {
            return false;
        }

        return self::globMatches($glob, (string) $value);
    }

    /**
     * @param array<string, mixed> $haystack
     */
    private static function dig(array $haystack, string $path): mixed
    {
        $parts = explode('.', $path);
        $cursor = $haystack;
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return null;
            }
            $cursor = $cursor[$part];
        }
        return is_scalar($cursor) ? $cursor : null;
    }

    private static function globMatches(string $glob, string $value): bool
    {
        $regex = '/^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($glob, '/')) . '$/i';
        return (bool) preg_match($regex, $value);
    }

    private static function isVerdict(string $value): bool
    {
        return $value === self::VERDICT_ALLOW
            || $value === self::VERDICT_DENY
            || $value === self::VERDICT_ASK;
    }
}

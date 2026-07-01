<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * Tiny JSON Schema validator focused on the subset OpenAI-compatible
 * function calling uses (Draft 2020-12 features that matter for tool
 * input schemas).
 *
 * Supported keywords:
 *   - type (string | number | integer | boolean | object | array | null)
 *   - required (array of property names)
 *   - properties (per-property nested schema)
 *   - additionalProperties (bool — when false, rejects unknown keys)
 *   - enum (must be one of the listed values)
 *   - minLength / maxLength (strings)
 *   - minimum / maximum (numbers)
 *   - items (per-element schema for arrays)
 *
 * Out of scope: $ref / allOf / oneOf / pattern / format / const / dependencies.
 * Add as needed; tool schemas rarely use them.
 *
 * Return value of `validate()` is { ok: bool, errors: list<string> }. Each
 * error is a self-contained human-readable sentence the LLM can act on
 * ("filters must be an object, got null") — by design, no JSON Pointer
 * paths and no error codes, because the LLM needs to fix-forward and a
 * narrative directive works better than a structured error.
 */
final class JsonSchemaValidator
{
    /**
     * Fill in missing optional properties with their schema-declared
     * `default` value. The LLM sometimes omits middle-positional optional
     * args ("limit", "format", "filters"); without normalization those
     * arrive at the PHP bridge as nulls and typed parameters throw. By
     * defaulting from the schema we relieve every bridge from having to
     * write defensive null-coercions.
     *
     * Recurses into object-typed properties. Lists with item schemas are
     * NOT auto-normalized (we don't manufacture array items the LLM
     * didn't ask for).
     *
     * @param array<string, mixed> $schema
     * @param mixed $value
     * @return mixed
     */
    public static function normalize(array $schema, mixed $value): mixed
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        if (($schema['type'] ?? null) !== 'object' || !is_array($value) || $properties === []) {
            return $value;
        }
        foreach ($properties as $key => $propSchema) {
            if (!is_array($propSchema)) {
                continue;
            }
            if (!array_key_exists((string) $key, $value)) {
                if (array_key_exists('default', $propSchema)) {
                    $value[(string) $key] = $propSchema['default'];
                }
                continue;
            }
            // Recurse for nested object schemas.
            if (($propSchema['type'] ?? null) === 'object' && is_array($value[(string) $key])) {
                $value[(string) $key] = self::normalize($propSchema, $value[(string) $key]);
            }
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     * @param mixed $value
     * @return array{ok: bool, errors: list<string>}
     */
    public static function validate(array $schema, mixed $value, string $path = '$'): array
    {
        $errors = [];

        $expectedType = $schema['type'] ?? null;
        if ($expectedType !== null) {
            $actualType = self::typeOf($value);
            $types = is_array($expectedType) ? array_map('strval', $expectedType) : [(string) $expectedType];
            $matched = false;
            foreach ($types as $t) {
                if (self::typeMatches($t, $value)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $errors[] = sprintf(
                    '%s must be %s, got %s.',
                    self::label($path),
                    implode(' or ', $types),
                    $actualType,
                );
                return ['ok' => false, 'errors' => $errors];
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                $errors[] = sprintf(
                    "%s must be one of [%s], got %s.",
                    self::label($path),
                    implode(', ', array_map(
                        static fn($v) => is_scalar($v) ? var_export($v, true) : json_encode($v),
                        $schema['enum'],
                    )),
                    is_scalar($value) ? var_export($value, true) : json_encode($value),
                );
            }
        }

        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
                $errors[] = sprintf('%s must be at least %d characters.', self::label($path), (int) $schema['minLength']);
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                $errors[] = sprintf('%s must be at most %d characters.', self::label($path), (int) $schema['maxLength']);
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = sprintf('%s must be ≥ %s.', self::label($path), (string) $schema['minimum']);
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = sprintf('%s must be ≤ %s.', self::label($path), (string) $schema['maximum']);
            }
        }

        if (is_array($value) && self::looksLikeObject($value)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            foreach ($required as $req) {
                if (!array_key_exists((string) $req, $value)) {
                    $errors[] = sprintf("%s is missing required field '%s'.", self::label($path), (string) $req);
                }
            }
            $additionalAllowed = !isset($schema['additionalProperties']) || $schema['additionalProperties'] !== false;
            foreach ($value as $key => $sub) {
                $propSchema = $properties[(string) $key] ?? null;
                if (is_array($propSchema)) {
                    $nested = self::validate($propSchema, $sub, $path . '.' . $key);
                    if (!$nested['ok']) {
                        $errors = array_merge($errors, $nested['errors']);
                    }
                } elseif (!$additionalAllowed) {
                    $errors[] = sprintf("%s has unexpected field '%s'.", self::label($path), (string) $key);
                }
            }
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $i => $item) {
                $nested = self::validate($schema['items'], $item, $path . '[' . $i . ']');
                if (!$nested['ok']) {
                    $errors = array_merge($errors, $nested['errors']);
                }
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    private static function typeOf(mixed $value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return 'boolean';
        if (is_int($value)) return 'integer';
        if (is_float($value)) return 'number';
        if (is_string($value)) return 'string';
        if (is_array($value)) return array_is_list($value) ? 'array' : 'object';
        return get_debug_type($value);
    }

    private static function typeMatches(string $expected, mixed $value): bool
    {
        return match ($expected) {
            'string'  => is_string($value),
            'integer' => is_int($value),
            'number'  => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array'   => is_array($value) && (array_is_list($value) || $value === []),
            'object'  => is_array($value) && self::looksLikeObject($value),
            'null'    => $value === null,
            default   => false,
        };
    }

    private static function looksLikeObject(array $value): bool
    {
        return $value === [] || !array_is_list($value);
    }

    private static function label(string $path): string
    {
        // '$' is the root token; rename to the more LLM-friendly 'arguments'.
        if ($path === '$') return 'arguments';
        return 'arguments' . substr($path, 1);
    }
}

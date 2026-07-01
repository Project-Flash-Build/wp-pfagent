<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * The metadata + JSON Schema the LLM sees when deciding which tool to call.
 * Tied to a concrete Tools\Tool implementation via the registry.
 *
 * `sideEffect=true` means the tool changes external state. The loop pauses
 * for confirmation (via a callback the host provides) before execution.
 *
 * `idempotent=true` is a stronger claim: even on retry the side-effect is
 * safe (same args twice = same end state). The loop's idempotency guard
 * uses this to dedupe retries automatically without bothering the user.
 *
 * `strict=true` adds `strict: true` to the wire function definition. On
 * providers that honour it (OpenAI gpt-4o+, DeepSeek `/beta`, Anthropic
 * 4.5+ with the beta header), the model's tool-call arguments are grammar-
 * enforced against the schema — eliminating the "tool call as plain text"
 * class of leaks (DeepSeek's `<｜｜DSML｜｜...>` markers etc.). Requires
 * the schema to satisfy strict-mode constraints: `additionalProperties:
 * false`, every property listed in `required`, no `default` keyword. Set
 * per-tool because legacy schemas with optional middle-positional args
 * need a reshape before they can opt in.
 */
final class ToolDefinition
{
    /** @param array<string, mixed> $parameters JSON Schema for the tool arguments */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        public readonly bool $sideEffect = false,
        public readonly bool $idempotent = false,
        public readonly bool $strict = false,
    ) {
    }

    public function toLlmDefinition(): array
    {
        $function = [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->parameters,
        ];
        if ($this->strict) {
            $function['strict'] = true;
        }
        return [
            'type' => 'function',
            'function' => $function,
        ];
    }
}

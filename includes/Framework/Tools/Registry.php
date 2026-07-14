<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Tools;

/**
 * Maps tool name → Tool implementation. The loop uses get($name) to dispatch.
 * Names are case-sensitive and must match the LLM-emitted tool call name
 * exactly; mismatches surface as tool_unknown errors back to the model so it
 * can correct itself.
 */
final class Registry
{
    // Exceptions are internal registration errors (empty/duplicate tool name),
    // caught by the runtime and surfaced as JSON/logs — never echoed as HTML.
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    /** @var array<string, Tool> */
    private array $tools = [];

    public function register(Tool $tool): void
    {
        $name = $tool->definition()->name;
        if ($name === '') {
            throw new \InvalidArgumentException('Tool definition name cannot be empty.');
        }
        if (isset($this->tools[$name])) {
            throw new \InvalidArgumentException(sprintf('Tool "%s" is already registered.', $name));
        }
        $this->tools[$name] = $tool;
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return list<\ProjectFlash\Agent\Framework\ToolDefinition> */
    public function definitions(): array
    {
        $out = [];
        foreach ($this->tools as $tool) {
            $out[] = $tool->definition();
        }
        return $out;
    }

    /** @return list<array<string, mixed>> LLM wire shape (OpenAI-compatible function tools). */
    public function llmDefinitions(): array
    {
        return array_map(static fn($t) => $t->toLlmDefinition(), $this->definitions());
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }
}

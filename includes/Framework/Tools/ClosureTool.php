<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Tools;

use ProjectFlash\Agent\Framework\ToolDefinition;
use ProjectFlash\Agent\Framework\ToolResult;

/**
 * Tool whose execute logic is a closure. Useful for tests and for cheap
 * wrappers around existing PHP services (the WP bridges in
 * src/WordPress/Tools/ subclass Tool directly instead, because they want
 * typed dependencies in their constructor).
 */
final class ClosureTool implements Tool
{
    private ToolDefinition $definition;

    /** @var \Closure(array<string, mixed>): ToolResult */
    private \Closure $handler;

    /**
     * @param array<string, mixed> $parameters JSON Schema
     * @param \Closure(array<string, mixed>): ToolResult $handler
     */
    public function __construct(
        string $name,
        string $description,
        array $parameters,
        bool $sideEffect,
        \Closure $handler,
        bool $idempotent = false,
        bool $strict = false,
    ) {
        $this->definition = new ToolDefinition($name, $description, $parameters, $sideEffect, $idempotent, $strict);
        $this->handler = $handler;
    }

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }

    public function execute(array $arguments): ToolResult
    {
        return ($this->handler)($arguments);
    }
}

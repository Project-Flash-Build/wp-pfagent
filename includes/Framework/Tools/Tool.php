<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Tools;

use ProjectFlash\Agent\Framework\ToolDefinition;
use ProjectFlash\Agent\Framework\ToolResult;

/**
 * Contract every concrete tool implements. Two methods: a static-ish
 * `definition()` that tells the registry + LLM what this tool is, and
 * `execute($args)` that runs it.
 *
 * execute() MUST always return a ToolResult (never throw, never return
 * WP_Error). The loop expects every failure to surface through
 * ToolResult::failure() so it can feed the error back to the model and let
 * it self-correct.
 */
interface Tool
{
    public function definition(): ToolDefinition;

    /** @param array<string, mixed> $arguments */
    public function execute(array $arguments): ToolResult;
}

<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * What a Tool::execute returns to the loop.
 *
 *   result      Free-form content the model will see as the tool_result message body.
 *   stateAfter  Snapshot of the relevant state AFTER the tool ran. The loop appends
 *               this to the tool_result content so the model always reasons on
 *               fresh state instead of inferring "done" from action alone. This is
 *               the antidote to false-done hallucinations: if the model says
 *               "limpio" the previous stateAfter has to actually contain {empty: true}.
 *   error       null on success; { code, message, retriable } on failure. The loop
 *               surfaces this verbatim to the model so it can self-correct.
 *
 * Tools NEVER return WP_Error / Exception out of execute() — they convert all
 * failure modes into this envelope so the loop can keep going.
 */
final class ToolResult
{
    /**
     * @param mixed $result
     * @param mixed $stateAfter
     * @param array{code: string, message: string, retriable: bool}|null $error
     */
    public function __construct(
        public readonly mixed $result,
        public readonly mixed $stateAfter = null,
        public readonly ?array $error = null,
    ) {
    }

    public static function ok(mixed $result, mixed $stateAfter = null): self
    {
        return new self($result, $stateAfter, null);
    }

    public static function failure(string $code, string $message, bool $retriable = false): self
    {
        return new self(null, null, [
            'code' => $code,
            'message' => $message,
            'retriable' => $retriable,
        ]);
    }

    /** Wire shape sent back to the LLM as the tool_result content. */
    public function toContent(): string
    {
        $payload = [];
        if ($this->error !== null) {
            $payload['error'] = $this->error;
        } else {
            $payload['result'] = $this->result;
        }
        if ($this->stateAfter !== null) {
            $payload['state_after'] = $this->stateAfter;
        }
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

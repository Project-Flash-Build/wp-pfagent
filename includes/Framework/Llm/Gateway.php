<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Llm;

use ProjectFlash\Agent\Framework\Message;

/**
 * Abstract LLM provider. Two operations:
 *
 *   discoverCaps(model)  → { contextLength, maxOutputTokens }
 *       Hits the provider's models endpoint (or equivalent) to learn the
 *       hard caps for a given model. Cached by the Loop in the conversation's
 *       metadata.modelCaps so we never re-hit per turn.
 *
 *   complete(req)         → CompletionResponse
 *       Synchronous tool-aware chat completion. The implementation handles
 *       auto-continuation when finish_reason='length': it issues a follow-up
 *       call with assistant prefix completion and concatenates the text
 *       BEFORE returning, so the caller sees a complete response. Tool calls
 *       are NEVER split across continuations — if the model was in the middle
 *       of emitting a tool_call when truncated, the gateway issues exactly
 *       ONE continuation and gives up after that; the caller decides what to
 *       do with a still-truncated response.
 */
interface Gateway
{
    /** @return array{contextLength: int, maxOutputTokens: int} */
    public function discoverCaps(string $model): array;

    public function complete(CompletionRequest $request): CompletionResponse;
}

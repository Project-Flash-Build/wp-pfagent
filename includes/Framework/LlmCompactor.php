<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

use ProjectFlash\Agent\Framework\Llm\CompletionRequest;
use ProjectFlash\Agent\Framework\Llm\Gateway;
use ProjectFlash\Agent\Framework\Llm\Prompts;

/**
 * Anchored-summary compactor. Calls an LLM with the Kilo Code compaction
 * prompt (Prompts::COMPACTION) to fold the older middle of a long
 * conversation into a single summary message so the next turn's context
 * stays bounded.
 *
 * This is the LLM-driven folder used by the live wire-shape path
 * (Loop::maybeCompactWireMessages). The legacy structural ConversationCompactor
 * that this once mirrored has been removed as dead code (its v1 caller
 * AgentRuntime::run_loop no longer exists).
 *
 * Recommended usage: pick a cheap "small model" (Haiku, Gemini Flash,
 * DeepSeek Flash) via the credential's `settings.small_model_id` slot
 * for compaction passes; the main chat keeps using the heavier model.
 */
final class LlmCompactor
{
    public function __construct(
        private readonly Gateway $gateway,
        private readonly string $model,
        private readonly int $maxOutputTokens = 4096,
    ) {
    }

    /**
     * Produce a new anchored summary that folds in the supplied messages.
     * Returns the model's summary text verbatim; the caller is responsible
     * for splicing it back into the conversation (e.g. as a single user
     * message replacing the compacted range).
     *
     * @param list<Message> $messages       Older messages to summarise.
     * @param string|null   $previousSummary Existing anchored summary, if any.
     */
    public function compact(array $messages, ?string $previousSummary = null): string
    {
        $userPrompt = self::buildUserPrompt($messages, $previousSummary);

        $request = new CompletionRequest(
            model: $this->model,
            messages: [
                Message::system(Prompts::COMPACTION),
                Message::user($userPrompt),
            ],
            maxOutputTokens: $this->maxOutputTokens,
        );

        $response = $this->gateway->complete($request);
        return trim($response->text);
    }

    /**
     * Build the user-side prompt the compactor sends to the LLM. The
     * <previous-summary> + <conversation-history> sections match the
     * structure Kilo's compaction.txt assumes; deviating from it would
     * defeat the anchoring contract.
     *
     * @param list<Message> $messages
     */
    private static function buildUserPrompt(array $messages, ?string $previousSummary): string
    {
        $lines = [];
        if ($previousSummary !== null && trim($previousSummary) !== '') {
            $lines[] = '<previous-summary>';
            $lines[] = $previousSummary;
            $lines[] = '</previous-summary>';
            $lines[] = '';
        }
        $lines[] = '<conversation-history>';
        foreach ($messages as $message) {
            $content = $message->content ?? '';
            $lines[] = sprintf('[%s] %s', $message->role, $content);
        }
        $lines[] = '</conversation-history>';
        $lines[] = '';
        $lines[] = 'Update the anchored summary with the conversation history above.';
        $lines[] = 'Preserve still-true facts, drop stale details, merge new ones.';
        $lines[] = 'Output ONLY the new summary text, no preamble.';

        return implode("\n", $lines);
    }
}

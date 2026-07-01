<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * One message in the chat conversation, in canonical wire shape.
 *
 *   role      ∈ system | user | assistant | tool
 *   content   text or null (assistant tool-only turns have null content)
 *   toolCalls list of [id, name, arguments] when role=assistant and the LLM emitted tool calls
 *   toolCallId set when role=tool and points to the assistant call this message answers
 *   reasoning  DeepSeek 'reasoning_content' if the provider returned one; echoed back on
 *              follow-up requests because some OpenAI-compat providers reject otherwise
 *
 * Value object: immutable, equality by value.
 */
final class Message
{
    public const ROLE_SYSTEM = 'system';
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_TOOL = 'tool';

    /** @param array<int, array{id: string, name: string, arguments: array<string, mixed>}> $toolCalls */
    public function __construct(
        public readonly string $role,
        public readonly ?string $content,
        public readonly array $toolCalls = [],
        public readonly string $toolCallId = '',
        public readonly string $reasoning = '',
        public readonly string $finishReason = '',
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        /**
         * Tool name for role=tool messages. OpenAI/Anthropic pair tool
         * results to their originating call by id (`tool_call_id`), so the
         * name is redundant there. Gemini does NOT use ids — pairing is by
         * `functionResponse.name`. Store the name on tool messages so the
         * Gemini adapter can build the right wire shape without re-walking
         * the history.
         */
        public readonly string $toolName = '',
    ) {
    }

    public static function system(string $content): self
    {
        return new self(self::ROLE_SYSTEM, $content);
    }

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    /** @param array<int, array{id: string, name: string, arguments: array<string, mixed>}> $toolCalls */
    public static function assistant(?string $content, array $toolCalls = [], string $reasoning = '', string $finishReason = ''): self
    {
        return new self(self::ROLE_ASSISTANT, $content, $toolCalls, '', $reasoning, $finishReason);
    }

    public static function tool(string $toolCallId, string $content, string $toolName = ''): self
    {
        return new self(self::ROLE_TOOL, $content, [], $toolCallId, '', '', 0, 0, $toolName);
    }

    /** Returns the wire-shape array the LLM provider expects. Subclasses keep this in sync. */
    public function toWire(): array
    {
        $out = ['role' => $this->role];
        if ($this->role === self::ROLE_ASSISTANT) {
            // Some providers (OpenAI, DeepSeek) want a string content (may be empty)
            // alongside tool_calls. Use empty string when content is null.
            $out['content'] = self::sanitizeContent($this->content ?? '');
            if ($this->toolCalls !== []) {
                $out['tool_calls'] = array_map(static fn(array $c): array => [
                    'id' => $c['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $c['name'],
                        'arguments' => is_string($c['arguments'] ?? null)
                            ? $c['arguments']
                            : (string) json_encode($c['arguments'] ?? new \stdClass()),
                    ],
                ], $this->toolCalls);
            }
            // DeepSeek requires `reasoning_content` on every assistant turn that
            // followed a thinking-mode response, even when the value is empty;
            // omitting it returns HTTP 400 on the next round. Other OpenAI-compat
            // providers tolerate the extra field, so always emitting it is the
            // safe lowest-common-denominator. (Kilo Code transform.ts:280-296.)
            $out['reasoning_content'] = self::sanitizeContent($this->reasoning);
            return $out;
        }
        if ($this->role === self::ROLE_TOOL) {
            return [
                'role' => self::ROLE_TOOL,
                'tool_call_id' => $this->toolCallId,
                'content' => self::sanitizeContent($this->content ?? ''),
            ];
        }
        $out['content'] = self::sanitizeContent($this->content ?? '');
        return $out;
    }

    /**
     * Replace lone UTF-16 surrogates (and any other invalid UTF-8 bytes) with
     * U+FFFD so the wire stays valid JSON. Some providers (notably Anthropic
     * and Gemini) reject payloads containing malformed surrogate pairs that
     * sneak in from clipboard paste of decorative characters. Public so
     * gateways can apply it before building their own wire shapes.
     */
    public static function sanitizeContent(string $content): string
    {
        if ($content === '') {
            return $content;
        }
        // mb_scrub replaces ALL invalid UTF-8 (including orphan surrogate
        // encodings) with the configured substitute char (U+FFFD by default).
        if (function_exists('mb_scrub')) {
            return mb_scrub($content, 'UTF-8');
        }
        // Fallback: strip lone surrogate code points via PCRE.
        $scrubbed = @preg_replace(
            '/[\x{D800}-\x{DBFF}](?![\x{DC00}-\x{DFFF}])|(?<![\x{D800}-\x{DBFF}])[\x{DC00}-\x{DFFF}]/u',
            "\u{FFFD}",
            $content,
        );
        return is_string($scrubbed) ? $scrubbed : $content;
    }
}

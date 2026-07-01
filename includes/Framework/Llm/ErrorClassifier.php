<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Llm;

/**
 * Pure helper that maps a provider error body to a stable category so the
 * Loop can decide whether to compact, retry, surface, or abort.
 *
 * Today the Loop still treats every 4xx/5xx as a generic provider error.
 * The intent is that Sprint B wires this in: callers compare the returned
 * category to `CATEGORY_CONTEXT_OVERFLOW` and trigger compaction instead of
 * surfacing a 400 to the user; `CATEGORY_OVERLOAD_RETRIABLE` and
 * `CATEGORY_RATE_LIMIT_RETRIABLE` mean "back off and retry"; the rest
 * propagate.
 *
 * Pattern source: Kilo Code `packages/opencode/src/provider/error.ts:8-28`
 * plus their stream-error code map at lines 123-168. 28 overflow regex
 * cover Anthropic, Bedrock, OpenAI, Gemini, xAI, Groq, OpenRouter, DeepSeek,
 * vLLM, Copilot, llama.cpp, LM Studio, MiniMax, Kimi, Moonshot, Mistral,
 * Cerebras, Ollama, z.ai, and the generic OpenAI `context_length_exceeded`
 * code.
 */
final class ErrorClassifier
{
    public const CATEGORY_CONTEXT_OVERFLOW = 'context_overflow';
    public const CATEGORY_QUOTA_EXHAUSTED = 'quota_exhausted';
    public const CATEGORY_BILLING = 'billing';
    public const CATEGORY_RATE_LIMIT_RETRIABLE = 'rate_limit_retriable';
    public const CATEGORY_OVERLOAD_RETRIABLE = 'overload_retriable';
    public const CATEGORY_SERVER_ERROR_RETRIABLE = 'server_error_retriable';
    public const CATEGORY_INVALID_REQUEST = 'invalid_request';
    public const CATEGORY_UNAUTHORIZED = 'unauthorized';
    public const CATEGORY_UNKNOWN = 'unknown';

    /**
     * Patterns that signal "the prompt is too long for this model". Triggers
     * conversation compaction in the Loop. Order does not matter — we return
     * on first hit, but every entry is exclusive of the others.
     *
     * @var list<string>
     */
    private const CONTEXT_OVERFLOW_PATTERNS = [
        '/prompt is too long/i',                                     // Anthropic
        '/input is too long for requested model/i',                  // Amazon Bedrock
        '/exceeds the context window/i',                             // OpenAI
        '/input token count.*exceeds the maximum/i',                 // Gemini
        '/maximum prompt length is \d+/i',                           // xAI Grok
        '/reduce the length of the messages/i',                      // Groq
        '/maximum context length is \d+ tokens/i',                   // OpenRouter, DeepSeek, vLLM
        '/exceeds the limit of \d+/i',                               // GitHub Copilot
        '/exceeds the available context size/i',                    // llama.cpp
        '/greater than the context length/i',                        // LM Studio
        '/context window exceeds limit/i',                           // MiniMax
        '/exceeded model token limit/i',                             // Kimi For Coding, Moonshot
        '/context[_ ]length[_ ]exceeded/i',                          // OpenAI generic, fallback
        '/request entity too large/i',                               // HTTP 413
        '/context length is only \d+ tokens/i',                      // vLLM
        '/input length.*exceeds.*context length/i',                  // vLLM
        '/prompt too long; exceeded (?:max )?context length/i',      // Ollama
        '/too large for model with \d+ maximum context length/i',    // Mistral
        '/model_context_window_exceeded/i',                          // z.ai
        '/^4(?:00|13)\s*(?:status code)?\s*\(no body\)/i',           // Cerebras / Mistral empty 4xx
    ];

    /**
     * OpenAI-style streamed error code → category. Source: Kilo Code
     * `provider/error.ts:123-168`. Caller passes the `error.code` string
     * from a parsed JSON error envelope.
     *
     * @var array<string, array{category: string, retriable: bool}>
     */
    private const STREAM_ERROR_CODES = [
        'context_length_exceeded' => ['category' => self::CATEGORY_CONTEXT_OVERFLOW, 'retriable' => false],
        'insufficient_quota'      => ['category' => self::CATEGORY_QUOTA_EXHAUSTED, 'retriable' => false],
        'usage_not_included'      => ['category' => self::CATEGORY_BILLING, 'retriable' => false],
        'invalid_prompt'          => ['category' => self::CATEGORY_INVALID_REQUEST, 'retriable' => false],
        'server_is_overloaded'    => ['category' => self::CATEGORY_OVERLOAD_RETRIABLE, 'retriable' => true],
        'server_error'            => ['category' => self::CATEGORY_SERVER_ERROR_RETRIABLE, 'retriable' => true],
    ];

    /**
     * Classify a provider error. Pass the raw HTTP body excerpt (already
     * truncated by the gateway is fine — patterns match the first few hundred
     * characters) and optionally the HTTP status code.
     *
     * The status code is only used as a tiebreaker for body-less responses
     * (e.g. Cerebras 400 with empty body); patterns in the body always win
     * because providers are inconsistent about which 4xx they pick.
     */
    public static function classify(string $body, int $statusCode = 0): string
    {
        $body = trim($body);

        if ($body !== '') {
            foreach (self::CONTEXT_OVERFLOW_PATTERNS as $pattern) {
                if (preg_match($pattern, $body) === 1) {
                    return self::CATEGORY_CONTEXT_OVERFLOW;
                }
            }

            // Stream-error envelope check: `{"error":{"code":"…"}}`.
            $code = self::extractErrorCode($body);
            if ($code !== '' && isset(self::STREAM_ERROR_CODES[$code])) {
                return self::STREAM_ERROR_CODES[$code]['category'];
            }

            $lower = strtolower($body);
            if (str_contains($lower, 'credit balance is too low') || str_contains($lower, 'payment required') || str_contains($lower, 'billing')) {
                return self::CATEGORY_BILLING;
            }
            if (str_contains($lower, 'quota')) {
                return self::CATEGORY_QUOTA_EXHAUSTED;
            }
            if (str_contains($lower, 'overloaded') || str_contains($lower, 'try again later')) {
                return self::CATEGORY_OVERLOAD_RETRIABLE;
            }
            if (str_contains($lower, 'invalid api key') || str_contains($lower, 'authentication') || str_contains($lower, 'unauthorized')) {
                return self::CATEGORY_UNAUTHORIZED;
            }
        }

        return match (true) {
            $statusCode === 401 || $statusCode === 403 => self::CATEGORY_UNAUTHORIZED,
            $statusCode === 429                        => self::CATEGORY_RATE_LIMIT_RETRIABLE,
            $statusCode === 503                        => self::CATEGORY_OVERLOAD_RETRIABLE,
            $statusCode >= 500 && $statusCode < 600    => self::CATEGORY_SERVER_ERROR_RETRIABLE,
            $statusCode >= 400 && $statusCode < 500    => self::CATEGORY_INVALID_REQUEST,
            default                                    => self::CATEGORY_UNKNOWN,
        };
    }

    public static function isRetriable(string $category): bool
    {
        return $category === self::CATEGORY_RATE_LIMIT_RETRIABLE
            || $category === self::CATEGORY_OVERLOAD_RETRIABLE
            || $category === self::CATEGORY_SERVER_ERROR_RETRIABLE;
    }

    /**
     * Try to read the OpenAI-style `error.code` out of a JSON error envelope.
     * Returns '' if the body isn't JSON or doesn't carry a code.
     */
    private static function extractErrorCode(string $body): string
    {
        $start = strpos($body, '{');
        if ($start === false) {
            return '';
        }
        $decoded = json_decode(substr($body, $start), true);
        if (!is_array($decoded)) {
            return '';
        }
        $err = $decoded['error'] ?? null;
        if (!is_array($err)) {
            return '';
        }
        $code = $err['code'] ?? '';
        return is_string($code) ? $code : '';
    }
}

<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Llm;

use ProjectFlash\Agent\Framework\Message;

/**
 * Anthropic Messages API gateway. Implements Gateway with full feature
 * coverage:
 *
 *  - Strict user/assistant role alternation (system extracted to top level).
 *  - `tool_use` ↔ `tool_result` pairing.
 *  - `cache_control` injection on stable prefixes (tools + system + last
 *    assistant) when prompt size exceeds per-model minimum from ModelCatalog.
 *  - `thinking` blocks round-tripped on assistant messages.
 *  - Usage normalised with cache_read / cache_creation / fresh_input separated.
 *  - Cost computed via ModelCatalog.
 *  - Caps from ModelCatalog (Anthropic does not expose them via /v1/models).
 *  - NEVER auto-continues on max_tokens (Anthropic has no prefix completion).
 *
 * See docs/llm-providers/anthropic.md for the full API spec.
 */
final class AnthropicGateway implements Gateway
{
    // Exceptions here carry the provider's HTTP/API error text; they are caught
    // by the runtime, returned to the client as a JSON error and matched by
    // ErrorClassifier. They are NEVER echoed as HTML, so esc_html would corrupt
    // the classified/displayed text. Justified, class-scoped.
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    public const DEFAULT_API_VERSION = '2023-06-01';

    /** @var array<string, array{contextLength: int, maxOutputTokens: int}> */
    private array $capsCache = [];

    /**
     * @param string $baseUrl Provider root incl. its version segment (e.g.
     *        `https://api.anthropic.com/v1`). Comes from the preset
     *        configuration; the gateway never hardcodes a URL.
     * @param array<int, string> $betaFeatures e.g. ['prompt-caching-2024-07-31', 'context-1m-2024-08-07']
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly ?ModelCatalog $catalog = null,
        private readonly string $apiVersion = self::DEFAULT_API_VERSION,
        private readonly array $betaFeatures = [],
        private readonly int $httpTimeoutSeconds = 120,
    ) {
        if ($this->baseUrl === '') {
            throw new \InvalidArgumentException('AnthropicGateway: baseUrl is required (use the preset configuration).');
        }
    }

    public function discoverCaps(string $model): array
    {
        if (isset($this->capsCache[$model])) {
            return $this->capsCache[$model];
        }

        // F17: try the catalog FIRST when the operator pre-filled caps
        // via the wizard — instant resolve, no network. Live /v1/models is
        // the source of truth, but the pre-flight on an unreachable host
        // blocks for httpTimeoutSeconds (default 120s) which is too much
        // for caps that we've already cached on the credential.
        if ($this->catalog !== null) {
            $caps = $this->catalog->capsFor($model);
            if (is_array($caps)
                && ($caps['contextLength'] ?? 0) > 0
                && ($caps['maxOutputTokens'] ?? 0) > 0
            ) {
                return $this->capsCache[$model] = [
                    'contextLength' => (int) $caps['contextLength'],
                    'maxOutputTokens' => (int) $caps['maxOutputTokens'],
                ];
            }
        }

        // No catalog entry — fall through to live /v1/models with a
        // bounded timeout so an unreachable host can't burn 120s.
        $live = $this->fetchCapsFromApi($model);
        if ($live !== null) {
            return $this->capsCache[$model] = $live;
        }

        if ($this->catalog !== null) {
            $caps = $this->catalog->capsFor($model);
            if ($caps !== null) {
                return $this->capsCache[$model] = $caps;
            }
        }

        throw new \RuntimeException(sprintf(
            'AnthropicGateway: could not discover caps for "%s". /v1/models/{id} did not resolve and no ModelCatalog fallback exists.',
            $model,
        ));
    }

    /**
     * Probe Anthropic /v1/models/{id} for caps. Returns null on any failure
     * (network error, non-200 status, missing fields) so the caller can fall
     * back to the catalog without surfacing the underlying error.
     *
     * @return array{contextLength: int, maxOutputTokens: int}|null
     */
    private function fetchCapsFromApi(string $model): ?array
    {
        // F17: bound caps pre-flight to min(5, httpTimeoutSeconds) so an
        // unreachable host can't burn the full 120s. Main /messages call still
        // uses the unbounded timeout. Blocking request (no streaming), so the
        // WordPress HTTP API is behavior-equivalent to the old curl.
        $response = wp_remote_get(rtrim($this->baseUrl, '/') . '/models/' . rawurlencode($model), [
            'timeout' => max(1, min(5, $this->httpTimeoutSeconds)),
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
                'Accept' => 'application/json',
            ],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $raw = wp_remote_retrieve_body($response);
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $context = (int) ($decoded['max_input_tokens'] ?? 0);
        $output = (int) ($decoded['max_tokens'] ?? 0);
        if ($context <= 0 || $output <= 0) {
            return null;
        }
        return ['contextLength' => $context, 'maxOutputTokens' => $output];
    }

    public function complete(CompletionRequest $request): CompletionResponse
    {
        $caps = $this->discoverCaps($request->model);
        $maxOutput = $request->maxOutputTokens > 0
            ? min($request->maxOutputTokens, $caps['maxOutputTokens'])
            : $caps['maxOutputTokens'];

        $converted = $this->convertMessages($request->messages);
        $body = $this->buildBody($request, $converted, $maxOutput);

        if ($this->catalog !== null) {
            $body = PromptCacheInjector::injectIfWorthIt(
                $body,
                $request->model,
                $this->catalog->minCacheTokensFor($request->model),
            );
        }

        $response = $this->httpPost('/messages', $body);
        $parsed = $this->parseResponse($response);

        $cost = $this->catalog?->computeCostMicros($request->model, $parsed['usage']) ?? 0;
        // Signal-only: a catalog that exists but cannot price this model means
        // the round is uncounted, not free. The cost stays 0; the flag lets
        // the Loop emit a cost_unknown trace.
        $costUnknown = $this->catalog !== null && $this->catalog->pricingFor($request->model) === null;

        return new CompletionResponse(
            text: $parsed['text'],
            toolCalls: $parsed['toolCalls'],
            finishReason: $parsed['finishReason'],
            reasoning: $parsed['reasoning'],
            usage: $parsed['usage'],
            costMicros: $cost,
            continued: false,
            rawModel: (string) ($response['model'] ?? $request->model),
            systemFingerprint: '',
            logprobs: null,
            costUnknown: $costUnknown,
        );
    }

    /**
     * Build the wire body. `max_tokens` is REQUIRED; system is top-level.
     *
     * @param array{system: string, messages: list<array<string, mixed>>} $converted
     * @return array<string, mixed>
     */
    private function buildBody(CompletionRequest $request, array $converted, int $maxOutput): array
    {
        $body = [
            'model' => $request->model,
            'max_tokens' => $maxOutput,
            'messages' => $converted['messages'],
        ];

        if ($converted['system'] !== '') {
            $body['system'] = $converted['system'];
        }

        // Per-model defaults from the credential catalog (Kilo Tier 1.9).
        // Anthropic's server-side default is 1.0; Kilo recommends letting it
        // win for Claude unless the host explicitly overrides. We omit the
        // field whenever the resolved value is null OR equals 1.0 so the
        // request stays as compact as possible (extra fields can also
        // affect prompt-cache request hashes).
        $defaults = $this->catalog?->defaultsFor($request->model) ?? [];
        $temperature = $request->temperature ?? ($defaults['temperature'] ?? null);
        $topP = $request->topP ?? ($defaults['topP'] ?? null);
        if ($temperature !== null && $temperature !== 1.0) {
            $body['temperature'] = $temperature;
        }
        if ($topP !== null) {
            $body['top_p'] = $topP;
        }
        if ($request->stop !== []) {
            $body['stop_sequences'] = array_values(array_slice($request->stop, 0, 4));
        }
        if ($request->tools !== []) {
            $body['tools'] = $this->convertTools($request->tools);
            $body['tool_choice'] = $this->convertToolChoice($request->toolChoice, $request->parallelToolCalls);
        }
        if ($request->reasoningEffort !== null) {
            $body['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => $this->mapReasoningEffortToBudget($request->reasoningEffort, $maxOutput),
            ];
            // Thinking incompatible with temperature / top_p — drop them.
            unset($body['temperature'], $body['top_p']);
        }

        if ($request->extraBody !== []) {
            $body = array_replace($body, $request->extraBody);
        }

        return $body;
    }

    /**
     * Convert neutral Message[] → Anthropic shape. Extracts system messages,
     * pairs tool_use ↔ tool_result, preserves thinking blocks on assistant
     * messages so the signature round-trip stays valid.
     *
     * @param list<Message> $messages
     * @return array{system: string, messages: list<array<string, mixed>>}
     */
    private function convertMessages(array $messages): array
    {
        $systemParts = [];
        $out = [];

        foreach ($messages as $m) {
            $role = $m->role;

            if ($role === Message::ROLE_SYSTEM) {
                if ($m->content !== null && trim($m->content) !== '') {
                    $systemParts[] = Message::sanitizeContent($m->content);
                }
                continue;
            }

            if ($role === Message::ROLE_TOOL) {
                // Tool results go in a user message as tool_result blocks.
                // Claude rejects tool_use_id with non-alphanumeric chars; scrub
                // before sending. (Kilo Code transform.ts:177-204.)
                $out[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => self::scrubToolUseId($m->toolCallId),
                        'content' => Message::sanitizeContent($m->content ?? ''),
                    ]],
                ];
                continue;
            }

            if ($role === Message::ROLE_ASSISTANT && $m->toolCalls !== []) {
                $blocks = [];
                if ($m->reasoning !== '') {
                    // Thinking block must come first if present. Anthropic
                    // verifies signature on round-trip; passing the
                    // signature here is essential. Note: Message doesn't
                    // currently carry a signature; future work to plumb it
                    // through. For now we send the thinking text without
                    // signature; Anthropic will reject if it actually
                    // verifies — most tool-only loops don't include thinking
                    // anyway.
                }
                if ($m->content !== null && $m->content !== '') {
                    $blocks[] = ['type' => 'text', 'text' => Message::sanitizeContent($m->content)];
                }
                foreach ($m->toolCalls as $call) {
                    $args = $call['arguments'] ?? [];
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => self::scrubToolUseId((string) ($call['id'] ?? '')),
                        'name' => (string) ($call['name'] ?? ''),
                        'input' => empty($args) ? new \stdClass() : $args,
                    ];
                }
                // $blocks may legitimately be just [tool_use, ...] (no text);
                // Anthropic accepts that. We only drop the message entirely
                // when there is no payload at all (defensive — shouldn't happen).
                if ($blocks === []) {
                    continue;
                }
                $out[] = ['role' => 'assistant', 'content' => $blocks];
                continue;
            }

            // Plain text user / assistant message. Anthropic 400s on empty
            // string content or whitespace-only, so drop those instead of
            // sending them as placeholders. (Kilo Code transform.ts:133-151.)
            if ($m->content === null || trim($m->content) === '') {
                continue;
            }
            $out[] = [
                'role' => $role === Message::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => Message::sanitizeContent($m->content),
            ];
        }

        // Anthropic requires the first non-system message to be user-role. If
        // we got here without one, the host is calling us with a malformed
        // conversation (e.g. only a system prompt). Surface as a typed error
        // rather than synthesizing an empty user placeholder — that placeholder
        // is itself what triggers Anthropic's 400 "messages: text content
        // blocks must be non-empty".
        if ($out === []) {
            throw new \InvalidArgumentException('AnthropicGateway: no user message to send (every message was empty or filtered). Conversation must contain at least one non-empty user / tool message.');
        }
        if ($out[0]['role'] !== 'user') {
            throw new \InvalidArgumentException('AnthropicGateway: first message must be user (or tool result); got "' . $out[0]['role'] . '". Anthropic enforces strict user/assistant alternation.');
        }

        return [
            'system' => trim(implode("\n\n", $systemParts)),
            'messages' => $out,
        ];
    }

    /**
     * Anthropic rejects tool_use ids with characters outside `[A-Za-z0-9_-]`.
     * Scrub before sending so ids generated by upstream tooling don't 400
     * the request. (Kilo Code transform.ts:177-204.)
     */
    private static function scrubToolUseId(string $id): string
    {
        if ($id === '') {
            return $id;
        }
        $scrubbed = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
        return is_string($scrubbed) ? $scrubbed : $id;
    }

    /**
     * Convert OpenAI-shaped function tools to Anthropic shape.
     * OpenAI: {type:"function", function:{name, description, parameters}}
     * Anthropic: {name, description, input_schema}
     *
     * @param list<array<string, mixed>> $tools
     * @return list<array<string, mixed>>
     */
    private function convertTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            // Tool may be in OpenAI shape (wrapped) or Anthropic-native shape
            // (flat). Detect and unwrap.
            if (isset($tool['type']) && $tool['type'] === 'function' && isset($tool['function']) && is_array($tool['function'])) {
                $fn = $tool['function'];
                $out[] = [
                    'name' => (string) ($fn['name'] ?? ''),
                    'description' => (string) ($fn['description'] ?? ''),
                    'input_schema' => is_array($fn['parameters'] ?? null) ? $fn['parameters'] : ['type' => 'object', 'properties' => new \stdClass()],
                ];
                continue;
            }

            $out[] = [
                'name' => (string) ($tool['name'] ?? ''),
                'description' => (string) ($tool['description'] ?? ''),
                'input_schema' => is_array($tool['input_schema'] ?? null)
                    ? $tool['input_schema']
                    : (is_array($tool['parameters'] ?? null) ? $tool['parameters'] : ['type' => 'object', 'properties' => new \stdClass()]),
            ];
        }
        return $out;
    }

    /**
     * Convert OpenAI tool_choice (string|object) → Anthropic shape (object).
     *
     * @param string|array<string, mixed> $toolChoice
     * @return array<string, mixed>
     */
    private function convertToolChoice(string|array $toolChoice, ?bool $parallel): array
    {
        $out = ['type' => 'auto'];

        if (is_string($toolChoice)) {
            switch ($toolChoice) {
                case 'none':
                    $out = ['type' => 'none'];
                    break;
                case 'required':
                    $out = ['type' => 'any'];
                    break;
                case 'auto':
                default:
                    $out = ['type' => 'auto'];
                    break;
            }
        } elseif (isset($toolChoice['type']) && $toolChoice['type'] === 'function' && isset($toolChoice['function']['name'])) {
            $out = ['type' => 'tool', 'name' => (string) $toolChoice['function']['name']];
        }

        if ($parallel === false && in_array($out['type'], ['auto', 'any'], true)) {
            $out['disable_parallel_tool_use'] = true;
        }

        return $out;
    }

    private function mapReasoningEffortToBudget(string $effort, int $maxOutput): int
    {
        return match ($effort) {
            'minimal' => 1024,
            'low' => 4096,
            'medium' => 16384,
            'high' => max(32768, (int) ($maxOutput * 0.75)),
            default => 8192,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{text: string, toolCalls: list<array{id: string, name: string, arguments: array<string, mixed>}>, finishReason: string, reasoning: string, usage: array<string, int>}
     */
    private function parseResponse(array $payload): array
    {
        $text = '';
        $reasoning = '';
        $toolCalls = [];

        foreach ((array) ($payload['content'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if ($type === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif ($type === 'thinking') {
                $reasoning .= (string) ($block['thinking'] ?? '');
            } elseif ($type === 'tool_use') {
                $input = $block['input'] ?? [];
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'arguments' => is_array($input) ? $input : (array) (json_decode((string) $input, true) ?? []),
                ];
            }
        }

        $stop = (string) ($payload['stop_reason'] ?? 'unknown');
        $finish = match ($stop) {
            'end_turn' => 'stop',
            'stop_sequence' => 'stop',
            'max_tokens' => 'length',
            'tool_use' => 'tool_calls',
            'refusal' => 'refusal',
            'pause_turn' => 'stop',
            default => 'unknown',
        };

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $cacheCreation = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $totalPrompt = $input + $cacheCreation + $cacheRead;

        return [
            'text' => $text,
            'toolCalls' => $toolCalls,
            'finishReason' => $finish,
            'reasoning' => $reasoning,
            'usage' => [
                'promptTokens' => $totalPrompt,
                'completionTokens' => $output,
                'totalTokens' => $totalPrompt + $output,
                'cacheHitTokens' => $cacheRead,
                'cacheMissTokens' => $input,
                'cacheWriteTokens' => $cacheCreation,
                'reasoningTokens' => 0,
            ],
        ];
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function httpPost(string $path, array $body): array
    {
        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->apiVersion,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($this->betaFeatures !== []) {
            $headers['anthropic-beta'] = implode(',', $this->betaFeatures);
        }

        // Blocking POST (no streaming) → the WordPress HTTP API is
        // behavior-equivalent to the previous curl call.
        $response = wp_remote_post(rtrim($this->baseUrl, '/') . $path, [
            'headers' => $headers,
            'body' => (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => $this->httpTimeoutSeconds,
        ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException('Anthropic HTTP failed: ' . $response->get_error_message());
        }
        $raw = wp_remote_retrieve_body($response);
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 400) {
            $excerpt = substr((string) $raw, 0, 400);
            throw new \RuntimeException(sprintf('Anthropic HTTP %d: %s', $status, $excerpt));
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Anthropic returned non-JSON: ' . substr((string) $raw, 0, 200));
        }
        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Llm;

use ProjectFlash\Agent\Framework\Message;

/**
 * Google Gemini native generateContent gateway. Implements Gateway with full
 * feature coverage:
 *
 *  - Roles converted: user→user, assistant→model, tool→user(functionResponse).
 *  - system extracted to top-level systemInstruction.
 *  - Tool schema trimmed to the OpenAPI-3 subset Gemini accepts (no
 *    additionalProperties, no oneOf/anyOf/allOf outside nullable/enum).
 *  - Empty tool args coerced to stdClass so JSON serialises as `{}` (Gemini
 *    rejects `[]`).
 *  - generationConfig with maxOutputTokens, thinkingConfig, etc.
 *  - safetySettings default to BLOCK_NONE for all 4 categories (agent
 *    context; host owns policy).
 *  - Usage normalised: cachedContentTokenCount → cacheHitTokens,
 *    thoughtsTokenCount → reasoningTokens.
 *  - Caps from /v1beta/models (inputTokenLimit / outputTokenLimit) — Gemini
 *    exposes them directly, no catalog fallback needed when /models works.
 *  - Cost via ModelCatalog (tier-aware for Pro past 200K).
 *  - NEVER auto-continues (Gemini has no prefix completion).
 *
 * See docs/llm-providers/gemini.md for the full API spec.
 */
final class GeminiGateway implements Gateway
{
    /** @var array<string, array{contextLength: int, maxOutputTokens: int}> */
    private array $capsCache = [];

    /**
     * @param string $baseUrl Provider root incl. its version segment (e.g.
     *        `https://generativelanguage.googleapis.com/v1beta`). Comes from
     *        the preset configuration; the gateway never hardcodes a URL.
     * @param array<int, array{category: string, threshold: string}> $safetySettings
     *        Override the default BLOCK_NONE if you want safer agent contexts.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly ?ModelCatalog $catalog = null,
        private readonly array $safetySettings = [],
        private readonly int $httpTimeoutSeconds = 120,
    ) {
        if ($this->baseUrl === '') {
            throw new \InvalidArgumentException('GeminiGateway: baseUrl is required (use the preset configuration).');
        }
    }

    public function discoverCaps(string $model): array
    {
        $key = $this->stripModelsPrefix($model);
        if (isset($this->capsCache[$key])) {
            return $this->capsCache[$key];
        }

        // F17: try the catalog FIRST. When the operator filled the
        // wizard's per-model context/output caps, the catalog answers
        // instantly and we never touch the network. /models is a slow
        // pre-flight on an unreachable host; bounding its timeout to
        // min(5, httpTimeoutSeconds) keeps caps discovery from blowing
        // the budget when the network is degraded.
        $context = 0;
        $output = 0;
        if ($this->catalog !== null) {
            $cat = $this->catalog->capsFor($key);
            if (is_array($cat)
                && ($cat['contextLength'] ?? 0) > 0
                && ($cat['maxOutputTokens'] ?? 0) > 0
            ) {
                return $this->capsCache[$key] = [
                    'contextLength' => (int) $cat['contextLength'],
                    'maxOutputTokens' => (int) $cat['maxOutputTokens'],
                ];
            }
        }

        // No catalog entry — fall through to live /models with a bounded
        // timeout so an unreachable host can't burn 120s.
        $capsTimeout = max(1, min(5, $this->httpTimeoutSeconds));
        $payload = $this->httpGet('/models/' . rawurlencode($key), $capsTimeout);
        if (is_array($payload)) {
            $context = (int) ($payload['inputTokenLimit'] ?? 0);
            $output = (int) ($payload['outputTokenLimit'] ?? 0);
        }

        // Catalog fallback (rare partial-fill case) — already tried above
        // but re-check in case /models returned a partial answer.
        if (($context === 0 || $output === 0) && $this->catalog !== null) {
            $cat = $this->catalog->capsFor($key);
            if ($cat !== null) {
                $context = $context ?: $cat['contextLength'];
                $output = $output ?: $cat['maxOutputTokens'];
            }
        }

        if ($context === 0 || $output === 0) {
            throw new \RuntimeException(sprintf(
                'GeminiGateway: could not discover caps for "%s". Provider /models did not expose them and no catalog entry exists.',
                $key,
            ));
        }

        return $this->capsCache[$key] = ['contextLength' => $context, 'maxOutputTokens' => $output];
    }

    public function complete(CompletionRequest $request): CompletionResponse
    {
        $caps = $this->discoverCaps($request->model);
        $maxOutput = $request->maxOutputTokens > 0
            ? min($request->maxOutputTokens, $caps['maxOutputTokens'])
            : $caps['maxOutputTokens'];

        $converted = $this->convertMessages($request->messages);
        $body = $this->buildBody($request, $converted, $maxOutput);

        $modelKey = $this->stripModelsPrefix($request->model);
        $response = $this->httpPost('/models/' . rawurlencode($modelKey) . ':generateContent', $body);
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
            rawModel: (string) ($response['modelVersion'] ?? $request->model),
            systemFingerprint: '',
            logprobs: null,
            costUnknown: $costUnknown,
        );
    }

    /**
     * @param array{systemInstruction: ?array<string, mixed>, contents: list<array<string, mixed>>} $converted
     * @return array<string, mixed>
     */
    private function buildBody(CompletionRequest $request, array $converted, int $maxOutput): array
    {
        // Per-model defaults from the credential catalog (Gemini exposes
        // temperature / topP / topK directly via /v1beta/models so this is
        // usually pre-filled; Kilo Tier 1.9). Request value wins; omit when
        // both null so Gemini's own default rules.
        $defaults = $this->catalog?->defaultsFor($request->model) ?? [];
        $temperature = $request->temperature ?? ($defaults['temperature'] ?? null);
        $topP = $request->topP ?? ($defaults['topP'] ?? null);
        $topK = $request->topK ?? ($defaults['topK'] ?? null);

        $generationConfig = ['maxOutputTokens' => $maxOutput];
        if ($temperature !== null) {
            $generationConfig['temperature'] = $temperature;
        }
        if ($topP !== null) {
            $generationConfig['topP'] = $topP;
        }
        if ($topK !== null) {
            $generationConfig['topK'] = $topK;
        }
        if ($request->seed !== null) {
            $generationConfig['seed'] = $request->seed;
        }
        // Penalties are a 2.x-and-up feature, and even within 2.x only some
        // tiers support them (gemini-2.5-flash-lite rejects with HTTP 400
        // "Penalty is not enabled"). Gate on the catalog's `penalties`
        // feature flag so we only send them where the provider accepts.
        $supportsPenalties = $this->catalog?->hasFeature($request->model, 'penalties') ?? false;
        if ($supportsPenalties && $request->presencePenalty !== null) {
            $generationConfig['presencePenalty'] = $request->presencePenalty;
        }
        if ($supportsPenalties && $request->frequencyPenalty !== null) {
            $generationConfig['frequencyPenalty'] = $request->frequencyPenalty;
        }
        if ($request->stop !== []) {
            $generationConfig['stopSequences'] = array_values(array_slice($request->stop, 0, 5));
        }
        if ($request->responseFormat !== null) {
            // Best-effort: when response_format declares a json_schema,
            // map to Gemini's responseMimeType + responseSchema.
            $type = (string) ($request->responseFormat['type'] ?? '');
            if ($type === 'json_schema' && isset($request->responseFormat['json_schema']['schema'])) {
                $generationConfig['responseMimeType'] = 'application/json';
                $generationConfig['responseSchema'] = self::trimSchemaForGemini(
                    $request->responseFormat['json_schema']['schema']
                );
            } elseif ($type === 'json_object') {
                $generationConfig['responseMimeType'] = 'application/json';
            }
        }
        if ($request->reasoningEffort !== null) {
            $generationConfig['thinkingConfig'] = [
                'thinkingBudget' => $this->mapReasoningEffortToBudget($request->reasoningEffort, $maxOutput),
            ];
        }

        $body = [
            'contents' => $converted['contents'],
            'generationConfig' => $generationConfig,
            'safetySettings' => $this->safetySettings !== [] ? $this->safetySettings : self::defaultSafetySettings(),
        ];

        if ($converted['systemInstruction'] !== null) {
            $body['systemInstruction'] = $converted['systemInstruction'];
        }

        if ($request->tools !== []) {
            $body['tools'] = [[
                'functionDeclarations' => $this->convertTools($request->tools),
            ]];
            $body['toolConfig'] = $this->convertToolChoice($request->toolChoice);
        }

        if ($request->extraBody !== []) {
            $body = array_replace_recursive($body, $request->extraBody);
        }

        return $body;
    }

    /**
     * @param list<Message> $messages
     * @return array{systemInstruction: array<string, mixed>|null, contents: list<array<string, mixed>>}
     */
    private function convertMessages(array $messages): array
    {
        $systemParts = [];
        $contents = [];

        foreach ($messages as $m) {
            $role = $m->role;

            if ($role === Message::ROLE_SYSTEM) {
                if ($m->content !== null && $m->content !== '') {
                    $systemParts[] = Message::sanitizeContent($m->content);
                }
                continue;
            }

            if ($role === Message::ROLE_TOOL) {
                $raw = Message::sanitizeContent($m->content ?? '');
                $decoded = json_decode($raw, true);
                $response = is_array($decoded) ? $decoded : ['content' => $raw];
                $contents[] = [
                    'role' => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name' => $m->toolName ?: 'tool',
                            'response' => empty($response) ? new \stdClass() : $response,
                        ],
                    ]],
                ];
                continue;
            }

            if ($role === Message::ROLE_ASSISTANT && $m->toolCalls !== []) {
                $parts = [];
                if ($m->content !== null && $m->content !== '') {
                    $parts[] = ['text' => Message::sanitizeContent($m->content)];
                }
                foreach ($m->toolCalls as $call) {
                    $args = $call['arguments'] ?? [];
                    $parts[] = [
                        'functionCall' => [
                            'name' => (string) ($call['name'] ?? ''),
                            'args' => empty($args) ? new \stdClass() : $args,
                        ],
                    ];
                }
                $contents[] = ['role' => 'model', 'parts' => $parts];
                continue;
            }

            $contents[] = [
                'role' => $role === Message::ROLE_ASSISTANT ? 'model' : 'user',
                'parts' => [['text' => Message::sanitizeContent($m->content ?? '')]],
            ];
        }

        if ($contents === []) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => '']]];
        }

        $systemInstruction = null;
        if ($systemParts !== []) {
            $systemInstruction = ['parts' => [['text' => trim(implode("\n\n", $systemParts))]]];
        }

        return ['systemInstruction' => $systemInstruction, 'contents' => $contents];
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @return list<array<string, mixed>>
     */
    private function convertTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            // Unwrap OpenAI shape if present.
            if (isset($tool['type']) && $tool['type'] === 'function' && isset($tool['function']) && is_array($tool['function'])) {
                $fn = $tool['function'];
                $out[] = [
                    'name' => (string) ($fn['name'] ?? ''),
                    'description' => (string) ($fn['description'] ?? ''),
                    'parameters' => self::trimSchemaForGemini($fn['parameters'] ?? null),
                ];
                continue;
            }

            $out[] = [
                'name' => (string) ($tool['name'] ?? ''),
                'description' => (string) ($tool['description'] ?? ''),
                'parameters' => self::trimSchemaForGemini(
                    $tool['parameters'] ?? $tool['input_schema'] ?? null
                ),
            ];
        }
        return $out;
    }

    /**
     * @param string|array<string, mixed> $toolChoice
     * @return array<string, mixed>
     */
    private function convertToolChoice(string|array $toolChoice): array
    {
        if (is_string($toolChoice)) {
            return match ($toolChoice) {
                'none' => ['functionCallingConfig' => ['mode' => 'NONE']],
                'required' => ['functionCallingConfig' => ['mode' => 'ANY']],
                default => ['functionCallingConfig' => ['mode' => 'AUTO']],
            };
        }
        if (isset($toolChoice['type']) && $toolChoice['type'] === 'function' && isset($toolChoice['function']['name'])) {
            return [
                'functionCallingConfig' => [
                    'mode' => 'ANY',
                    'allowedFunctionNames' => [(string) $toolChoice['function']['name']],
                ],
            ];
        }
        return ['functionCallingConfig' => ['mode' => 'AUTO']];
    }

    /**
     * Trim a JSON Schema to the OpenAPI-3 subset Gemini accepts. Drops
     * unsupported keys (additionalProperties, $ref, oneOf, anyOf, allOf
     * unless they encode `nullable`). Coerces empty object schema to
     * `properties: {}` (object literal) so JSON serialises as `{}` not `[]`.
     *
     * @param mixed $schema
     * @return array<string, mixed>|\stdClass
     */
    public static function trimSchemaForGemini($schema): array|\stdClass
    {
        if (!is_array($schema)) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }

        $out = [];

        foreach (['type', 'description', 'format', 'nullable'] as $key) {
            if (isset($schema[$key]) && is_scalar($schema[$key])) {
                $out[$key] = $schema[$key];
            }
        }

        if (is_array($schema['enum'] ?? null)) {
            $out['enum'] = array_values(array_filter($schema['enum'], 'is_scalar'));
        }

        if (is_array($schema['required'] ?? null)) {
            $out['required'] = array_values(array_map('strval', $schema['required']));
        }

        if (is_array($schema['items'] ?? null)) {
            $out['items'] = self::trimSchemaForGemini($schema['items']);
        }

        if (is_array($schema['properties'] ?? null)) {
            $properties = [];
            foreach ($schema['properties'] as $name => $propertySchema) {
                if (!is_string($name) || !is_array($propertySchema)) {
                    continue;
                }
                $properties[$name] = self::trimSchemaForGemini($propertySchema);
            }
            $out['properties'] = $properties === [] ? new \stdClass() : $properties;
        }

        if (($out['type'] ?? '') === 'object' && !array_key_exists('properties', $out)) {
            $out['properties'] = new \stdClass();
        }

        return $out === [] ? new \stdClass() : $out;
    }

    /**
     * @return list<array{category: string, threshold: string}>
     */
    private static function defaultSafetySettings(): array
    {
        // Agent contexts default to BLOCK_NONE because the host owns policy
        // through tools and approvals; an over-zealous safety filter cutting
        // a tool call mid-flight breaks the wire shape and confuses the
        // model on the next round.
        return [
            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
        ];
    }

    private function mapReasoningEffortToBudget(string $effort, int $maxOutput): int
    {
        return match ($effort) {
            'minimal' => 0,
            'low' => 2048,
            'medium' => 8192,
            'high' => min(32768, max(8192, (int) ($maxOutput * 0.5))),
            default => 4096,
        };
    }

    private function stripModelsPrefix(string $model): string
    {
        return preg_replace('#^models/#', '', $model) ?? $model;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{text: string, toolCalls: list<array{id: string, name: string, arguments: array<string, mixed>}>, finishReason: string, reasoning: string, usage: array<string, int>}
     */
    private function parseResponse(array $payload): array
    {
        $candidate = is_array($payload['candidates'][0] ?? null) ? $payload['candidates'][0] : [];
        $parts = is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [];

        $text = '';
        $toolCalls = [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }
            if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                $args = $part['functionCall']['args'] ?? [];
                $toolCalls[] = [
                    'id' => '', // Gemini does not assign ids; pairing is positional
                    'name' => (string) ($part['functionCall']['name'] ?? ''),
                    'arguments' => is_array($args) ? $args : [],
                ];
            }
        }

        $finishRaw = (string) ($candidate['finishReason'] ?? 'STOP');
        $finish = match ($finishRaw) {
            'STOP' => $toolCalls !== [] ? 'tool_calls' : 'stop',
            'MAX_TOKENS' => 'length',
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII' => 'content_filter',
            'MALFORMED_FUNCTION_CALL' => 'tool_calls',
            default => 'unknown',
        };

        $usageMeta = is_array($payload['usageMetadata'] ?? null) ? $payload['usageMetadata'] : [];
        $promptTokens = (int) ($usageMeta['promptTokenCount'] ?? 0);
        $cachedTokens = (int) ($usageMeta['cachedContentTokenCount'] ?? 0);
        $completionTokens = (int) ($usageMeta['candidatesTokenCount'] ?? 0);
        $thoughtsTokens = (int) ($usageMeta['thoughtsTokenCount'] ?? 0);
        $totalTokens = (int) ($usageMeta['totalTokenCount'] ?? ($promptTokens + $completionTokens + $thoughtsTokens));

        return [
            'text' => $text,
            'toolCalls' => $toolCalls,
            'finishReason' => $finish,
            'reasoning' => '',  // Gemini does not surface thought text by default; thoughtsTokenCount only counts them
            'usage' => [
                'promptTokens' => $promptTokens,
                'completionTokens' => $completionTokens,
                'totalTokens' => $totalTokens,
                'cacheHitTokens' => $cachedTokens,
                'cacheMissTokens' => max(0, $promptTokens - $cachedTokens),
                'cacheWriteTokens' => 0,
                'reasoningTokens' => $thoughtsTokens,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function httpGet(string $path, ?int $timeoutOverride = null): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($this->baseUrl, '/') . $path,
            CURLOPT_RETURNTRANSFER => true,
            // F17: caller may override timeout for short-lived calls
            // (caps discovery). Defaults to the gateway's main timeout.
            CURLOPT_TIMEOUT => $timeoutOverride ?? $this->httpTimeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'x-goog-api-key: ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $status >= 400) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function httpPost(string $path, array $body): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($this->baseUrl, '/') . $path,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->httpTimeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'x-goog-api-key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Gemini HTTP failed: ' . $err);
        }
        if ($status >= 400) {
            $excerpt = substr((string) $raw, 0, 400);
            throw new \RuntimeException(sprintf('Gemini HTTP %d: %s', $status, $excerpt));
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Gemini returned non-JSON: ' . substr((string) $raw, 0, 200));
        }
        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

final class ProviderRuntime
{
    private const SAFE_PROMPT = 'Reply with one short sentence containing the word ProjectFlash.';

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly ProviderModelDiscovery $model_discovery
    ) {
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function generate_smoke(string $provider_id)
    {
        $context = $this->credentials->runtime_context($provider_id);
        if ($context instanceof WP_Error) {
            return $context;
        }

        $model_catalog = $this->model_discovery->discover((string) $context['providerId']);
        if ($model_catalog instanceof WP_Error) {
            return $model_catalog;
        }

        $candidate_models = $this->candidate_models($context, is_array($model_catalog['models'] ?? null) ? $model_catalog['models'] : []);
        if ($candidate_models instanceof WP_Error) {
            return $candidate_models;
        }

        $last_error = null;
        foreach (array_slice($candidate_models, 0, 5) as $model) {
            $generation = match ((string) ($context['preset']['family'] ?? '')) {
                'openai-compatible' => $this->generate_openai_compatible($context, $model),
                'anthropic-compatible' => $this->generate_anthropic_compatible($context, $model),
                'gemini-compatible' => $this->generate_gemini_compatible($context, $model),
                default => new WP_Error('pfa_provider_generation_unsupported', __('Provider family is not supported for generation yet.', 'wp-pfagent'), ['status' => 400]),
            };

            if ($generation instanceof WP_Error) {
                $last_error = $generation;
                if ($this->should_try_next_model($generation)) {
                    continue;
                }

                return $generation;
            }

            return [
                'providerId' => (string) $context['providerId'],
                'label' => (string) ($context['preset']['label'] ?? $context['providerId']),
                'family' => (string) ($context['preset']['family'] ?? ''),
                'model' => $model,
                'status' => 'completed',
                'prompt' => self::SAFE_PROMPT,
                'output' => $generation['output'],
                'usage' => $generation['usage'],
                'endpointType' => $generation['endpointType'],
            ];
        }

        return new WP_Error(
            'pfa_provider_generation_no_candidate_succeeded',
            'Generation failed for all discovered model candidates. Last error: ' . ($last_error instanceof WP_Error ? $last_error->get_error_message() : 'unknown'),
            ['status' => 502]
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private function discover_models(array $context)
    {
        $request = $this->request_descriptor($context, 'models');
        if ($request instanceof WP_Error) {
            return $request;
        }

        $response = wp_remote_get($request['url'], [
            'timeout' => 12,
            'redirection' => 1,
            'headers' => $request['headers'],
        ]);

        $decoded = $this->decode_response($response, 'model discovery');
        if ($decoded instanceof WP_Error) {
            return $decoded;
        }

        $family = (string) ($context['preset']['family'] ?? '');
        if ($family === 'gemini-compatible') {
            $models = is_array($decoded['models'] ?? null) ? $decoded['models'] : [];
            return array_values(array_filter($models, 'is_array'));
        }

        $models = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return array_values(array_filter($models, 'is_array'));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $models
     */
    private function select_model(array $context, array $models): string|WP_Error
    {
        $candidates = $this->candidate_models($context, $models);
        if ($candidates instanceof WP_Error) {
            return $candidates;
        }

        return $candidates[0];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $models
     * @return array<int, string>|WP_Error
     */
    private function candidate_models(array $context, array $models): array|WP_Error
    {
        if ($models === []) {
            return new WP_Error('pfa_provider_no_models', __('Provider model discovery returned no models.', 'wp-pfagent'), ['status' => 502]);
        }

        $family = (string) ($context['preset']['family'] ?? '');
        $candidates = [];

        foreach ($models as $model) {
            $id = $this->model_id($family, $model);
            if ($id === '') {
                continue;
            }

            if ($family === 'gemini-compatible') {
                $methods = array_merge(
                    is_array($model['supportedGenerationMethods'] ?? null) ? $model['supportedGenerationMethods'] : [],
                    is_array($model['capabilities'] ?? null) ? $model['capabilities'] : []
                );
                if (!in_array('generateContent', $methods, true)) {
                    continue;
                }
            }

            $score = $this->model_score($id);
            if ($score < 0) {
                continue;
            }

            $candidates[] = ['id' => $id, 'score' => $score];
        }

        if ($candidates === []) {
            return new WP_Error('pfa_provider_no_text_model', __('Provider model discovery returned no text generation model candidate.', 'wp-pfagent'), ['status' => 502]);
        }

        usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_values(array_map(static fn(array $candidate): string => (string) $candidate['id'], $candidates));
    }

    /**
     * @param array<string, mixed> $context
     * @return array{output: string, usage: array<string, mixed>, endpointType: string}|WP_Error
     */
    private function generate_openai_compatible(array $context, string $model)
    {
        $request = $this->request_descriptor($context, 'chatCompletions');
        if ($request instanceof WP_Error) {
            return $request;
        }

        $response = wp_remote_post($request['url'], [
            'timeout' => 25,
            'redirection' => 1,
            'headers' => $request['headers'],
            'body' => (string) wp_json_encode([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a concise API smoke test responder.'],
                    ['role' => 'user', 'content' => self::SAFE_PROMPT],
                ],
                'temperature' => 0,
                'max_tokens' => 128,
            ]),
        ]);

        $decoded = $this->decode_response($response, 'OpenAI-compatible generation');
        if ($decoded instanceof WP_Error) {
            return $decoded;
        }

        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');

        return [
            'output' => $this->clean_output($content),
            'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
            'endpointType' => 'chatCompletions',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{output: string, usage: array<string, mixed>, endpointType: string}|WP_Error
     */
    private function generate_anthropic_compatible(array $context, string $model)
    {
        $request = $this->request_descriptor($context, 'messages');
        if ($request instanceof WP_Error) {
            return $request;
        }

        $response = wp_remote_post($request['url'], [
            'timeout' => 25,
            'redirection' => 1,
            'headers' => $request['headers'],
            'body' => (string) wp_json_encode([
                'model' => $model,
                'max_tokens' => 128,
                'messages' => [
                    ['role' => 'user', 'content' => self::SAFE_PROMPT],
                ],
            ]),
        ]);

        $decoded = $this->decode_response($response, 'Anthropic-compatible generation');
        if ($decoded instanceof WP_Error) {
            return $decoded;
        }

        $parts = is_array($decoded['content'] ?? null) ? $decoded['content'] : [];
        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text') {
                $text .= (string) ($part['text'] ?? '');
            }
        }

        return [
            'output' => $this->clean_output($text),
            'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
            'endpointType' => 'messages',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{output: string, usage: array<string, mixed>, endpointType: string}|WP_Error
     */
    private function generate_gemini_compatible(array $context, string $model)
    {
        $request = $this->request_descriptor($context, 'generateContent', ['model' => $model]);
        if ($request instanceof WP_Error) {
            return $request;
        }

        $response = wp_remote_post($request['url'], [
            'timeout' => 25,
            'redirection' => 1,
            'headers' => $request['headers'],
            'body' => (string) wp_json_encode([
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => self::SAFE_PROMPT],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0,
                    'maxOutputTokens' => 256,
                ],
            ]),
        ]);

        $decoded = $this->decode_response($response, 'Gemini generation');
        if ($decoded instanceof WP_Error) {
            return $decoded;
        }

        $parts = is_array($decoded['candidates'][0]['content']['parts'] ?? null) ? $decoded['candidates'][0]['content']['parts'] : [];
        $text = '';
        foreach ($parts as $part) {
            if (is_array($part)) {
                $text .= (string) ($part['text'] ?? '');
            }
        }

        return [
            'output' => $this->clean_output($text),
            'usage' => is_array($decoded['usageMetadata'] ?? null) ? $decoded['usageMetadata'] : [],
            'endpointType' => 'generateContent',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $extra
     * @return array{url: string, headers: array<string, string>}|WP_Error
     */
    private function request_descriptor(array $context, string $endpoint_key, array $extra = [])
    {
        $family = is_array($context['family'] ?? null) ? $context['family'] : [];
        $preset = is_array($context['preset'] ?? null) ? $context['preset'] : [];
        $settings = is_array($context['settings'] ?? null) ? $context['settings'] : [];
        $api_key = (string) ($context['apiKey'] ?? '');
        $endpoints = is_array($family['endpoints'] ?? null) ? $family['endpoints'] : [];
        $endpoint = (string) ($endpoints[$endpoint_key] ?? '');

        if ($endpoint === '') {
            return new WP_Error('pfa_provider_endpoint_missing', __('Provider family does not expose the required runtime endpoint.', 'wp-pfagent'), ['status' => 400]);
        }

        $values = array_merge(is_array($family['defaults'] ?? null) ? $family['defaults'] : [], $settings, $extra, ['api_key' => $api_key]);
        $base_url = $this->resolve_template((string) ($preset['baseUrl'] ?? ''), $values);
        if ($base_url instanceof WP_Error) {
            return $base_url;
        }

        $headers = [];
        $default_headers = is_array($family['defaultHeaders'] ?? null) ? $family['defaultHeaders'] : [];
        foreach ($default_headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $this->replace_placeholders($value, $values);
            }
        }

        return [
            'url' => rtrim($base_url, '/') . '/' . ltrim($this->replace_placeholders($endpoint, $values), '/'),
            'headers' => $headers,
        ];
    }

    private function decode_response(mixed $response, string $operation): array|WP_Error
    {
        if (is_wp_error($response)) {
            return new WP_Error('pfa_provider_http_failed', $operation . ' failed: ' . $this->redact((string) $response->get_error_message()), ['status' => 502]);
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status_code < 200 || $status_code >= 300) {
            $provider_message = $this->provider_error_message($decoded, $body);
            return new WP_Error(
                'pfa_provider_http_status',
                $operation . ' returned HTTP ' . (string) $status_code . ': ' . $provider_message,
                [
                    'status' => 502,
                    'providerStatus' => $status_code,
                    'errorType' => $this->classify_provider_error($status_code, $provider_message),
                ]
            );
        }

        if (!is_array($decoded)) {
            return new WP_Error('pfa_provider_invalid_json', $operation . ' returned invalid JSON.', ['status' => 502]);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $model
     */
    private function model_id(string $family, array $model): string
    {
        $id = (string) ($model['id'] ?? $model['name'] ?? '');
        if ($family === 'gemini-compatible') {
            $id = preg_replace('#^models/#', '', $id) ?? $id;
        }

        return trim($id);
    }

    private function model_score(string $id): int
    {
        $normalized = strtolower($id);
        foreach (['embed', 'image', 'audio', 'omni', 'tts', 'vision', 'realtime'] as $blocked) {
            if (str_contains($normalized, $blocked)) {
                return -1;
            }
        }

        if (preg_match('/(^|[-_.])(vl|asr|ocr)([-_.]|$)/', $normalized)) {
            return -1;
        }

        $score = 10;
        foreach (['chat', 'instruct', 'claude', 'gemini', 'qwen', 'deepseek', 'mimo'] as $hint) {
            if (str_contains($normalized, $hint)) {
                $score += 10;
            }
        }

        foreach (['flash' => 8, 'haiku' => 7, 'mini' => 5, 'lite' => 5, 'sonnet' => 4, 'pro' => 2, 'opus' => 1] as $hint => $bonus) {
            if (str_contains($normalized, $hint)) {
                $score += $bonus;
            }
        }

        if (str_contains($normalized, 'preview') || str_contains($normalized, 'beta')) {
            $score -= 2;
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function resolve_template(string $template, array $values): string|WP_Error
    {
        $resolved = $this->replace_placeholders($template, $values);
        if (preg_match('/{{\s*[^}]+\s*}}/', $resolved)) {
            return new WP_Error('pfa_provider_settings_missing', __('Provider requires additional settings before runtime call.', 'wp-pfagent'), ['status' => 400]);
        }

        if (!str_starts_with($resolved, 'http://') && !str_starts_with($resolved, 'https://')) {
            return new WP_Error('pfa_provider_base_url_invalid', __('Provider base URL must be an HTTP URL.', 'wp-pfagent'), ['status' => 400]);
        }

        return esc_url_raw($resolved);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function replace_placeholders(string $template, array $values): string
    {
        return (string) preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', static function (array $matches) use ($values): string {
            $key = sanitize_key((string) $matches[1]);
            return array_key_exists($key, $values) ? (string) $values[$key] : (string) $matches[0];
        }, $template);
    }

    private function clean_output(string $output): string
    {
        return trim(wp_strip_all_tags($output));
    }

    private function should_try_next_model(WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $data = is_array($data) ? $data : [];
        $provider_status = (int) ($data['providerStatus'] ?? 0);
        $error_type = (string) ($data['errorType'] ?? '');

        return $error_type === 'invalid_model' || in_array($provider_status, [400, 404], true);
    }

    private function provider_error_message(mixed $decoded, string $body): string
    {
        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? $decoded['error'] ?? null;
            if (is_scalar($message)) {
                return $this->redact((string) $message);
            }
        }

        $body = trim(wp_strip_all_tags($body));
        if ($body === '') {
            return 'provider returned an empty error body';
        }

        return $this->redact(substr($body, 0, 240));
    }

    private function classify_provider_error(int $status_code, string $message): string
    {
        $message = strtolower($message);
        if ($status_code === 401 || $status_code === 403) {
            return 'auth';
        }

        if ($status_code === 402 || str_contains($message, 'quota') || str_contains($message, 'insufficient') || str_contains($message, 'balance')) {
            return 'quota';
        }

        if ($status_code === 429 || str_contains($message, 'rate limit') || str_contains($message, 'too many requests')) {
            return 'rate_limit';
        }

        if (str_contains($message, 'invalid model') || str_contains($message, 'model') && str_contains($message, 'not found')) {
            return 'invalid_model';
        }

        return 'provider_error';
    }

    private function redact(string $message): string
    {
        return preg_replace('/(sk-[a-zA-Z0-9_-]{8,}|AIza[a-zA-Z0-9_-]{8,})/', '[redacted]', $message) ?? $message;
    }
}

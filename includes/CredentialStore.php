<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

final class CredentialStore
{
    private const OPTION_NAME = 'wp_pfagent_credentials_v1';

    public function __construct(private readonly ProviderPresets $presets)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function statuses(): array
    {
        $catalog = $this->presets->catalog();
        if ($catalog instanceof WP_Error) {
            return [];
        }

        $stored = $this->records();
        $statuses = [];

        foreach ($catalog['presets'] as $provider_id => $preset) {
            if (!is_array($preset)) {
                continue;
            }

            $record = is_array($stored[$provider_id] ?? null) ? $stored[$provider_id] : null;
            $statuses[] = $this->status_for((string) $provider_id, $preset, $record);
        }

        return $statuses;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function status(string $provider_id)
    {
        $provider_id = sanitize_key($provider_id);
        $preset = $this->presets->preset($provider_id);
        if ($preset === null) {
            return new WP_Error('pfa_provider_unknown', __('Provider preset was not found.', 'wp-pfagent'), ['status' => 404]);
        }

        $records = $this->records();
        $record = is_array($records[$provider_id] ?? null) ? $records[$provider_id] : null;

        return $this->status_for($provider_id, $preset, $record);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function save(string $provider_id, string $api_key, array $settings = [])
    {
        $provider_id = sanitize_key($provider_id);
        $preset = $this->presets->preset($provider_id);
        if ($preset === null) {
            return new WP_Error('pfa_provider_unknown', __('Provider preset was not found.', 'wp-pfagent'), ['status' => 404]);
        }

        $api_key = trim($api_key);
        if ($api_key === '' || strlen($api_key) < 8) {
            return new WP_Error('pfa_api_key_invalid', __('API key is required and must be at least 8 characters.', 'wp-pfagent'), ['status' => 400]);
        }

        if (!$this->can_encrypt()) {
            return new WP_Error('pfa_crypto_unavailable', __('Credential encryption is not available on this WordPress runtime.', 'wp-pfagent'), ['status' => 500]);
        }

        $encrypted = $this->encrypt($api_key);
        if ($encrypted instanceof WP_Error) {
            return $encrypted;
        }

        $records = $this->records();
        $now = gmdate('c');
        $existing = is_array($records[$provider_id] ?? null) ? $records[$provider_id] : [];

        $record = [
            'providerId' => $provider_id,
            'family' => (string) ($preset['family'] ?? ''),
            'secret' => $encrypted,
            'maskedKey' => $this->mask_secret($api_key),
            'settings' => $this->sanitize_settings($settings),
            'createdAt' => (string) ($existing['createdAt'] ?? $now),
            'updatedAt' => $now,
            'validation' => [
                'status' => 'configured_unvalidated',
                'checkedAt' => null,
                'message' => __('Credential stored but connection has not been validated.', 'wp-pfagent'),
            ],
        ];

        $records[$provider_id] = $record;
        update_option(self::OPTION_NAME, $records, false);

        return $this->status_for($provider_id, $preset, $record);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function delete(string $provider_id)
    {
        $provider_id = sanitize_key($provider_id);
        $preset = $this->presets->preset($provider_id);
        if ($preset === null) {
            return new WP_Error('pfa_provider_unknown', __('Provider preset was not found.', 'wp-pfagent'), ['status' => 404]);
        }

        $records = $this->records();
        unset($records[$provider_id]);
        update_option(self::OPTION_NAME, $records, false);

        return $this->status_for($provider_id, $preset, null);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function test_connection(string $provider_id)
    {
        $provider_id = sanitize_key($provider_id);
        $preset = $this->presets->preset($provider_id);
        if ($preset === null) {
            return new WP_Error('pfa_provider_unknown', __('Provider preset was not found.', 'wp-pfagent'), ['status' => 404]);
        }

        $records = $this->records();
        $record = is_array($records[$provider_id] ?? null) ? $records[$provider_id] : null;
        if ($record === null) {
            return new WP_Error('pfa_provider_not_configured', __('Provider is not configured.', 'wp-pfagent'), ['status' => 400]);
        }

        $api_key = $this->decrypt(is_array($record['secret'] ?? null) ? $record['secret'] : []);
        if ($api_key instanceof WP_Error) {
            return $api_key;
        }

        $family = $this->presets->family((string) ($preset['family'] ?? ''));
        if ($family === null) {
            return new WP_Error('pfa_provider_family_unsupported', __('Provider family does not support connection testing yet.', 'wp-pfagent'), ['status' => 400]);
        }

        $request = $this->connection_test_request($preset, $family, $api_key, is_array($record['settings'] ?? null) ? $record['settings'] : []);
        if ($request instanceof WP_Error) {
            return $request;
        }

        $response = wp_remote_get($request['url'], [
            'timeout' => 8,
            'redirection' => 1,
            'headers' => $request['headers'],
        ]);

        $now = gmdate('c');
        if (is_wp_error($response)) {
            $record['validation'] = [
                'status' => 'validation_failed',
                'checkedAt' => $now,
                'message' => sprintf(
                    /* translators: %s: redacted underlying error message */
                    __('Connection failed: %s', 'wp-pfagent'),
                    $this->redact_message($response->get_error_message(), $api_key)
                ),
            ];
        } else {
            $status_code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            $has_models = is_array($decoded) && (is_array($decoded['data'] ?? null) || is_array($decoded['models'] ?? null));

            if ($status_code >= 200 && $status_code < 300 && $has_models) {
                $record['validation'] = [
                    'status' => 'validated',
                    'checkedAt' => $now,
                    'message' => __('Connection validated through provider model discovery.', 'wp-pfagent'),
                ];
            } else {
                $record['validation'] = [
                    'status' => 'validation_failed',
                    'checkedAt' => $now,
                    'message' => sprintf(
                        /* translators: %d: HTTP status code */
                        __('Connection test returned HTTP %d without a valid models payload.', 'wp-pfagent'),
                        $status_code
                    ),
                ];
            }
        }

        $record['updatedAt'] = $now;
        $records[$provider_id] = $record;
        update_option(self::OPTION_NAME, $records, false);

        return $this->status_for($provider_id, $preset, $record);
    }

    public static function raw_option_value(): mixed
    {
        $records = get_option(self::OPTION_NAME, []);
        if (is_array($records) && $records !== []) {
            return $records;
        }

        return get_option(self::legacy_option_name(), []);
    }

    /**
     * Internal-only runtime context. Do not expose this payload through REST.
     *
     * @return array{providerId: string, preset: array<string, mixed>, family: array<string, mixed>, apiKey: string, settings: array<string, string>, status: array<string, mixed>}|WP_Error
     */
    public function runtime_context(string $provider_id)
    {
        $provider_id = sanitize_key($provider_id);
        $preset = $this->presets->preset($provider_id);
        if ($preset === null) {
            return new WP_Error('pfa_provider_unknown', __('Provider preset was not found.', 'wp-pfagent'), ['status' => 404]);
        }

        $records = $this->records();
        $record = is_array($records[$provider_id] ?? null) ? $records[$provider_id] : null;
        if ($record === null) {
            return new WP_Error('pfa_provider_not_configured', __('Provider is not configured.', 'wp-pfagent'), ['status' => 400]);
        }

        $family = $this->presets->family((string) ($preset['family'] ?? ''));
        if ($family === null) {
            return new WP_Error('pfa_provider_family_unsupported', __('Provider family is not supported for runtime calls.', 'wp-pfagent'), ['status' => 400]);
        }

        $api_key = $this->decrypt(is_array($record['secret'] ?? null) ? $record['secret'] : []);
        if ($api_key instanceof WP_Error) {
            return $api_key;
        }

        return [
            'providerId' => $provider_id,
            'preset' => $preset,
            'family' => $family,
            'apiKey' => $api_key,
            'settings' => is_array($record['settings'] ?? null) ? $record['settings'] : [],
            'status' => $this->status_for($provider_id, $preset, $record),
        ];
    }

    /**
     * Persist the user-confirmed per-model configuration for a provider.
     *
     * `$models` mirrors what the wizard collected: discovery data from the
     * provider's API (caps, defaults, capability flags) merged with what
     * the user filled in by hand (pricing always; caps when the provider's
     * /v1/models is sparse). This is the source of truth that runtime
     * gateways read instead of consulting a static repo-shipped catalog.
     *
     * The provider's API key + other settings are NOT touched here — this
     * call only replaces the `settings.models[]` slot.
     *
     * @param array<int|string, mixed> $models
     * @return array<string, mixed>|WP_Error
     */
    public function save_models(string $provider_id, array $models)
    {
        $provider_id = sanitize_key($provider_id);
        $preset = $this->presets->preset($provider_id);
        if ($preset === null) {
            return new WP_Error('pfa_provider_unknown', __('Provider preset was not found.', 'wp-pfagent'), ['status' => 404]);
        }

        $records = $this->records();
        $record = is_array($records[$provider_id] ?? null) ? $records[$provider_id] : null;
        if ($record === null) {
            return new WP_Error('pfa_provider_not_configured', __('Provider must be configured before saving models.', 'wp-pfagent'), ['status' => 400]);
        }

        $settings = is_array($record['settings'] ?? null) ? $record['settings'] : [];
        $settings['models'] = $this->sanitize_models_setting($models);

        $record['settings'] = $settings;
        $record['updatedAt'] = gmdate('c');
        $records[$provider_id] = $record;
        update_option(self::OPTION_NAME, $records, false);

        return $this->status_for($provider_id, $preset, $record);
    }

    /**
     * Look up a single confirmed model record for a provider. Returns null
     * when the credential isn't configured or the model id is not in the
     * user-saved list. Callers (gateways) use this to read caps/pricing/
     * features at runtime.
     *
     * @return array<string, mixed>|null
     */
    public function model(string $provider_id, string $model_id): ?array
    {
        $provider_id = sanitize_key($provider_id);
        $model_id = trim($model_id);
        if ($provider_id === '' || $model_id === '') {
            return null;
        }

        $records = $this->records();
        $record = is_array($records[$provider_id] ?? null) ? $records[$provider_id] : null;
        if ($record === null) {
            return null;
        }

        $settings = is_array($record['settings'] ?? null) ? $record['settings'] : [];
        $models = is_array($settings['models'] ?? null) ? $settings['models'] : [];

        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            if (($model['id'] ?? null) === $model_id) {
                return $model;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $validation
     * @return array<string, mixed>|WP_Error
     */
    public function update_validation(string $provider_id, array $validation)
    {
        $provider_id = sanitize_key($provider_id);
        $preset = $this->presets->preset($provider_id);
        if ($preset === null) {
            return new WP_Error('pfa_provider_unknown', __('Provider preset was not found.', 'wp-pfagent'), ['status' => 404]);
        }

        $records = $this->records();
        $record = is_array($records[$provider_id] ?? null) ? $records[$provider_id] : null;
        if ($record === null) {
            return new WP_Error('pfa_provider_not_configured', __('Provider is not configured.', 'wp-pfagent'), ['status' => 400]);
        }

        $record['validation'] = [
            'status' => sanitize_key((string) ($validation['status'] ?? 'validation_failed')),
            'checkedAt' => (string) ($validation['checkedAt'] ?? gmdate('c')),
            'message' => sanitize_text_field((string) ($validation['message'] ?? __('Connection validation updated.', 'wp-pfagent'))),
            'errorType' => isset($validation['errorType']) ? sanitize_key((string) $validation['errorType']) : null,
            'httpStatus' => isset($validation['httpStatus']) ? (int) $validation['httpStatus'] : null,
        ];
        $record['updatedAt'] = gmdate('c');
        $records[$provider_id] = $record;
        update_option(self::OPTION_NAME, $records, false);

        return $this->status_for($provider_id, $preset, $record);
    }

    /**
     * @return array<string, mixed>
     */
    private function status_for(string $provider_id, array $preset, ?array $record): array
    {
        $validation = is_array($record['validation'] ?? null) ? $record['validation'] : [];
        $validation_status = (string) ($validation['status'] ?? 'not_configured');
        $status = $record === null ? 'not_configured' : $validation_status;

        // settings on the wire keeps flat scalar keys (base_url, region, etc.)
        // The per-model configuration the user confirmed in the wizard is
        // lifted out into a dedicated `models` field on the status so the
        // frontend doesn't have to inspect settings as a mixed bag.
        $settings = $record === null ? [] : (is_array($record['settings'] ?? null) ? $record['settings'] : []);
        $models = is_array($settings['models'] ?? null) ? array_values($settings['models']) : [];
        unset($settings['models']);

        return [
            'providerId' => $provider_id,
            'label' => (string) ($preset['label'] ?? $provider_id),
            'family' => (string) ($preset['family'] ?? ''),
            'status' => $status,
            'configured' => $record !== null,
            'maskedKey' => $record === null ? null : (string) ($record['maskedKey'] ?? 'stored'),
            'settings' => $record === null ? new \stdClass() : (object) $settings,
            'models' => $models,
            'configuredAt' => $record === null ? null : (string) ($record['createdAt'] ?? ''),
            'updatedAt' => $record === null ? null : (string) ($record['updatedAt'] ?? ''),
            'validatedAt' => $record === null ? null : ($validation['checkedAt'] ?? null),
            'validationMessage' => $record === null ? __('Provider is not configured.', 'wp-pfagent') : (string) ($validation['message'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function records(): array
    {
        $records = get_option(self::OPTION_NAME, []);
        if (is_array($records) && $records !== []) {
            return $records;
        }

        $legacy_records = get_option(self::legacy_option_name(), []);
        if (is_array($legacy_records) && $legacy_records !== []) {
            update_option(self::OPTION_NAME, $legacy_records, false);

            return $legacy_records;
        }

        return is_array($records) ? $records : [];
    }

    private function can_encrypt(): bool
    {
        return function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
    }

    /**
     * @return array<string, string>|WP_Error
     */
    private function encrypt(string $plain_text)
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain_text, 'aes-256-gcm', $this->encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || $tag === '') {
            return new WP_Error('pfa_crypto_failed', __('Credential encryption failed.', 'wp-pfagent'), ['status' => 500]);
        }

        return [
            'cipher' => 'aes-256-gcm',
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'value' => base64_encode($cipher),
        ];
    }

    /**
     * @param array<string, mixed> $encrypted
     */
    private function decrypt(array $encrypted): string|WP_Error
    {
        if (($encrypted['cipher'] ?? '') !== 'aes-256-gcm') {
            return new WP_Error('pfa_crypto_invalid', __('Stored credential cipher is unsupported.', 'wp-pfagent'), ['status' => 500]);
        }

        $iv = base64_decode((string) ($encrypted['iv'] ?? ''), true);
        $tag = base64_decode((string) ($encrypted['tag'] ?? ''), true);
        $value = base64_decode((string) ($encrypted['value'] ?? ''), true);
        if ($iv === false || $tag === false || $value === false) {
            return new WP_Error('pfa_crypto_invalid', __('Stored credential payload is invalid.', 'wp-pfagent'), ['status' => 500]);
        }

        foreach ([$this->encryption_key(), $this->legacy_encryption_key()] as $key) {
            $plain_text = openssl_decrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain_text !== false) {
                return $plain_text;
            }
        }

        return new WP_Error('pfa_crypto_failed', __('Credential decryption failed.', 'wp-pfagent'), ['status' => 500]);
    }

    private function encryption_key(): string
    {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') .
            '|' . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '') .
            '|' . (defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '') .
            '|wp-pfagent-credentials';

        return hash('sha256', $material, true);
    }

    private function legacy_encryption_key(): string
    {
        $legacy_suffix = 'projectflash' . '-agent-credentials';
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') .
            '|' . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '') .
            '|' . (defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '') .
            '|' . $legacy_suffix;

        return hash('sha256', $material, true);
    }

    private static function legacy_option_name(): string
    {
        return 'projectflash' . '_agent_credentials_v1';
    }

    private function mask_secret(string $secret): string
    {
        $suffix = substr($secret, -4);

        return '****' . $suffix;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function sanitize_settings(array $settings): array
    {
        $sanitized = [];
        foreach ($settings as $key => $value) {
            if (!is_string($key) || str_contains(strtolower($key), 'key') || str_contains(strtolower($key), 'secret')) {
                continue;
            }

            $key_clean = sanitize_key($key);
            if ($key_clean === 'models') {
                // Models is a typed nested structure (one record per model
                // selected/confirmed by the user in the wizard). Sanitised
                // by its own helper to preserve numeric caps + pricing +
                // boolean feature flags. Everything else gets the flat
                // scalar treatment so config-shaped keys (base_url, region,
                // anthropic_version) keep working.
                if (is_array($value)) {
                    $sanitized['models'] = $this->sanitize_models_setting($value);
                }
                continue;
            }

            if (is_scalar($value)) {
                $sanitized[$key_clean] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * Sanitise the credential's `settings.models[]` array — the per-credential
     * record of confirmed-by-the-user model configuration. Anything not in the
     * schema is dropped silently.
     *
     * @param array<int|string, mixed> $models
     * @return list<array<string, mixed>>
     */
    private function sanitize_models_setting(array $models): array
    {
        $out = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $clean = $this->sanitize_model_record($model);
            if ($clean !== null) {
                $out[] = $clean;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, mixed>|null
     */
    private function sanitize_model_record(array $model): ?array
    {
        $id = isset($model['id']) && is_scalar($model['id']) ? trim(sanitize_text_field((string) $model['id'])) : '';
        if ($id === '') {
            return null;
        }

        $record = ['id' => $id];

        if (isset($model['label']) && is_scalar($model['label'])) {
            $label = sanitize_text_field((string) $model['label']);
            $record['label'] = $label !== '' ? $label : $id;
        } else {
            $record['label'] = $id;
        }

        $string_fields = ['family', 'source', 'description', 'version', 'ownedBy', 'createdAt', 'defaultReasoningEffort'];
        foreach ($string_fields as $field) {
            if (isset($model[$field]) && is_scalar($model[$field])) {
                $sanitised = sanitize_text_field((string) $model[$field]);
                if ($sanitised !== '') {
                    $record[$field] = $sanitised;
                }
            }
        }

        $int_fields = ['contextLength', 'maxOutputTokens'];
        foreach ($int_fields as $field) {
            if (isset($model[$field]) && is_numeric($model[$field])) {
                $value = (int) $model[$field];
                if ($value > 0) {
                    $record[$field] = $value;
                }
            }
        }

        if (isset($model['features']) && is_array($model['features'])) {
            $features = [];
            foreach ($model['features'] as $flag => $on) {
                if (is_string($flag) && $flag !== '') {
                    $features[sanitize_key($flag)] = (bool) $on;
                }
            }
            if ($features !== []) {
                $record['features'] = $features;
            }
        }

        if (isset($model['defaults']) && is_array($model['defaults'])) {
            $defaults = [];
            foreach (['temperature', 'topP', 'topK', 'maxTemperature'] as $field) {
                if (isset($model['defaults'][$field]) && is_numeric($model['defaults'][$field])) {
                    $defaults[$field] = $model['defaults'][$field] + 0; // coerce int|float
                }
            }
            if ($defaults !== []) {
                $record['defaults'] = $defaults;
            }
        }

        if (isset($model['reasoningVariants']) && is_array($model['reasoningVariants'])) {
            $variants = [];
            foreach ($model['reasoningVariants'] as $variant) {
                if (is_string($variant) && $variant !== '') {
                    $clean = sanitize_key($variant);
                    if ($clean !== '') {
                        $variants[] = $clean;
                    }
                }
            }
            if ($variants !== []) {
                $record['reasoningVariants'] = array_values(array_unique($variants));
            }
        }

        if (isset($model['pricing']) && is_array($model['pricing'])) {
            $pricing = $this->sanitize_pricing_setting($model['pricing']);
            if ($pricing !== []) {
                $record['pricing'] = $pricing;
            }
        }

        if (isset($model['minCacheTokens']) && is_numeric($model['minCacheTokens'])) {
            $value = (int) $model['minCacheTokens'];
            if ($value > 0) {
                $record['minCacheTokens'] = $value;
            }
        }

        return $record;
    }

    /**
     * @param array<int|string, mixed> $pricing
     * @return array<string, mixed>
     */
    private function sanitize_pricing_setting(array $pricing): array
    {
        $out = [];
        foreach (['input', 'output', 'cacheRead', 'cacheWrite'] as $field) {
            if (isset($pricing[$field]) && is_numeric($pricing[$field])) {
                $value = $pricing[$field] + 0;
                if ($value >= 0) {
                    $out[$field] = $value;
                }
            }
        }

        if (isset($pricing['tiers']) && is_array($pricing['tiers'])) {
            $tiers = [];
            foreach ($pricing['tiers'] as $tier) {
                if (!is_array($tier)) {
                    continue;
                }
                $tier_clean = [];
                foreach (['inputUpTo', 'input', 'output', 'cacheRead', 'cacheWrite'] as $field) {
                    if (isset($tier[$field]) && is_numeric($tier[$field])) {
                        $tier_clean[$field] = $tier[$field] + 0;
                    }
                }
                if (!empty($tier_clean['inputUpTo'])) {
                    $tiers[] = $tier_clean;
                }
            }
            if ($tiers !== []) {
                $out['tiers'] = $tiers;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $preset
     * @param array<string, mixed> $family
     * @param array<string, string> $settings
     * @return array{url: string, headers: array<string, string>}|WP_Error
     */
    private function connection_test_request(array $preset, array $family, string $api_key, array $settings)
    {
        $endpoints = is_array($family['endpoints'] ?? null) ? $family['endpoints'] : [];
        $models_endpoint = (string) ($endpoints['models'] ?? '');
        if ($models_endpoint === '') {
            return new WP_Error('pfa_provider_models_unsupported', __('Provider family does not expose a models endpoint for connection testing.', 'wp-pfagent'), ['status' => 400]);
        }

        $base_url = $this->resolve_template((string) ($preset['baseUrl'] ?? ''), $settings);
        if ($base_url instanceof WP_Error) {
            return $base_url;
        }

        $headers = [];
        $default_headers = is_array($family['defaultHeaders'] ?? null) ? $family['defaultHeaders'] : [];
        $defaults = is_array($family['defaults'] ?? null) ? $family['defaults'] : [];
        $values = array_merge($defaults, $settings, ['api_key' => $api_key]);

        foreach ($default_headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                continue;
            }

            $headers[$name] = $this->replace_placeholders($value, $values);
        }

        return [
            'url' => rtrim($base_url, '/') . '/' . ltrim($models_endpoint, '/'),
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, string> $settings
     */
    private function resolve_template(string $template, array $settings): string|WP_Error
    {
        $resolved = $this->replace_placeholders($template, $settings);
        if (preg_match('/{{\s*[^}]+\s*}}/', $resolved)) {
            return new WP_Error('pfa_provider_settings_missing', __('Provider requires additional settings before testing connection.', 'wp-pfagent'), ['status' => 400]);
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

    private function redact_message(string $message, string $secret): string
    {
        if ($secret === '') {
            return $message;
        }

        return str_replace($secret, '[redacted]', $message);
    }
}

<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

final class ProviderHealth
{
    public function __construct(
        private readonly ProviderModelDiscovery $models,
        private readonly CredentialStore $credentials,
        private readonly ProviderPresets $presets
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function check(string $provider_id): array
    {
        $provider_id = sanitize_key($provider_id);
        $preset = $this->presets->preset($provider_id);
        $checked_at = gmdate('c');

        if ($preset === null) {
            return [
                'providerId' => $provider_id,
                'label' => $provider_id,
                'family' => '',
                'status' => 'failed',
                'credentialStatus' => 'validation_failed',
                'errorType' => 'configuration',
                'httpStatus' => 404,
                'checkedAt' => $checked_at,
                'message' => __('Provider preset was not found.', 'wp-pfagent'),
                'modelsAvailable' => 0,
                'discoverySource' => null,
            ];
        }

        $result = $this->models->discover($provider_id, true);
        if ($result instanceof WP_Error) {
            $error = $this->normalize_error($result);
            $message = $error['message'];
            $this->credentials->update_validation($provider_id, [
                'status' => 'validation_failed',
                'checkedAt' => $checked_at,
                'message' => $message,
                'errorType' => $error['errorType'],
                'httpStatus' => $error['httpStatus'],
            ]);

            return [
                'providerId' => $provider_id,
                'label' => (string) ($preset['label'] ?? $provider_id),
                'family' => (string) ($preset['family'] ?? ''),
                'status' => 'failed',
                'credentialStatus' => 'validation_failed',
                'errorType' => $error['errorType'],
                'httpStatus' => $error['httpStatus'],
                'checkedAt' => $checked_at,
                'message' => $message,
                'modelsAvailable' => 0,
                'discoverySource' => null,
            ];
        }

        $model_count = count(is_array($result['models'] ?? null) ? $result['models'] : []);
        $message = 'Connection validated through provider model discovery. Models available: ' . (string) $model_count . '.';
        $this->credentials->update_validation($provider_id, [
            'status' => 'validated',
            'checkedAt' => $checked_at,
            'message' => $message,
            'errorType' => null,
            'httpStatus' => 200,
        ]);

        return [
            'providerId' => $provider_id,
            'label' => (string) ($preset['label'] ?? $provider_id),
            'family' => (string) ($preset['family'] ?? ''),
            'status' => 'connected',
            'credentialStatus' => 'validated',
            'errorType' => null,
            'httpStatus' => 200,
            'checkedAt' => $checked_at,
            'message' => $message,
            'modelsAvailable' => $model_count,
            'discoverySource' => (string) ($result['source'] ?? 'api'),
        ];
    }

    /**
     * @return array{errorType: string, httpStatus: int, message: string}
     */
    private function normalize_error(WP_Error $error): array
    {
        $data = $error->get_error_data();
        $data = is_array($data) ? $data : [];
        $http_status = (int) ($data['providerStatus'] ?? $data['httpStatus'] ?? $data['status'] ?? 0);
        $error_type = (string) ($data['errorType'] ?? '');
        $message = $error->get_error_message();

        if ($error_type === '') {
            $error_type = $this->classify($http_status, $message);
        }

        return [
            'errorType' => $error_type,
            'httpStatus' => $http_status,
            'message' => $message,
        ];
    }

    private function classify(int $http_status, string $message): string
    {
        $normalized = strtolower($message);
        if ($http_status === 401 || $http_status === 403) {
            return 'auth';
        }

        if ($http_status === 402 || str_contains($normalized, 'quota') || str_contains($normalized, 'insufficient') || str_contains($normalized, 'balance')) {
            return 'quota';
        }

        if ($http_status === 429 || str_contains($normalized, 'rate limit') || str_contains($normalized, 'too many requests')) {
            return 'rate_limit';
        }

        if ($http_status === 0 || str_contains($normalized, 'network')) {
            return 'network';
        }

        if (str_contains($normalized, 'invalid model') || str_contains($normalized, 'model not found')) {
            return 'invalid_model';
        }

        if (str_contains($normalized, 'setting') || str_contains($normalized, 'base url')) {
            return 'configuration';
        }

        return 'provider_error';
    }
}

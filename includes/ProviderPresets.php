<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

final class ProviderPresets
{
    /**
     * @return array<string, mixed>|WP_Error
     */
    public function catalog()
    {
        $path = WP_PFAGENT_DIR . 'config/provider-presets.json';
        if (!file_exists($path)) {
            return new WP_Error('pfa_provider_presets_missing', __('Provider presets file is missing.', 'wp-pfagent'), ['status' => 500]);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return new WP_Error('pfa_provider_presets_invalid_json', __('Provider presets file is not valid JSON.', 'wp-pfagent'), ['status' => 500]);
        }

        $validation_error = $this->validate_catalog($decoded);
        if ($validation_error instanceof WP_Error) {
            return $validation_error;
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function preset(string $provider_id): ?array
    {
        $catalog = $this->catalog();
        if ($catalog instanceof WP_Error) {
            return null;
        }

        $preset = $catalog['presets'][$provider_id] ?? null;

        return is_array($preset) ? $preset : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function family(string $family_id): ?array
    {
        $catalog = $this->catalog();
        if ($catalog instanceof WP_Error) {
            return null;
        }

        $family = $catalog['families'][$family_id] ?? null;

        return is_array($family) ? $family : null;
    }

    /**
     * @param array<string, mixed> $catalog
     */
    private function validate_catalog(array $catalog): ?WP_Error
    {
        if (($catalog['schemaVersion'] ?? null) !== 1) {
            return new WP_Error('pfa_provider_presets_schema', __('Provider presets schemaVersion must be 1.', 'wp-pfagent'), ['status' => 500]);
        }

        if (!is_array($catalog['families'] ?? null) || !is_array($catalog['presets'] ?? null)) {
            return new WP_Error('pfa_provider_presets_shape', __('Provider presets must define families and presets.', 'wp-pfagent'), ['status' => 500]);
        }

        foreach ($catalog['presets'] as $id => $preset) {
            if (!is_string($id) || !is_array($preset)) {
                return new WP_Error('pfa_provider_presets_shape', __('Provider preset entries must be keyed objects.', 'wp-pfagent'), ['status' => 500]);
            }

            if (!is_string($preset['label'] ?? null) || !is_string($preset['family'] ?? null) || !is_string($preset['baseUrl'] ?? null)) {
                return new WP_Error('pfa_provider_presets_shape', __('Provider presets require label, family and baseUrl.', 'wp-pfagent'), ['status' => 500]);
            }

            if (!array_key_exists($preset['family'], $catalog['families']) && $preset['family'] !== 'custom') {
                return new WP_Error('pfa_provider_presets_family', __('Provider preset references an unknown family.', 'wp-pfagent'), ['status' => 500]);
            }

            if (array_key_exists('defaultModels', $preset)) {
                return new WP_Error('pfa_provider_presets_models', __('Provider presets must not define defaultModels.', 'wp-pfagent'), ['status' => 500]);
            }

            if (is_array($preset['modelHints'] ?? null) && $preset['modelHints'] !== []) {
                return new WP_Error('pfa_provider_presets_models', __('Provider modelHints must remain non-authoritative and empty; real models come from discovery, cache or explicit manual entry.', 'wp-pfagent'), ['status' => 500]);
            }
        }

        return null;
    }
}

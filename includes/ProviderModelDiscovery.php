<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

final class ProviderModelDiscovery
{
    private const CACHE_OPTION = 'wp_pfagent_model_cache_v1';
    private const FAILURE_OPTION = 'wp_pfagent_model_failure_v1';
    private const TTL_SECONDS = 600;
    /**
     * How long a remembered failure suppresses the next API call. Short
     * enough that the user retrying after fixing their key or waiting out
     * a rate limit gets through, long enough that a tight loop doesn't
     * hammer the provider while it's still broken.
     */
    private const FAILURE_TTL_SECONDS = 60;

    /**
     * Per-process result cache: dedupes repeated discover() calls inside
     * a single HTTP request (e.g. when several REST handlers all need
     * the catalog). Kilo Tier 1.12 in-flight dedup. PHP is single-threaded
     * per request so there's no real "in-flight Promise" — a result map
     * is the equivalent.
     *
     * @var array<string, array<string, mixed>|WP_Error>
     */
    private array $resultsInRequest = [];

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly ProviderPresets $presets
    ) {
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function discover(string $provider_id, bool $force_refresh = false)
    {
        $provider_id = sanitize_key($provider_id);
        $preset = $this->presets->preset($provider_id);
        if ($preset === null) {
            return new WP_Error('pfa_provider_unknown', __('Provider preset was not found.', 'wp-pfagent'), ['status' => 404]);
        }

        // Per-REQUEST memo: dedupe repeated discover() calls within a single HTTP
        // request. It is intentionally NOT cross-request — each request is a fresh
        // PHP process, so the persisted option cache (cached_catalog) is what makes
        // a later request report source='cache'. Tests that want cache-read
        // semantics must use a fresh instance, not reuse this one in-process.
        if (!$force_refresh && isset($this->resultsInRequest[$provider_id])) {
            return $this->resultsInRequest[$provider_id];
        }

        if (!$force_refresh) {
            $cached = $this->cached_catalog($provider_id, $preset);
            if ($cached !== null) {
                return $this->resultsInRequest[$provider_id] = $cached;
            }
            $failure = $this->cached_failure($provider_id);
            if ($failure !== null) {
                return $this->resultsInRequest[$provider_id] = $failure;
            }
        }

        $model_discovery = (string) ($preset['modelDiscovery'] ?? 'api');
        if (!$this->allows_api_discovery($model_discovery)) {
            $err = new WP_Error('pfa_provider_model_discovery_manual_required', __('Provider does not expose API model discovery. Manual model entries are required for this preset.', 'wp-pfagent'),
                ['status' => 400, 'errorType' => 'provider_specific']
            );
            return $this->resultsInRequest[$provider_id] = $err;
        }

        $context = $this->credentials->runtime_context($provider_id);
        if ($context instanceof WP_Error) {
            return $this->resultsInRequest[$provider_id] = $context;
        }

        $endpoints = $this->resolve_discovery_endpoints($context, $preset);
        if ($endpoints === []) {
            $err = new WP_Error('pfa_provider_no_discovery_endpoints', __('Provider family declares no discovery endpoints.', 'wp-pfagent'), ['status' => 500, 'errorType' => 'configuration']);
            return $this->resultsInRequest[$provider_id] = $err;
        }

        $models_by_id = [];
        $metadata = [];
        $primary_error = null;
        foreach ($endpoints as $endpoint) {
            $url = (string) ($endpoint['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $response = wp_remote_get($url, [
                'timeout' => 15,
                'redirection' => 1,
                'headers' => is_array($endpoint['headers'] ?? null) ? $endpoint['headers'] : [],
            ]);
            $decoded = $this->decode_response($response, 'discovery (' . (string) ($endpoint['shape'] ?? 'unknown') . ')');
            if ($decoded instanceof WP_Error) {
                // Family-level endpoints (no `optional` flag) are required —
                // first hard failure aborts. Extra preset endpoints are
                // optional: their failures are recorded as metadata but the
                // probe continues.
                if (!empty($endpoint['optional'])) {
                    $metadata['warnings'][] = [
                        'shape' => $endpoint['shape'] ?? null,
                        'message' => (string) $decoded->get_error_message(),
                    ];
                    continue;
                }
                $primary_error = $decoded;
                break;
            }
            $shape = (string) ($endpoint['shape'] ?? '');
            $this->apply_shape(
                $shape,
                $decoded,
                $endpoint,
                (string) ($context['preset']['family'] ?? ''),
                $models_by_id,
                $metadata
            );
            // Pagination support: shapes that return a cursor/page count keep
            // probing the next page until exhausted.
            $next_endpoint = $this->next_paginated_endpoint($endpoint, $decoded, $shape);
            while ($next_endpoint !== null) {
                $response = wp_remote_get((string) $next_endpoint['url'], [
                    'timeout' => 15,
                    'redirection' => 1,
                    'headers' => is_array($next_endpoint['headers'] ?? null) ? $next_endpoint['headers'] : [],
                ]);
                $decoded = $this->decode_response($response, 'discovery page (' . $shape . ')');
                if ($decoded instanceof WP_Error) {
                    $metadata['warnings'][] = [
                        'shape' => $shape,
                        'message' => 'pagination stopped: ' . $decoded->get_error_message(),
                    ];
                    break;
                }
                $this->apply_shape($shape, $decoded, $next_endpoint, (string) ($context['preset']['family'] ?? ''), $models_by_id, $metadata);
                $next_endpoint = $this->next_paginated_endpoint($next_endpoint, $decoded, $shape);
            }
        }

        if ($primary_error instanceof WP_Error) {
            $this->store_failure($provider_id, $primary_error);
            return $this->resultsInRequest[$provider_id] = $primary_error;
        }

        if ($models_by_id === []) {
            $err = new WP_Error('pfa_provider_no_models', __('Provider model discovery returned no models.', 'wp-pfagent'), ['status' => 502, 'errorType' => 'invalid_response']);
            $this->store_failure($provider_id, $err);
            return $this->resultsInRequest[$provider_id] = $err;
        }

        $catalog = $this->catalog_payload($provider_id, $preset, array_values($models_by_id), 'api', gmdate('c'), $this->expires_at());
        if ($metadata !== []) {
            $catalog['metadata'] = $metadata;
        }
        $this->store_cache($provider_id, $catalog);
        $this->clear_failure($provider_id);

        return $this->resultsInRequest[$provider_id] = $catalog;
    }

    /**
     * Build the flat list of discovery endpoints to probe for this provider:
     *
     *   - family.discovery[] paths resolved against the preset's baseUrl,
     *   - preset.extraDiscoveryEndpoints[] taken verbatim (absolute URLs).
     *
     * Each entry is annotated with the shape extractor that should handle
     * its response, the headers to send, and a flag marking it as optional
     * (extras don't abort discovery if they fail).
     *
     * @param array<string, mixed> $context CredentialStore::runtime_context shape
     * @param array<string, mixed> $preset
     * @return list<array<string, mixed>>
     */
    private function resolve_discovery_endpoints(array $context, array $preset): array
    {
        $endpoints = [];
        $family = is_array($context['family'] ?? null) ? $context['family'] : [];

        // 1. Family-level discovery endpoints — required (failure aborts).
        //    When the family declares `versions: [...]` we probe each version
        //    in order. Sucess at any version produces a result; later versions
        //    additively merge into the model map (see merge_models).
        $request = $this->request_descriptor($context, 'models');
        if ($request instanceof WP_Error) {
            return [];
        }
        $base = rtrim((string) preg_replace('/\?.*$/', '', $request['url']), '/');
        $base = (string) preg_replace('#/models$#', '', $base);

        $versions = is_array($family['versions'] ?? null) ? array_values(array_filter($family['versions'], 'is_string')) : [];
        if ($versions === []) {
            // No versions declared → use the baseUrl as-is.
            $version_bases = [$base];
        } else {
            // Re-write the trailing /vN or /vNbeta segment with each
            // declared candidate. Order matters: the FIRST version is the
            // primary (its failure aborts); subsequent versions are optional
            // (their failures are warnings, their successes additively merge).
            $version_bases = [];
            foreach ($versions as $version) {
                $version_bases[] = (string) preg_replace('#/v\d+(?:beta)?$#', '/' . $version, $base);
            }
        }

        $first = true;
        foreach ($version_bases as $version_base) {
            foreach ((array) ($family['discovery'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $endpoints[] = [
                    'url' => $version_base . (string) ($entry['path'] ?? ''),
                    'shape' => (string) ($entry['shape'] ?? ''),
                    'headers' => $request['headers'],
                    'optional' => !$first,
                ];
            }
            $first = false;
        }

        // 2. Preset-level extras — optional (each failure is recorded but
        //    discovery continues). Used for native sibling APIs (Qwen
        //    DashScope native) or non-model metadata endpoints (DeepSeek
        //    /user/balance).
        $api_key = (string) ($context['apiKey'] ?? '');
        foreach ((array) ($preset['extraDiscoveryEndpoints'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $url = (string) ($entry['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $headers = ['Accept: application/json'];
            $auth_header = (string) ($entry['authHeader'] ?? '');
            $auth_prefix = (string) ($entry['authPrefix'] ?? '');
            if ($auth_header !== '' && $api_key !== '') {
                $headers[] = $auth_header . ': ' . $auth_prefix . $api_key;
            }
            // wp_remote_get accepts headers as an associative array, but the
            // request_descriptor branch already returns associative; keep this
            // branch associative too for consistency.
            $assoc = [];
            foreach ($headers as $line) {
                $colon = strpos($line, ':');
                if ($colon === false) continue;
                $assoc[trim(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
            }
            $endpoints[] = [
                'url' => $url,
                'shape' => (string) ($entry['shape'] ?? ''),
                'headers' => $assoc,
                'optional' => true,
                'paginate' => is_array($entry['paginate'] ?? null) ? $entry['paginate'] : null,
            ];
        }

        return $endpoints;
    }

    /**
     * Dispatch a decoded response to the matching shape extractor. Each
     * extractor either contributes model records (writing into $models_by_id)
     * or contributes catalog-level metadata (writing into $metadata).
     *
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $endpoint
     * @param array<string, array<string, mixed>> $models_by_id
     * @param array<string, mixed> $metadata
     */
    private function apply_shape(string $shape, array $decoded, array $endpoint, string $family, array &$models_by_id, array &$metadata): void
    {
        switch ($shape) {
            case 'openai_models_list':
                $this->merge_models($models_by_id, $this->extract_openai_models_list($decoded, $family));
                return;
            case 'anthropic_models_list':
                $this->merge_models($models_by_id, $this->extract_anthropic_models_list($decoded));
                return;
            case 'gemini_models_list':
                $this->merge_models($models_by_id, $this->extract_gemini_models_list($decoded));
                return;
            case 'dashscope_models_list':
                $this->merge_models($models_by_id, $this->extract_dashscope_models_list($decoded));
                return;
            case 'openai_balance':
                $metadata['balance'] = $this->extract_openai_balance($decoded);
                return;
            default:
                $metadata['warnings'][] = ['shape' => $shape, 'message' => 'unknown shape'];
        }
    }

    /**
     * Compute the next paginated endpoint when the current shape supports it
     * and the response indicates more pages.
     *
     * @param array<string, mixed> $endpoint
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>|null
     */
    private function next_paginated_endpoint(array $endpoint, array $decoded, string $shape): ?array
    {
        if ($shape !== 'dashscope_models_list') {
            return null;
        }
        $paginate = is_array($endpoint['paginate'] ?? null) ? $endpoint['paginate'] : null;
        if ($paginate === null) {
            return null;
        }
        $output = is_array($decoded['output'] ?? null) ? $decoded['output'] : [];
        $total = (int) ($output['total'] ?? 0);
        $page_size = (int) ($output['page_size'] ?? 0);
        $page_no = (int) ($output['page_no'] ?? 0);
        if ($total <= 0 || $page_size <= 0 || $page_no <= 0) {
            return null;
        }
        if ($page_no * $page_size >= $total) {
            return null;
        }
        $next_page = $page_no + 1;
        $param = (string) ($paginate['param'] ?? 'page_no');
        $page_size_param = (string) ($paginate['pageSize'] ?? 'page_size');
        $page_size_value = (int) ($paginate['pageSizeValue'] ?? $page_size);

        $url = (string) $endpoint['url'];
        $url = preg_replace('/[?&]' . preg_quote($param, '/') . '=\d+/', '', $url) ?? $url;
        $url = preg_replace('/[?&]' . preg_quote($page_size_param, '/') . '=\d+/', '', $url) ?? $url;
        $url = rtrim($url, '?&');
        $sep = str_contains($url, '?') ? '&' : '?';
        $url .= $sep . $param . '=' . $next_page . '&' . $page_size_param . '=' . $page_size_value;

        $next = $endpoint;
        $next['url'] = $url;
        return $next;
    }

    /**
     * Merge a batch of model records into the cumulative map keyed by id.
     * Non-empty fields in later records win — so a richer native-API record
     * overrides the sparse OpenAI-compat one for the same model id.
     *
     * @param array<string, array<string, mixed>> $accumulator
     * @param list<array<string, mixed>> $records
     */
    private function merge_models(array &$accumulator, array $records): void
    {
        foreach ($records as $record) {
            $id = (string) ($record['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!isset($accumulator[$id])) {
                $accumulator[$id] = $record;
                continue;
            }
            foreach ($record as $key => $value) {
                if ($value === null || $value === '' || $value === [] || $value === false) {
                    continue;
                }
                $accumulator[$id][$key] = $value;
            }
            // Merge source tags so callers can see this model was confirmed by
            // multiple endpoints (e.g. both openai-compat and dashscope-native).
            $accumulator[$id]['source'] = 'api';
        }
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private function extract_openai_models_list(array $decoded, string $family): array
    {
        $raw = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $out = [];
        foreach ($raw as $m) {
            if (!is_array($m)) continue;
            $id = (string) ($m['id'] ?? '');
            if ($id === '') continue;
            $out[] = $this->extract_model_record($family, $id, $m, 'api');
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private function extract_anthropic_models_list(array $decoded): array
    {
        $raw = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $out = [];
        foreach ($raw as $m) {
            if (!is_array($m)) continue;
            $id = (string) ($m['id'] ?? '');
            if ($id === '') continue;
            $out[] = $this->extract_model_record('anthropic-compatible', $id, $m, 'api');
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private function extract_gemini_models_list(array $decoded): array
    {
        $raw = is_array($decoded['models'] ?? null) ? $decoded['models'] : [];
        $out = [];
        foreach ($raw as $m) {
            if (!is_array($m)) continue;
            $id = $this->model_id('gemini-compatible', $m);
            if ($id === '') continue;
            $out[] = $this->extract_model_record('gemini-compatible', $id, $m, 'api');
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private function extract_dashscope_models_list(array $decoded): array
    {
        $output = is_array($decoded['output'] ?? null) ? $decoded['output'] : [];
        $raw = is_array($output['models'] ?? null) ? $output['models'] : [];
        $out = [];
        foreach ($raw as $m) {
            if (!is_array($m)) continue;
            $id = trim((string) ($m['model'] ?? ''));
            if ($id === '') continue;

            $record = [
                'id' => $id,
                'label' => (string) ($m['name'] ?? $id),
                'source' => 'api',
                'family' => 'openai-compatible',
                'capabilities' => is_array($m['capabilities'] ?? null)
                    ? array_values(array_map('strval', $m['capabilities']))
                    : ['text_generation'],
            ];

            if (isset($m['description']) && is_string($m['description'])) {
                $record['description'] = (string) $m['description'];
            }
            if (isset($m['provider']) && is_string($m['provider'])) {
                $record['ownedBy'] = (string) $m['provider'];
            }
            if (isset($m['published_time']) && is_string($m['published_time'])) {
                $record['createdAt'] = (string) $m['published_time'];
            }
            if (isset($m['equivalent_snapshot']) && is_string($m['equivalent_snapshot']) && $m['equivalent_snapshot'] !== '') {
                $record['equivalentSnapshot'] = (string) $m['equivalent_snapshot'];
            }

            $info = is_array($m['model_info'] ?? null) ? $m['model_info'] : [];
            if (isset($info['context_window']) && is_numeric($info['context_window'])) {
                $record['contextLength'] = (int) $info['context_window'];
            }
            if (isset($info['max_output_tokens']) && is_numeric($info['max_output_tokens'])) {
                $record['maxOutputTokens'] = (int) $info['max_output_tokens'];
            }

            // Feature flags lifted from the raw `features` array.
            $features = [];
            foreach ((array) ($m['features'] ?? []) as $flag) {
                if (is_string($flag) && $flag !== '') {
                    $features[sanitize_key($flag)] = true;
                }
            }
            if ($features !== []) {
                $record['features'] = $features;
            }

            // Modalities → expose as a flat array of strings on the record.
            $inference = is_array($m['inference_metadata'] ?? null) ? $m['inference_metadata'] : [];
            $request_modalities = is_array($inference['request_modality'] ?? null)
                ? array_values(array_map('strval', $inference['request_modality']))
                : [];
            $response_modalities = is_array($inference['response_modality'] ?? null)
                ? array_values(array_map('strval', $inference['response_modality']))
                : [];
            if ($request_modalities !== [] || $response_modalities !== []) {
                $record['modalities'] = [
                    'request' => $request_modalities,
                    'response' => $response_modalities,
                ];
            }

            // Pricing: DashScope returns tiered pricing keyed by range_name.
            // Flatten the first tier into pricing.{input,output,cacheRead,cacheWrite}
            // and stash extra tiers in pricing.tiers[].
            $pricing = $this->extract_dashscope_pricing($m);
            if ($pricing !== null) {
                $record['pricing'] = $pricing;
            }

            $out[] = $record;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, mixed>|null
     */
    private function extract_dashscope_pricing(array $model): ?array
    {
        $tiers_raw = is_array($model['prices'] ?? null) ? $model['prices'] : [];
        if ($tiers_raw === []) {
            return null;
        }

        $tiers = [];
        foreach ($tiers_raw as $tier) {
            if (!is_array($tier)) continue;
            $bucket = [
                'rangeName' => (string) ($tier['range_name'] ?? ''),
            ];
            foreach ((array) ($tier['prices'] ?? []) as $line) {
                if (!is_array($line)) continue;
                $type = (string) ($line['type'] ?? '');
                $price = isset($line['price']) && is_numeric($line['price']) ? (float) $line['price'] : null;
                if ($type === '' || $price === null) continue;
                $key = match ($type) {
                    'input_token' => 'input',
                    'output_token' => 'output',
                    'input_token_cache_read' => 'cacheRead',
                    'input_token_cache_creation_5m' => 'cacheWrite',
                    'input_token_cache_creation' => 'cacheWrite',
                    default => null,
                };
                if ($key !== null) {
                    $bucket[$key] = $price;
                }
            }
            $tiers[] = $bucket;
        }

        if ($tiers === []) {
            return null;
        }

        $first = $tiers[0];
        $pricing = [];
        foreach (['input', 'output', 'cacheRead', 'cacheWrite'] as $k) {
            if (isset($first[$k])) {
                $pricing[$k] = $first[$k];
            }
        }
        if (count($tiers) > 1) {
            $pricing['tiers'] = $tiers;
        }
        return $pricing === [] ? null : $pricing;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function extract_openai_balance(array $decoded): array
    {
        $infos = is_array($decoded['balance_infos'] ?? null) ? $decoded['balance_infos'] : [];
        $first = is_array($infos[0] ?? null) ? $infos[0] : [];
        return [
            'available' => (bool) ($decoded['is_available'] ?? false),
            'currency' => (string) ($first['currency'] ?? ''),
            'total' => isset($first['total_balance']) ? (string) $first['total_balance'] : '',
            'granted' => isset($first['granted_balance']) ? (string) $first['granted_balance'] : '',
            'toppedUp' => isset($first['topped_up_balance']) ? (string) $first['topped_up_balance'] : '',
            'fetchedAt' => gmdate('c'),
        ];
    }

    /**
     * @param array<int, string|array<string, mixed>> $models
     * @return array<string, mixed>|WP_Error
     */
    public function save_manual_models(string $provider_id, array $models)
    {
        $provider_id = sanitize_key($provider_id);
        $preset = $this->presets->preset($provider_id);
        if ($preset === null) {
            return new WP_Error('pfa_provider_unknown', __('Provider preset was not found.', 'wp-pfagent'), ['status' => 404]);
        }

        $model_discovery = (string) ($preset['modelDiscovery'] ?? 'api');
        if (!$this->allows_manual_entry($model_discovery)) {
            return new WP_Error('pfa_provider_manual_models_not_allowed', __('Manual model entries are not allowed for this provider preset.', 'wp-pfagent'),
                ['status' => 400, 'errorType' => 'manual_not_allowed']
            );
        }

        $normalized = [];
        foreach ($models as $model) {
            $id = is_array($model) ? (string) ($model['id'] ?? '') : (string) $model;
            $id = trim($id);
            if ($id === '') {
                continue;
            }

            $normalized[] = [
                'id' => sanitize_text_field($id),
                'label' => is_array($model) && is_string($model['label'] ?? null) && trim((string) $model['label']) !== ''
                    ? sanitize_text_field((string) $model['label'])
                    : sanitize_text_field($id),
                'source' => 'manual',
                'family' => (string) ($preset['family'] ?? ''),
                'capabilities' => ['text_generation'],
            ];
        }

        if ($normalized === []) {
            return new WP_Error('pfa_provider_manual_models_empty', __('At least one manual model id is required.', 'wp-pfagent'), ['status' => 400]);
        }

        $catalog = $this->catalog_payload($provider_id, $preset, $normalized, 'manual', gmdate('c'), null);
        $this->store_cache($provider_id, $catalog);

        return $catalog;
    }

    /**
     * @return array{url: string, headers: array<string, string>}|WP_Error
     * @param array<string, mixed> $context
     */
    public function request_descriptor(array $context, string $endpoint_key, array $extra = [])
    {
        $family = is_array($context['family'] ?? null) ? $context['family'] : [];
        $preset = is_array($context['preset'] ?? null) ? $context['preset'] : [];
        $settings = is_array($context['settings'] ?? null) ? $context['settings'] : [];
        $api_key = (string) ($context['apiKey'] ?? '');
        $endpoints = is_array($family['endpoints'] ?? null) ? $family['endpoints'] : [];
        $endpoint = (string) ($endpoints[$endpoint_key] ?? '');

        if ($endpoint === '') {
            return new WP_Error('pfa_provider_endpoint_missing', __('Provider family does not expose the required runtime endpoint.', 'wp-pfagent'), ['status' => 400, 'errorType' => 'provider_specific']);
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

    /**
     * @param array<string, mixed> $decoded
     * @return array<int, array<string, mixed>>
     */
    public function normalize_models(string $family, array $decoded, string $source): array
    {
        $raw_models = $family === 'gemini-compatible'
            ? (is_array($decoded['models'] ?? null) ? $decoded['models'] : [])
            : (is_array($decoded['data'] ?? null) ? $decoded['data'] : []);

        $models = [];
        foreach ($raw_models as $model) {
            if (!is_array($model)) {
                continue;
            }

            $id = $this->model_id($family, $model);
            if ($id === '') {
                continue;
            }

            $models[] = $this->extract_model_record($family, $id, $model, $source);
        }

        return $models;
    }

    /**
     * Per-family extraction of EVERYTHING the API exposes about a model.
     *
     * The wizard renders these fields as read-only (API-provided) and
     * collects user input for whatever the API doesn't return (pricing
     * always, plus caps when the provider's listing is sparse). The
     * confirmed record is saved into the credential's settings.models[].
     *
     * Pricing is intentionally NEVER pulled here — no provider's REST API
     * exposes per-token pricing in a stable, machine-readable form.
     * Pricing comes from the user in the wizard.
     *
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private function extract_model_record(string $family, string $id, array $model, string $source): array
    {
        $record = [
            'id' => $id,
            'label' => (string) ($model['display_name'] ?? $model['displayName'] ?? $id),
            'source' => $source,
            'family' => $family,
            'capabilities' => $this->model_capabilities($family, $model),
        ];

        if ($family === 'anthropic-compatible') {
            $caps = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];

            $context_length = (int) ($model['max_input_tokens'] ?? 0);
            $max_output = (int) ($model['max_tokens'] ?? 0);
            if ($context_length > 0) {
                $record['contextLength'] = $context_length;
            }
            if ($max_output > 0) {
                $record['maxOutputTokens'] = $max_output;
            }

            $created_at = (string) ($model['created_at'] ?? '');
            if ($created_at !== '') {
                $record['createdAt'] = $created_at;
            }

            // Features lifted directly from the capabilities object.
            // Each "supported" subkey is a boolean we propagate as-is so
            // the wizard can render the full surface without guessing.
            $record['features'] = [
                'batch' => $this->cap_flag($caps, 'batch'),
                'citations' => $this->cap_flag($caps, 'citations'),
                'code_execution' => $this->cap_flag($caps, 'code_execution'),
                'context_management' => $this->cap_flag($caps, 'context_management'),
                'image_input' => $this->cap_flag($caps, 'image_input'),
                'pdf_input' => $this->cap_flag($caps, 'pdf_input'),
                'structured_outputs' => $this->cap_flag($caps, 'structured_outputs'),
                'thinking' => $this->cap_flag($caps, 'thinking'),
                'thinking_enabled' => $this->nested_cap_flag($caps, ['thinking', 'types', 'enabled']),
                'thinking_adaptive' => $this->nested_cap_flag($caps, ['thinking', 'types', 'adaptive']),
                'effort' => $this->cap_flag($caps, 'effort'),
            ];

            // Reasoning variants — Kilo Code 2.4 pattern: model-specific
            // effort levels (low/medium/high/max). Lifted from the
            // capabilities.effort.<level>.supported flags.
            $variants = [];
            $effort = is_array($caps['effort'] ?? null) ? $caps['effort'] : [];
            foreach (['low', 'medium', 'high', 'max'] as $level) {
                if ($this->nested_cap_flag($caps, ['effort', $level])) {
                    $variants[] = $level;
                }
            }
            if ($variants !== []) {
                $record['reasoningVariants'] = $variants;
            }

            return $record;
        }

        if ($family === 'gemini-compatible') {
            $context_length = (int) ($model['inputTokenLimit'] ?? 0);
            $max_output = (int) ($model['outputTokenLimit'] ?? 0);
            if ($context_length > 0) {
                $record['contextLength'] = $context_length;
            }
            if ($max_output > 0) {
                $record['maxOutputTokens'] = $max_output;
            }
            if (isset($model['description']) && is_string($model['description'])) {
                $record['description'] = (string) $model['description'];
            }
            if (isset($model['version']) && is_string($model['version'])) {
                $record['version'] = (string) $model['version'];
            }

            // Kilo Code 1.9: per-model temperature / topP / topK defaults.
            // Gemini's /v1beta/models exposes these directly. Surfacing
            // them lets the wizard show recommended-vs-overridden values
            // and the runtime apply per-model defaults when LoopOptions
            // leaves them at framework defaults.
            $defaults = [];
            foreach (['temperature', 'topP', 'topK', 'maxTemperature'] as $key) {
                if (isset($model[$key]) && is_numeric($model[$key])) {
                    $defaults[$key] = $model[$key] + 0; // coerce int|float
                }
            }
            if ($defaults !== []) {
                $record['defaults'] = $defaults;
            }

            $features = [];
            if (isset($model['thinking'])) {
                $features['thinking'] = (bool) $model['thinking'];
            }
            $methods = is_array($model['supportedGenerationMethods'] ?? null) ? $model['supportedGenerationMethods'] : [];
            $features['embeddings'] = in_array('embedContent', $methods, true);
            $features['caching'] = in_array('createCachedContent', $methods, true);
            $features['batch'] = in_array('batchGenerateContent', $methods, true);
            $features['streaming'] = in_array('streamGenerateContent', $methods, true);
            $record['features'] = $features;

            return $record;
        }

        // openai-compatible (DeepSeek / Qwen / xAI / OpenAI / Xiaomi / Ollama / etc.)
        // Their /v1/models listings are uniformly sparse — usually just
        // {id, object, owned_by, created}. We surface what we got so the
        // wizard can show the user "this is what the API confirmed" and
        // ask them to fill everything else (caps + pricing) by hand.
        if (isset($model['owned_by']) && is_string($model['owned_by'])) {
            $record['ownedBy'] = (string) $model['owned_by'];
        }
        if (isset($model['created']) && is_numeric($model['created'])) {
            $record['createdAt'] = gmdate('c', (int) $model['created']);
        }

        return $record;
    }

    /**
     * Read a top-level capability `<name>.supported` flag from the
     * Anthropic capabilities object. Returns false when missing.
     *
     * @param array<string, mixed> $caps
     */
    private function cap_flag(array $caps, string $name): bool
    {
        $entry = $caps[$name] ?? null;
        if (!is_array($entry)) {
            return false;
        }
        return (bool) ($entry['supported'] ?? false);
    }

    /**
     * Read a nested capability flag (`<a>.<b>.supported` or deeper).
     *
     * @param array<string, mixed> $caps
     * @param list<string> $path
     */
    private function nested_cap_flag(array $caps, array $path): bool
    {
        $node = $caps;
        foreach ($path as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return false;
            }
            $node = $node[$key];
        }
        if (!is_array($node)) {
            return false;
        }
        return (bool) ($node['supported'] ?? false);
    }

    public function clear_cache(string $provider_id): void
    {
        $provider_id = sanitize_key($provider_id);
        $records = $this->cache_records();
        unset($records[$provider_id]);
        update_option(self::CACHE_OPTION, $records, false);
        // User-initiated clear also wipes any remembered failure so the
        // next discover() actually hits the API.
        $this->clear_failure($provider_id);
        unset($this->resultsInRequest[$provider_id]);
    }

    /**
     * Return the last cached failure for $provider_id if still within
     * FAILURE_TTL_SECONDS. Kilo Tier 1.12. Wrapped back into a WP_Error
     * so the caller sees the same shape it would have got the first time.
     */
    private function cached_failure(string $provider_id): ?WP_Error
    {
        $failures = $this->failure_records();
        $record = is_array($failures[$provider_id] ?? null) ? $failures[$provider_id] : null;
        if ($record === null) {
            return null;
        }
        $expires_at = (int) ($record['expiresAt'] ?? 0);
        if ($expires_at <= time()) {
            return null;
        }
        $error = new WP_Error(
            (string) ($record['code'] ?? 'pfa_provider_cached_failure'),
            (string) ($record['message'] ?? __('Provider discovery failed recently and is on cooldown.', 'wp-pfagent')),
            is_array($record['data'] ?? null) ? $record['data'] : []
        );
        $error->add_data(array_merge(
            is_array($record['data'] ?? null) ? $record['data'] : [],
            ['cachedFailureUntil' => gmdate('c', $expires_at)]
        ));
        return $error;
    }

    private function store_failure(string $provider_id, WP_Error $error): void
    {
        $failures = $this->failure_records();
        $failures[$provider_id] = [
            'code' => (string) $error->get_error_code(),
            'message' => (string) $error->get_error_message(),
            'data' => $error->get_error_data() ?? [],
            'recordedAt' => gmdate('c'),
            'expiresAt' => time() + self::FAILURE_TTL_SECONDS,
        ];
        update_option(self::FAILURE_OPTION, $failures, false);
    }

    private function clear_failure(string $provider_id): void
    {
        $failures = $this->failure_records();
        if (isset($failures[$provider_id])) {
            unset($failures[$provider_id]);
            update_option(self::FAILURE_OPTION, $failures, false);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function failure_records(): array
    {
        $records = get_option(self::FAILURE_OPTION, []);
        return is_array($records) ? $records : [];
    }

    /**
     * @return array<string, mixed>|null
     * @param array<string, mixed> $preset
     */
    private function cached_catalog(string $provider_id, array $preset): ?array
    {
        $records = $this->cache_records();
        $record = is_array($records[$provider_id] ?? null) ? $records[$provider_id] : null;
        if ($record === null) {
            return null;
        }

        $source = (string) ($record['source'] ?? '');
        if ($source !== 'manual') {
            $expires_at = strtotime((string) ($record['expiresAt'] ?? ''));
            if ($expires_at === false || $expires_at <= time()) {
                return null;
            }
        }

        $models = is_array($record['models'] ?? null) ? $record['models'] : [];
        $models = array_values(array_filter($models, 'is_array'));
        if ($models === []) {
            return null;
        }

        $response_source = $source === 'manual' ? 'manual' : 'cache';
        $models = array_map(static function (array $model) use ($response_source): array {
            $model['source'] = $response_source;
            return $model;
        }, $models);

        return $this->catalog_payload(
            $provider_id,
            $preset,
            $models,
            $response_source,
            (string) ($record['fetchedAt'] ?? gmdate('c')),
            $source === 'manual' ? null : (string) ($record['expiresAt'] ?? '')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog_payload(string $provider_id, array $preset, array $models, string $source, string $fetched_at, ?string $expires_at): array
    {
        return [
            'providerId' => $provider_id,
            'label' => (string) ($preset['label'] ?? $provider_id),
            'family' => (string) ($preset['family'] ?? ''),
            'modelDiscovery' => (string) ($preset['modelDiscovery'] ?? 'api'),
            'manualAllowed' => $this->allows_manual_entry((string) ($preset['modelDiscovery'] ?? 'api')),
            'source' => $source,
            'fetchedAt' => $fetched_at,
            'expiresAt' => $expires_at,
            'ttlSeconds' => $expires_at === null ? null : self::TTL_SECONDS,
            'models' => array_values($models),
        ];
    }

    /**
     * @param array<string, mixed> $catalog
     */
    private function store_cache(string $provider_id, array $catalog): void
    {
        $records = $this->cache_records();
        $records[$provider_id] = [
            'source' => (string) ($catalog['source'] ?? 'api'),
            'fetchedAt' => (string) ($catalog['fetchedAt'] ?? gmdate('c')),
            'expiresAt' => $catalog['expiresAt'] ?? null,
            'models' => is_array($catalog['models'] ?? null) ? $catalog['models'] : [],
        ];
        update_option(self::CACHE_OPTION, $records, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function cache_records(): array
    {
        $records = get_option(self::CACHE_OPTION, []);
        if (is_array($records) && $records !== []) {
            return $records;
        }

        $legacy_records = get_option($this->legacy_cache_option(), []);
        if (is_array($legacy_records) && $legacy_records !== []) {
            update_option(self::CACHE_OPTION, $legacy_records, false);

            return $legacy_records;
        }

        return is_array($records) ? $records : [];
    }

    private function legacy_cache_option(): string
    {
        return 'projectflash' . '_agent_model_cache_v1';
    }

    private function allows_api_discovery(string $mode): bool
    {
        return in_array($mode, ['api', 'api_or_manual'], true);
    }

    private function allows_manual_entry(string $mode): bool
    {
        return in_array($mode, ['manual', 'api_or_manual', 'deployment_config', 'provider_specific'], true);
    }

    private function expires_at(): string
    {
        return gmdate('c', time() + self::TTL_SECONDS);
    }

    private function decode_response(mixed $response, string $operation): array|WP_Error
    {
        if (is_wp_error($response)) {
            return new WP_Error(
                'pfa_provider_network_error',
                $operation . ' failed: ' . $this->redact((string) $response->get_error_message()),
                ['status' => 502, 'errorType' => 'network', 'providerStatus' => 0]
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status_code < 200 || $status_code >= 300) {
            return new WP_Error(
                'pfa_provider_http_status',
                $operation . ' returned HTTP ' . (string) $status_code . ': ' . $this->provider_error_message($decoded, $body),
                [
                    'status' => 502,
                    'providerStatus' => $status_code,
                    'errorType' => $this->classify_provider_error($status_code, $decoded, $body),
                ]
            );
        }

        if (!is_array($decoded)) {
            return new WP_Error('pfa_provider_invalid_json', $operation . ' returned invalid JSON.', ['status' => 502, 'errorType' => 'invalid_response']);
        }

        return $decoded;
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

    private function classify_provider_error(int $status_code, mixed $decoded, string $body): string
    {
        $message = strtolower($this->provider_error_message($decoded, $body));
        if ($status_code === 401 || $status_code === 403) {
            return 'auth';
        }

        if ($status_code === 402 || str_contains($message, 'quota') || str_contains($message, 'insufficient') || str_contains($message, 'balance')) {
            return 'quota';
        }

        if ($status_code === 429 || str_contains($message, 'rate limit') || str_contains($message, 'too many requests')) {
            return 'rate_limit';
        }

        if (str_contains($message, 'invalid model') || str_contains($message, 'model not found')) {
            return 'invalid_model';
        }

        return 'provider_error';
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

    /**
     * @param array<string, mixed> $model
     * @return array<int, string>
     */
    private function model_capabilities(string $family, array $model): array
    {
        if ($family === 'gemini-compatible') {
            $methods = is_array($model['supportedGenerationMethods'] ?? null) ? $model['supportedGenerationMethods'] : [];

            return array_values(array_filter(array_map('strval', $methods)));
        }

        return ['text_generation'];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function resolve_template(string $template, array $values): string|WP_Error
    {
        $resolved = $this->replace_placeholders($template, $values);
        if (preg_match('/{{\s*[^}]+\s*}}/', $resolved)) {
            return new WP_Error('pfa_provider_settings_missing', __('Provider requires additional settings before runtime call.', 'wp-pfagent'), ['status' => 400, 'errorType' => 'configuration']);
        }

        if (!str_starts_with($resolved, 'http://') && !str_starts_with($resolved, 'https://')) {
            return new WP_Error('pfa_provider_base_url_invalid', __('Provider base URL must be an HTTP URL.', 'wp-pfagent'), ['status' => 400, 'errorType' => 'configuration']);
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

    private function redact(string $message): string
    {
        return preg_replace('/(sk-[a-zA-Z0-9_-]{8,}|AIza[a-zA-Z0-9_-]{8,})/', '[redacted]', $message) ?? $message;
    }
}

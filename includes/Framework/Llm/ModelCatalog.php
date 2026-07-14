<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Llm;

/**
 * Per-model catalog of caps + pricing. Loaded from a JSON file so prices can
 * be updated without touching code.
 *
 * Three responsibilities:
 *
 *  1. capsFor(modelId) → context_length + max_output_tokens fallback for
 *     providers (Anthropic) that don't expose them via /models.
 *
 *  2. pricingFor(modelId) → per-token rates (input / output / cacheRead /
 *     cacheWrite). Tier-aware: when a model declares `pricing.tiers`, the
 *     rate depends on the prompt size (Gemini 2.5 Pro doubles past 200K).
 *
 *  3. computeCostMicros(modelId, usage) → integer micros (1e-6 USD) for the
 *     round, ready to bank for trace logs / dashboards.
 *
 * Lookup uses LONGEST-PREFIX match on the canonical model id so that
 * versioned ids ("claude-opus-4-7-20260301") still resolve to the right
 * family entry without one row per version. Provider prefixes ("anthropic/")
 * are stripped before matching.
 */
final class ModelCatalog
{
    // Exceptions are internal catalog/config errors (bad JSON, missing keys),
    // caught by the runtime and surfaced as JSON/logs — never echoed as HTML.
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    /** @var array<string, array<string, mixed>>|null */
    private ?array $models = null;

    /**
     * Build from a JSON file (legacy / fallback) OR from an in-memory array
     * of models (new: per-credential populated from the wizard's discovery +
     * user confirmation).
     *
     * @param string $catalogPath  Path to a JSON file; ignored when $models is
     *                             non-null (lets static factories pass an
     *                             empty path).
     * @param array<int|string, array<string, mixed>>|null $models Optional
     *        in-memory models map keyed by id. When provided, the JSON file
     *        is never read.
     */
    public function __construct(private readonly string $catalogPath, ?array $models = null)
    {
        if ($models !== null) {
            $this->models = $this->normaliseModelsArray($models);
        }
    }

    /**
     * Build directly from an in-memory list of model records. This is the
     * preferred entry-point for the per-credential flow where the wizard
     * collected caps + pricing + features and saved them on
     * CredentialStore.settings.models[].
     *
     * @param array<int|string, array<string, mixed>> $models
     */
    public static function fromArray(array $models): self
    {
        return new self('', $models);
    }

    /**
     * Build from a JSON catalog file. Kept for backward compatibility and
     * for the smoke harness that exercises a known-good fixture.
     */
    public static function fromFile(string $catalogPath): self
    {
        return new self($catalogPath);
    }

    /**
     * Build the catalog from the JSON file shipped with the framework. Throws
     * if the file is missing or invalid — silent failure here would mean we
     * compute zero cost and the host never knows.
     */
    public function load(): void
    {
        if ($this->models !== null) {
            return;
        }

        if ($this->catalogPath === '') {
            // Constructed via fromArray() with a (now-cleared) models map.
            // Treat the catalog as empty rather than blowing up on the file
            // path check — callers will still get null from lookup().
            $this->models = [];
            return;
        }

        if (!file_exists($this->catalogPath)) {
            throw new \RuntimeException(sprintf(
                'ModelCatalog: file not found at %s',
                $this->catalogPath,
            ));
        }

        $decoded = json_decode((string) file_get_contents($this->catalogPath), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('ModelCatalog: file is not valid JSON.');
        }

        $models = $decoded['models'] ?? null;
        if (!is_array($models)) {
            throw new \RuntimeException('ModelCatalog: missing top-level `models` object.');
        }

        $this->models = $this->normaliseModelsArray($models);
    }

    /**
     * Map both shapes (file-shipped associative `{ "id" => {...} }` and the
     * credential-shipped list `[ { "id": "...", ... }, ... ]`) into the
     * canonical assoc form keyed by id.
     *
     * @param array<int|string, array<string, mixed>> $models
     * @return array<string, array<string, mixed>>
     */
    private function normaliseModelsArray(array $models): array
    {
        $out = [];
        foreach ($models as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = is_string($key) ? $key : (string) ($entry['id'] ?? '');
            if ($id === '' || str_starts_with($id, '_')) {
                // Skip underscore-prefixed comment entries (e.g. _xiaomi_mimo_ids).
                continue;
            }
            $out[$id] = $entry;
        }
        return $out;
    }

    /**
     * @return array{contextLength: int, maxOutputTokens: int}|null
     */
    public function capsFor(string $modelId): ?array
    {
        $entry = $this->lookup($modelId);
        if ($entry === null) {
            return null;
        }

        $context = (int) ($entry['contextLength'] ?? 0);
        $output = (int) ($entry['maxOutputTokens'] ?? 0);
        if ($context <= 0 || $output <= 0) {
            return null;
        }

        return ['contextLength' => $context, 'maxOutputTokens' => $output];
    }

    /**
     * Returns the per-token rates in USD/Mtok. When the model declares
     * `pricing.tiers`, the tier is chosen by `$promptTokens` (the running
     * input size for THIS request). For non-tiered models, the static
     * `pricing` block is returned as-is.
     *
     * @return array{input: float, output: float, cacheRead: float, cacheWrite: float}|null
     */
    public function pricingFor(string $modelId, int $promptTokens = 0): ?array
    {
        $entry = $this->lookup($modelId);
        if ($entry === null || !is_array($entry['pricing'] ?? null)) {
            return null;
        }

        $pricing = $entry['pricing'];
        $tiers = is_array($pricing['tiers'] ?? null) ? $pricing['tiers'] : [];

        if ($tiers !== []) {
            // Tiers must be sorted ascending by `inputUpTo`. Pick the first
            // one whose ceiling >= promptTokens; fall back to last when
            // promptTokens exceeds all tiers.
            $picked = null;
            foreach ($tiers as $tier) {
                if (!is_array($tier)) {
                    continue;
                }
                $ceiling = (int) ($tier['inputUpTo'] ?? 0);
                if ($ceiling > 0 && $promptTokens <= $ceiling) {
                    $picked = $tier;
                    break;
                }
            }
            $picked ??= end($tiers);
            if (is_array($picked)) {
                return [
                    'input' => (float) ($picked['input'] ?? 0),
                    'output' => (float) ($picked['output'] ?? 0),
                    'cacheRead' => (float) ($picked['cacheRead'] ?? $picked['input'] ?? 0),
                    'cacheWrite' => (float) ($picked['cacheWrite'] ?? 0),
                ];
            }
        }

        return [
            'input' => (float) ($pricing['input'] ?? 0),
            'output' => (float) ($pricing['output'] ?? 0),
            'cacheRead' => (float) ($pricing['cacheRead'] ?? $pricing['input'] ?? 0),
            'cacheWrite' => (float) ($pricing['cacheWrite'] ?? 0),
        ];
    }

    /**
     * Minimum tokens before the provider actually caches (Anthropic only).
     * Below this, cache_control markers are a waste — the provider silently
     * skips caching and you pay the 1.25× write surcharge for nothing.
     */
    public function minCacheTokensFor(string $modelId): int
    {
        $entry = $this->lookup($modelId);
        return is_array($entry) ? (int) ($entry['minCacheTokens'] ?? 0) : 0;
    }

    /**
     * @return list<string>
     */
    public function featuresFor(string $modelId): array
    {
        $entry = $this->lookup($modelId);
        if (!is_array($entry) || !is_array($entry['features'] ?? null)) {
            return [];
        }

        $features = $entry['features'];
        // Two on-disk shapes coexist for backward compatibility:
        //   - File catalog (legacy): list<string> — e.g. ["tools","stream","penalties"]
        //   - Credential catalog (new): map<string, bool> — e.g. {"penalties": true,
        //     "image_input": true} as captured by the wizard from Anthropic /
        //     Gemini capability objects. Normalise both into the list shape so
        //     hasFeature() works regardless of which catalog source built the
        //     instance.
        $isAssoc = false;
        foreach (array_keys($features) as $key) {
            if (is_string($key)) {
                $isAssoc = true;
                break;
            }
        }

        if ($isAssoc) {
            $out = [];
            foreach ($features as $flag => $enabled) {
                if (is_string($flag) && $enabled) {
                    $out[] = $flag;
                }
            }
            return $out;
        }

        return array_values(array_filter($features, 'is_string'));
    }

    public function hasFeature(string $modelId, string $feature): bool
    {
        return in_array($feature, $this->featuresFor($modelId), true);
    }

    /**
     * Per-model sampling defaults (temperature / topP / topK / maxTemperature)
     * as captured by the wizard from the provider's API (Gemini exposes them
     * via /v1beta/models; for OpenAI-compatible providers the wizard collects
     * them by hand). Used by gateways when CompletionRequest leaves the
     * matching field null. (Kilo Tier 1.9.)
     *
     * @return array{temperature?: float, topP?: float, topK?: int, maxTemperature?: float}
     */
    public function defaultsFor(string $modelId): array
    {
        $entry = $this->lookup($modelId);
        if (!is_array($entry) || !is_array($entry['defaults'] ?? null)) {
            return [];
        }

        $out = [];
        foreach (['temperature', 'topP', 'maxTemperature'] as $key) {
            if (isset($entry['defaults'][$key]) && is_numeric($entry['defaults'][$key])) {
                $out[$key] = (float) $entry['defaults'][$key];
            }
        }
        if (isset($entry['defaults']['topK']) && is_numeric($entry['defaults']['topK'])) {
            $out['topK'] = (int) $entry['defaults']['topK'];
        }
        return $out;
    }

    /**
     * Compute the round cost in micros (1e-6 USD). Returns 0 when the model
     * isn't in the catalog; the host is responsible for surfacing a
     * `cost_unknown` trace so dashboards know they're undercounting.
     *
     * Usage shape (canonical):
     *  - promptTokens     int  total input (incl. cache)
     *  - completionTokens int  visible output
     *  - cacheHitTokens   int  input read from cache
     *  - cacheMissTokens  int  input billed at full rate
     *  - cacheWriteTokens int  input written into cache (Anthropic)
     *  - reasoningTokens  int  hidden CoT (o-series / Gemini thoughts) — billed at output rate
     *
     * @param array<string, int> $usage
     */
    public function computeCostMicros(string $modelId, array $usage): int
    {
        $promptTokens = (int) ($usage['promptTokens'] ?? 0);
        $pricing = $this->pricingFor($modelId, $promptTokens);
        if ($pricing === null) {
            return 0;
        }

        // When a provider doesn't surface cache hit/miss separately, treat
        // promptTokens as fresh input. This is safe: the user pays the
        // full rate, which is the worst case — never undercount.
        $cacheHit = (int) ($usage['cacheHitTokens'] ?? 0);
        $cacheMiss = (int) ($usage['cacheMissTokens'] ?? 0);
        $cacheWrite = (int) ($usage['cacheWriteTokens'] ?? 0);
        if ($cacheHit === 0 && $cacheMiss === 0) {
            $cacheMiss = $promptTokens;
        }

        $completion = (int) ($usage['completionTokens'] ?? 0);
        $reasoning = (int) ($usage['reasoningTokens'] ?? 0);

        $usd = 0.0;
        $usd += $cacheMiss * $pricing['input'] / 1_000_000.0;
        $usd += $cacheHit * $pricing['cacheRead'] / 1_000_000.0;
        $usd += $cacheWrite * $pricing['cacheWrite'] / 1_000_000.0;
        $usd += $completion * $pricing['output'] / 1_000_000.0;
        $usd += $reasoning * $pricing['output'] / 1_000_000.0;

        return (int) round($usd * 1_000_000.0);
    }

    /**
     * Longest-prefix lookup. "claude-opus-4-7-20260301" → "claude-opus-4-7".
     * Provider prefixes like "anthropic/" are stripped before matching.
     *
     * @return array<string, mixed>|null
     */
    private function lookup(string $modelId): ?array
    {
        $this->load();
        $models = $this->models ?? [];

        $key = $modelId;
        if (str_contains($key, '/')) {
            $key = (string) substr($key, (int) strrpos($key, '/') + 1);
        }
        $key = strtolower($key);

        // Exact match wins.
        foreach ($models as $candidate => $entry) {
            if (strtolower((string) $candidate) === $key && is_array($entry)) {
                return $entry;
            }
        }

        // Longest-prefix on the canonical id. Sort candidates descending by
        // length so "claude-opus-4-7" beats "claude-opus-4" when both
        // exist.
        $candidates = array_keys($models);
        usort($candidates, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($candidates as $candidate) {
            $candidateLc = strtolower((string) $candidate);
            if ($candidateLc !== '' && str_starts_with($key, $candidateLc) && is_array($models[$candidate])) {
                return $models[$candidate];
            }
        }

        return null;
    }
}

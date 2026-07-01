<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Llm;

/**
 * Build the right Gateway implementation for a given provider family. The
 * host hands in a small bundle of:
 *
 *   - family: 'openai-compatible' | 'anthropic-compatible' | 'gemini-compatible'
 *   - apiKey: the credential to use
 *   - baseUrl: provider's HTTP endpoint root
 *   - settings: optional per-credential overrides (anthropic-version,
 *     anthropic-beta, gemini safetySettings override, etc.)
 *
 * The factory holds an optional ModelCatalog so each gateway can compute cost
 * and resolve caps fallbacks without the host wiring it per call.
 */
final class GatewayFactory
{
    public function __construct(private readonly ?ModelCatalog $catalog = null)
    {
    }

    /**
     * @param array<string, mixed> $context
     *        Required keys: family (string), apiKey (string), baseUrl (string).
     *        Optional: settings (array<string,string>), timeout (int).
     */
    public function build(array $context): Gateway
    {
        $family = (string) ($context['family'] ?? '');
        $apiKey = (string) ($context['apiKey'] ?? '');
        $baseUrl = (string) ($context['baseUrl'] ?? '');
        $settings = is_array($context['settings'] ?? null) ? $context['settings'] : [];
        $timeout = (int) ($context['timeout'] ?? 120);

        if ($apiKey === '' || $baseUrl === '') {
            throw new \InvalidArgumentException(
                'GatewayFactory::build requires apiKey and baseUrl in the context.'
            );
        }

        switch ($family) {
            case 'openai-compatible':
                return new OpenAiCompatibleGateway(
                    apiKey: $apiKey,
                    baseUrl: $baseUrl,
                    fallbackCaps: [],
                    httpTimeoutSeconds: $timeout,
                    maxContinuationAttempts: 1,
                    catalog: $this->catalog,
                );

            case 'anthropic-compatible':
                $apiVersion = (string) ($settings['anthropic_version'] ?? AnthropicGateway::DEFAULT_API_VERSION);
                $betaRaw = (string) ($settings['anthropic_beta'] ?? '');
                $beta = $betaRaw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $betaRaw))));
                return new AnthropicGateway(
                    apiKey: $apiKey,
                    baseUrl: $baseUrl,
                    catalog: $this->catalog,
                    apiVersion: $apiVersion,
                    betaFeatures: $beta,
                    httpTimeoutSeconds: $timeout,
                );

            case 'gemini-compatible':
                $safety = is_array($settings['safety_settings'] ?? null) ? $settings['safety_settings'] : [];
                return new GeminiGateway(
                    apiKey: $apiKey,
                    baseUrl: $baseUrl,
                    catalog: $this->catalog,
                    safetySettings: $safety,
                    httpTimeoutSeconds: $timeout,
                );

            default:
                throw new \InvalidArgumentException(sprintf(
                    'GatewayFactory: unknown family "%s". Expected openai-compatible | anthropic-compatible | gemini-compatible.',
                    $family,
                ));
        }
    }
}

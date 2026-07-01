<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

final class AgentContract
{
    private const NAMESPACE = 'wp-pfagent/v1';

    public function __construct(
        private readonly ProviderPresets $presets,
        private readonly AgentToolRegistry $tools
    ) {
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function build()
    {
        $capabilities = $this->capability_manifest();
        if ($capabilities instanceof WP_Error) {
            return $capabilities;
        }

        $presets = $this->presets->catalog();
        if ($presets instanceof WP_Error) {
            return $presets;
        }

        return [
            'schema' => 'projectflash.agent.contract',
            'schemaVersion' => 1,
            'generatedAt' => gmdate('c'),
            'plugin' => [
                'name' => 'WP PFAgent',
                'version' => defined('WP_PFAGENT_VERSION') ? WP_PFAGENT_VERSION : 'unknown',
                'namespace' => self::NAMESPACE,
            ],
            'permissions' => [
                'manageAgent' => [
                    'wordpressCapability' => 'manage_options',
                    'description' => 'Read and operate agent runtime surfaces.',
                ],
                'manageCredentials' => [
                    'wordpressCapability' => 'manage_options',
                    'description' => 'Manage provider credentials and provider checks.',
                ],
            ],
            'routes' => self::routes(),
            'capabilityStates' => is_array($capabilities['states'] ?? null) ? $capabilities['states'] : [],
            'capabilities' => is_array($capabilities['capabilities'] ?? null) ? $capabilities['capabilities'] : [],
            'agentTools' => $this->tools->tools(),
            'providers' => $this->provider_contract($presets),
            'workflowDependency' => [
                'active' => WorkflowDependency::is_active(),
                'namespace' => WorkflowDependency::rest_namespace(),
                'capabilities' => WorkflowDependency::capabilities(),
            ],
            'security' => [
                'secretsInContract' => false,
                'credentialValuesExposed' => false,
                'nonceRequired' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function openapi()
    {
        $contract = $this->build();
        if ($contract instanceof WP_Error) {
            return $contract;
        }

        $paths = [];
        foreach (self::routes() as $route) {
            $method = strtolower((string) ($route['method'] ?? 'GET'));
            $path = '/' . self::NAMESPACE . (string) ($route['path'] ?? '');
            $paths[$path][$method] = $this->openapi_operation($route);
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'WP PFAgent API',
                'version' => defined('WP_PFAGENT_VERSION') ? WP_PFAGENT_VERSION : 'unknown',
                'description' => 'Internal API contract for WP PFAgent. Credential values are never exposed by this document.',
            ],
            'servers' => [
                ['url' => rest_url()],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'wpNonce' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-WP-Nonce',
                    ],
                ],
                'schemas' => $this->openapi_schemas($contract),
            ],
            'security' => [
                ['wpNonce' => []],
            ],
            'x-projectflash' => [
                'schema' => 'projectflash.agent.openapi',
                'schemaVersion' => 1,
                'generatedAt' => gmdate('c'),
                'capabilityCount' => count(is_array($contract['capabilities'] ?? null) ? $contract['capabilities'] : []),
                'agentToolCount' => count(is_array($contract['agentTools'] ?? null) ? $contract['agentTools'] : []),
                'secretsInContract' => false,
                'credentialValuesExposed' => false,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function routes(): array
    {
        return [
            self::route('GET', '/contract', 'manageAgent', false, 'AgentContract'),
            self::route('GET', '/contract/openapi', 'manageAgent', false, 'OpenApiDocument'),
            self::route('GET', '/provider-presets', 'manageAgent', false, 'ProviderPresetCatalog'),
            self::route('GET', '/provider-credentials', 'manageCredentials', false, 'ProviderCredentialCatalog'),
            self::route('POST', '/provider-credentials/{provider}', 'manageCredentials', true, 'ProviderCredentialStatus'),
            self::route('DELETE', '/provider-credentials/{provider}', 'manageCredentials', true, 'ProviderCredentialStatus'),
            self::route('POST', '/provider-credentials/{provider}/rotate', 'manageCredentials', true, 'ProviderCredentialStatus'),
            self::route('POST', '/provider-credentials/{provider}/test', 'manageCredentials', false, 'ProviderCredentialStatus'),
            self::route('GET', '/provider-models/{provider}', 'manageCredentials', false, 'ProviderModelCatalog'),
            self::route('POST', '/provider-models/{provider}/manual', 'manageCredentials', true, 'ProviderModelCatalog'),
            self::route('POST', '/provider-models/{provider}/save', 'manageCredentials', true, 'ProviderModelCatalog'),
            self::route('POST', '/provider-health/{provider}', 'manageCredentials', false, 'ProviderHealthResult'),
            self::route('POST', '/provider-runtime/{provider}/smoke', 'manageCredentials', false, 'ProviderGenerationResult'),
            self::route('GET', '/agent-runtime/tools', 'manageAgent', false, 'AgentToolCatalog'),
            self::route('GET', '/agent-runtime/internal-docs', 'manageAgent', false, 'AgentInternalDocs'),
            self::route('POST', '/agent-runtime/fix-suggestions', 'manageAgent', false, 'AgentFixSuggestionsResult'),
            // The Framework Loop-backed turn + resume routes. These replaced the
            // v1 /agent-runtime/turn + /llm/{provider}/* routes during the Sprint C
            // cutover; the cutover removed the old entries here but never added the
            // new ones, so the contract silently stopped advertising the agent's
            // primary endpoint (and BetaReadiness::declared_routes_are_registered
            // flagged every capability that points at the turn route).
            self::route('POST', '/agent-runtime/turn-v2', 'manageAgent', true, 'AgentRuntimeTurnResult'),
            self::route('POST', '/agent-runtime/resume-v2', 'manageAgent', true, 'AgentRuntimeTurnResult'),
            self::route('GET', '/agent-runtime/support-export', 'manageAgent', false, 'AgentSupportExport'),
            self::route('GET', '/agent-runtime/metrics', 'manageAgent', false, 'AgentMetrics'),
            self::route('GET', '/agent-runtime/beta-readiness', 'manageAgent', false, 'AgentBetaReadiness'),
            self::route('GET', '/agent-runtime/progress', 'manageAgent', false, 'AgentRuntimeProgress'),
            self::route('GET', '/agent-runtime/permission-rules', 'manageAgent', false, 'AgentPermissionRules'),
            self::route('PUT', '/agent-runtime/permission-rules', 'manageAgent', true, 'AgentPermissionRules'),
            self::route('GET', '/active-llm', 'manageAgent', false, 'ActiveLlmSelection'),
            self::route('PUT', '/active-llm', 'manageAgent', true, 'ActiveLlmSelection'),
            self::route('POST', '/vfs/library-refresh', 'manageAgent', false, 'VfsLibraryRefresh'),
            self::route('GET', '/chat-sessions', 'manageAgent', false, 'ChatSessionCatalog'),
            self::route('POST', '/chat-sessions', 'manageAgent', true, 'ChatSession'),
            self::route('GET', '/chat-sessions/{id}', 'manageAgent', false, 'ChatSession'),
            self::route('PATCH', '/chat-sessions/{id}', 'manageAgent', true, 'ChatSession'),
            self::route('DELETE', '/chat-sessions/{id}', 'manageAgent', true, 'ChatSessionDeleted'),
            self::route('POST', '/chat-sessions/{id}/messages', 'manageAgent', true, 'ChatSession'),
        ];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function capability_manifest()
    {
        $path = WP_PFAGENT_DIR . 'config/capabilities.json';
        if (!file_exists($path)) {
            return new WP_Error('pfa_agent_capabilities_missing', __('Agent capability manifest is missing.', 'wp-pfagent'), ['status' => 500]);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || ($decoded['schemaVersion'] ?? null) !== 1 || !is_array($decoded['capabilities'] ?? null)) {
            return new WP_Error('pfa_agent_capabilities_invalid', __('Agent capability manifest is invalid.', 'wp-pfagent'), ['status' => 500]);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $presets
     * @return array<string, mixed>
     */
    private function provider_contract(array $presets): array
    {
        $families = [];
        foreach (is_array($presets['families'] ?? null) ? $presets['families'] : [] as $key => $family) {
            if (!is_array($family)) {
                continue;
            }
            $families[(string) $key] = [
                'label' => (string) ($family['label'] ?? $key),
                'auth' => (string) ($family['auth'] ?? ''),
                'endpoints' => is_array($family['endpoints'] ?? null) ? array_keys($family['endpoints']) : [],
            ];
        }

        $items = [];
        foreach (is_array($presets['presets'] ?? null) ? $presets['presets'] : [] as $key => $preset) {
            if (!is_array($preset)) {
                continue;
            }
            $items[(string) $key] = [
                'label' => (string) ($preset['label'] ?? $key),
                'family' => (string) ($preset['family'] ?? ''),
                'modelDiscovery' => (string) ($preset['modelDiscovery'] ?? ''),
                'status' => (string) ($preset['status'] ?? ''),
            ];
        }

        return [
            'schemaVersion' => (int) ($presets['schemaVersion'] ?? 1),
            'families' => $families,
            'presets' => $items,
            'credentialsIncluded' => false,
            'modelListsIncluded' => false,
        ];
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    private function openapi_operation(array $route): array
    {
        $method = (string) ($route['method'] ?? 'GET');
        $path = (string) ($route['path'] ?? '');
        $response = (string) ($route['response'] ?? 'Object');
        $operation_id = strtolower($method) . str_replace(['/', '{', '}', '-'], ['', '_', '', '_'], $path);
        $operation = [
            'operationId' => trim($operation_id, '_'),
            'summary' => $this->summary_for_route($method, $path),
            'x-projectflash-permission' => (string) ($route['permission'] ?? ''),
            'x-projectflash-sideEffect' => (bool) ($route['sideEffect'] ?? false),
            'parameters' => $this->path_parameters($path),
            'responses' => [
                '200' => [
                    'description' => 'Successful response.',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/' . $this->schema_ref($response)],
                        ],
                    ],
                ],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '500' => ['$ref' => '#/components/responses/Error'],
            ],
        ];

        $body = $this->request_body_for_route($method, $path);
        if ($body !== null) {
            $operation['requestBody'] = $body;
        }

        return $operation;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function path_parameters(string $path): array
    {
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $path, $matches);
        $parameters = [];
        foreach ($matches[1] ?? [] as $name) {
            $parameters[] = [
                'name' => (string) $name,
                'in' => 'path',
                'required' => true,
                'schema' => [
                    'type' => 'string',
                    'pattern' => '^[a-z0-9-]+$',
                ],
            ];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function request_body_for_route(string $method, string $path): ?array
    {
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        $schema = match (true) {
            str_contains($path, '/provider-credentials') => ['$ref' => '#/components/schemas/CredentialSaveRequest'],
            str_contains($path, '/provider-models') && str_ends_with($path, '/manual') => ['$ref' => '#/components/schemas/ManualModelsRequest'],
            str_contains($path, '/llm/') && str_ends_with($path, '/tool-call') => ['$ref' => '#/components/schemas/LlmToolCallRequest'],
            str_contains($path, '/llm/') => ['$ref' => '#/components/schemas/LlmCompleteRequest'],
            str_contains($path, '/agent-runtime/fix-suggestions') => ['$ref' => '#/components/schemas/AgentFixSuggestionsRequest'],
            str_contains($path, '/agent-runtime/turn') => ['$ref' => '#/components/schemas/AgentRuntimeTurnRequest'],
            default => ['type' => 'object', 'additionalProperties' => true],
        };

        return [
            'required' => !str_contains($path, '/test') && !str_contains($path, '/health') && !str_contains($path, '/smoke'),
            'content' => [
                'application/json' => [
                    'schema' => $schema,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function openapi_schemas(array $contract): array
    {
        return [
            'AgentContract' => [
                'type' => 'object',
                'required' => ['schema', 'schemaVersion', 'plugin', 'routes', 'capabilities', 'agentTools', 'security'],
                'properties' => [
                    'schema' => ['type' => 'string', 'const' => 'projectflash.agent.contract'],
                    'schemaVersion' => ['type' => 'integer', 'const' => 1],
                    'generatedAt' => ['type' => 'string', 'format' => 'date-time'],
                    'plugin' => ['$ref' => '#/components/schemas/AgentPlugin'],
                    'routes' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AgentRoute']],
                    'capabilityStates' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'capabilities' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Capability']],
                    'agentTools' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AgentTool']],
                    'providers' => ['$ref' => '#/components/schemas/ProviderContract'],
                    'workflowDependency' => ['$ref' => '#/components/schemas/WorkflowDependency'],
                    'security' => ['$ref' => '#/components/schemas/ContractSecurity'],
                ],
            ],
            'OpenApiDocument' => ['type' => 'object', 'additionalProperties' => true],
            'AgentPlugin' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'version' => ['type' => 'string'],
                    'namespace' => ['type' => 'string'],
                ],
            ],
            'AgentRoute' => [
                'type' => 'object',
                'required' => ['method', 'path', 'permission', 'sideEffect', 'response'],
                'properties' => [
                    'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                    'path' => ['type' => 'string'],
                    'permission' => ['type' => 'string'],
                    'sideEffect' => ['type' => 'boolean'],
                    'response' => ['type' => 'string'],
                ],
            ],
            'Capability' => ['type' => 'object', 'additionalProperties' => true],
            'AgentToolCatalog' => [
                'type' => 'object',
                'required' => ['tools'],
                'properties' => [
                    'tools' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AgentTool']],
                ],
            ],
            'AgentTool' => [
                'type' => 'object',
                'required' => ['name', 'description', 'parameters', 'sideEffect'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'parameters' => ['type' => 'object', 'additionalProperties' => true],
                    'sideEffect' => ['type' => 'boolean'],
                    'permission' => ['type' => 'string'],
                    'endpoints' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                ],
            ],
            'ProviderContract' => [
                'type' => 'object',
                'required' => ['credentialsIncluded', 'modelListsIncluded'],
                'properties' => [
                    'schemaVersion' => ['type' => 'integer'],
                    'families' => ['type' => 'object', 'additionalProperties' => true],
                    'presets' => ['type' => 'object', 'additionalProperties' => true],
                    'credentialsIncluded' => ['type' => 'boolean', 'const' => false],
                    'modelListsIncluded' => ['type' => 'boolean', 'const' => false],
                ],
            ],
            'WorkflowDependency' => [
                'type' => 'object',
                'properties' => [
                    'active' => ['type' => 'boolean'],
                    'namespace' => ['type' => 'string'],
                    'capabilities' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean']],
                ],
            ],
            'ContractSecurity' => [
                'type' => 'object',
                'properties' => [
                    'secretsInContract' => ['type' => 'boolean', 'const' => false],
                    'credentialValuesExposed' => ['type' => 'boolean', 'const' => false],
                    'nonceRequired' => ['type' => 'boolean', 'const' => true],
                ],
            ],
            'ProviderPresetCatalog' => ['type' => 'object', 'additionalProperties' => true],
            'ProviderCredentialCatalog' => [
                'type' => 'object',
                'properties' => [
                    'credentials' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ProviderCredentialStatus']],
                ],
            ],
            'ProviderCredentialStatus' => [
                'type' => 'object',
                'properties' => [
                    'providerId' => ['type' => 'string'],
                    'label' => ['type' => 'string'],
                    'family' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'configured' => ['type' => 'boolean'],
                    'maskedKey' => ['type' => ['string', 'null']],
                    'settings' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    'validationMessage' => ['type' => 'string'],
                ],
            ],
            'ProviderModelCatalog' => ['type' => 'object', 'additionalProperties' => true],
            'ProviderHealthResult' => ['type' => 'object', 'additionalProperties' => true],
            'ProviderGenerationResult' => ['type' => 'object', 'additionalProperties' => true],
            'AgentInternalDocs' => [
                'type' => 'object',
                'required' => ['schema', 'schemaVersion', 'source', 'summary', 'sections', 'secretsIncluded'],
                'properties' => [
                    'schema' => ['type' => 'string', 'const' => 'projectflash.agent.internal_docs'],
                    'schemaVersion' => ['type' => 'integer', 'const' => 1],
                    'generatedAt' => ['type' => 'string', 'format' => 'date-time'],
                    'source' => ['type' => 'string', 'const' => 'generated_from_runtime_contract'],
                    'summary' => ['type' => 'object', 'additionalProperties' => true],
                    'sections' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                    'markdown' => ['type' => 'string'],
                    'secretsIncluded' => ['type' => 'boolean', 'const' => false],
                ],
            ],
            'AgentFixSuggestionsRequest' => [
                'type' => 'object',
                'properties' => [
                    'error' => ['type' => 'object', 'additionalProperties' => true],
                    'timeline' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                    'tool' => ['type' => ['object', 'string']],
                    'evidence' => ['type' => 'object', 'additionalProperties' => true],
                    'result' => ['type' => 'object', 'additionalProperties' => true],
                ],
            ],
            'AgentFixSuggestionsResult' => [
                'type' => 'object',
                'required' => ['schema', 'schemaVersion', 'status', 'suggestions'],
                'properties' => [
                    'schema' => ['type' => 'string', 'const' => 'projectflash.agent.fix_suggestions'],
                    'schemaVersion' => ['type' => 'integer', 'const' => 1],
                    'generatedAt' => ['type' => 'string', 'format' => 'date-time'],
                    'status' => ['type' => 'string'],
                    'inputSummary' => ['type' => 'object', 'additionalProperties' => true],
                    'suggestions' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                ],
            ],
            'CredentialSaveRequest' => [
                'type' => 'object',
                'required' => ['apiKey'],
                'properties' => [
                    'apiKey' => ['type' => 'string', 'writeOnly' => true],
                    'settings' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                ],
            ],
            'ManualModelsRequest' => [
                'type' => 'object',
                'required' => ['models'],
                'properties' => [
                    'models' => ['type' => 'array', 'items' => ['type' => ['string', 'object']]],
                ],
            ],
            'LlmMessage' => [
                'type' => 'object',
                'required' => ['role', 'content'],
                'properties' => [
                    'role' => ['type' => 'string', 'enum' => ['system', 'user', 'assistant']],
                    'content' => ['type' => 'string'],
                ],
            ],
            'LlmCompleteRequest' => [
                'type' => 'object',
                'properties' => [
                    'messages' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/LlmMessage']],
                    'prompt' => ['type' => 'string'],
                    'options' => ['type' => 'object', 'additionalProperties' => true],
                ],
            ],
            'LlmToolCallRequest' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/LlmCompleteRequest'],
                    [
                        'type' => 'object',
                        'required' => ['tools'],
                        'properties' => [
                            'tools' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AgentTool']],
                        ],
                    ],
                ],
            ],
            'LlmGatewayResult' => ['type' => 'object', 'additionalProperties' => true],
            'LlmStreamSse' => [
                'type' => 'object',
                'description' => 'Server-Sent Events stream. Events are emitted as text/event-stream and include start, delta, done and error event payloads.',
                'additionalProperties' => true,
            ],
            'AgentRuntimeTurnRequest' => [
                'type' => 'object',
                'required' => ['providerId'],
                'properties' => [
                    'providerId' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'confirmed' => ['type' => 'boolean'],
                    'confirmationId' => ['type' => 'string'],
                ],
            ],
            'AgentRuntimeTurnResult' => ['type' => 'object', 'additionalProperties' => true],
            'VfsLibraryRefresh' => ['type' => 'object', 'additionalProperties' => true],
            'AgentRuntimeProgress' => ['type' => 'object', 'additionalProperties' => true],
            'AgentPermissionRules' => ['type' => 'object', 'additionalProperties' => true],
            'ActiveLlmSelection' => ['type' => 'object', 'additionalProperties' => true],
            'Error' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'data' => ['type' => 'object', 'additionalProperties' => true],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema_ref(string $response): string
    {
        $response = str_replace('|WP_Error', '', $response);

        return preg_replace('/[^A-Za-z0-9_]/', '', $response) ?: 'Error';
    }

    private function summary_for_route(string $method, string $path): string
    {
        return $method . ' ' . $path;
    }

    /**
     * @return array<string, mixed>
     */
    private static function route(string $method, string $path, string $permission, bool $side_effect, string $response): array
    {
        return [
            'method' => $method,
            'path' => $path,
            'permission' => $permission,
            'sideEffect' => $side_effect,
            'response' => $response,
        ];
    }
}

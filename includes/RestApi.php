<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestApi
{
    private const NAMESPACE = 'wp-pfagent/v1';

    private ProviderPresets $presets;
    private CredentialStore $credentials;
    private ProviderModelDiscovery $models;
    private ProviderHealth $health;
    private ProviderRuntime $runtime;
    private AgentToolRegistry $agent_tools;
    private FrameworkRuntime $framework_runtime;
    private AgentContract $agent_contract;
    private AgentFixAdvisor $fix_advisor;
    private AgentInternalDocs $internal_docs;
    private RateLimiter $rate_limiter;
    private TraceLogger $trace_logger;
    private BetaReadiness $beta_readiness;

    public function __construct()
    {
        $this->presets = new ProviderPresets();
        $this->credentials = new CredentialStore($this->presets);
        $this->models = new ProviderModelDiscovery($this->credentials, $this->presets);
        $this->health = new ProviderHealth($this->models, $this->credentials, $this->presets);
        $this->runtime = new ProviderRuntime($this->credentials, $this->models);
        $this->agent_tools = new AgentToolRegistry();
        $this->trace_logger = new TraceLogger();
        $this->framework_runtime = new FrameworkRuntime($this->credentials, $this->presets, $this->agent_tools);
        $this->agent_contract = new AgentContract($this->presets, $this->agent_tools);
        $this->fix_advisor = new AgentFixAdvisor();
        $this->internal_docs = new AgentInternalDocs($this->agent_contract);
        $this->rate_limiter = new RateLimiter();
        $this->beta_readiness = new BetaReadiness($this->agent_contract, $this->health, $this->credentials);
    }

    private function enforce_rate(string $bucket): ?WP_Error
    {
        $result = $this->rate_limiter->consume($bucket);
        if ($result instanceof WP_Error) {
            $this->trace_logger->log(
                $this->trace_logger->new_trace_id(),
                TraceLogger::KIND_RATE_LIMIT,
                TraceLogger::STATUS_RATE_LIMITED,
                [
                    'bucket' => $bucket,
                    'errorCode' => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                    'httpStatus' => 429,
                ]
            );

            return $result;
        }

        return null;
    }

    private function enforce_body_size(WP_REST_Request $request, int $max_bytes = 524288): ?WP_Error
    {
        $size = strlen((string) $request->get_body());
        if ($size > $max_bytes) {
            $error = new WP_Error(
                'pfa_payload_too_large',
                sprintf(
                    /* translators: 1: maximum bytes allowed, 2: actual bytes received */
                    __('Request body exceeds %1$d bytes (got %2$d).', 'wp-pfagent'),
                    $max_bytes,
                    $size
                ),
                ['status' => 413, 'maxBytes' => $max_bytes, 'received' => $size]
            );
            $this->trace_logger->log(
                $this->trace_logger->new_trace_id(),
                TraceLogger::KIND_BODY_SIZE,
                TraceLogger::STATUS_FAILED,
                [
                    'errorCode' => 'pfa_payload_too_large',
                    'message' => __('oversized request body', 'wp-pfagent'),
                    'httpStatus' => 413,
                    'received' => $size,
                ]
            );

            return $error;
        }

        return null;
    }

    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/contract', [
            'methods' => 'GET',
            'callback' => [$this, 'contract'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/contract/openapi', [
            'methods' => 'GET',
            'callback' => [$this, 'openapi_contract'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-presets', [
            'methods' => 'GET',
            'callback' => [$this, 'provider_presets'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-credentials', [
            'methods' => 'GET',
            'callback' => [$this, 'credential_statuses'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-credentials/(?P<provider>[a-z0-9-]+)', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'save_credential'],
                'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
                'args' => [
                    'provider' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                ],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_credential'],
                'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
                'args' => [
                    'provider' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-credentials/(?P<provider>[a-z0-9-]+)/rotate', [
            'methods' => 'POST',
            'callback' => [$this, 'save_credential'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-credentials/(?P<provider>[a-z0-9-]+)/test', [
            'methods' => 'POST',
            'callback' => [$this, 'test_credential'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-models/(?P<provider>[a-z0-9-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'provider_models'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'force' => [
                    'required' => false,
                    'type' => 'boolean',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-models/(?P<provider>[a-z0-9-]+)/manual', [
            'methods' => 'POST',
            'callback' => [$this, 'save_manual_provider_models'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-models/(?P<provider>[a-z0-9-]+)/save', [
            'methods' => 'POST',
            'callback' => [$this, 'save_provider_models'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-health/(?P<provider>[a-z0-9-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'provider_health'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/provider-runtime/(?P<provider>[a-z0-9-]+)/smoke', [
            'methods' => 'POST',
            'callback' => [$this, 'generate_smoke'],
            'permission_callback' => [Capabilities::class, 'can_manage_credentials'],
            'args' => [
                'provider' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/tools', [
            'methods' => 'GET',
            'callback' => [$this, 'agent_tools'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/internal-docs', [
            'methods' => 'GET',
            'callback' => [$this, 'agent_internal_docs'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/fix-suggestions', [
            'methods' => 'POST',
            'callback' => [$this, 'agent_fix_suggestions'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        // Framework Loop-backed turn route. The /agent-runtime/turn (v1)
        // route, plus the /llm/{provider}/{complete,stream,tool-call} routes
        // that fed it, were removed during the Sprint C cutover.
        register_rest_route(self::NAMESPACE, '/agent-runtime/turn-v2', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'agent_turn_v2'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/resume-v2', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'agent_resume_v2'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);

        // H5: continue a turn that paused on its wall-clock time budget
        // (response `continuation: true`, status `paused`). No verdict, no
        // token — just the conversationId + the same provider/model. The host
        // calls this transparently until the response is no longer a pause, so
        // long multi-round work spans several short requests instead of one
        // that fatals on max_execution_time.
        register_rest_route(self::NAMESPACE, '/agent-runtime/continue-v2', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'agent_continue_v2'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);

        // Progress polling: the frontend hits this while waiting on
        // /turn-v2 (synchronous request that may take 1-2 minutes for
        // multi-round tool work). Returns the latest tool calls and
        // assistant rounds since the supplied cursor so the chat can
        // surface a localised, customer-facing "working on it" trace
        // ("Analyzing the data model…", "Implementing the logic…") that
        // updates as the agent makes progress. Read-only, no rate-limit
        // concern beyond the standard auth gate.
        register_rest_route(self::NAMESPACE, '/agent-runtime/progress', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'agent_progress'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);

        // Permission ruleset CRUD. Operators paste / edit the JSON; the
        // server validates against PermissionRuleset's schema before
        // storing in wp_pfagent_permission_rules. FrameworkRuntime
        // re-reads on every turn so changes apply without reload.
        register_rest_route(self::NAMESPACE, '/agent-runtime/permission-rules', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_permission_rules'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'save_permission_rules'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/support-export', [
            'methods' => 'GET',
            'callback' => [$this, 'support_export'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/metrics', [
            'methods' => 'GET',
            'callback' => [$this, 'agent_metrics'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        register_rest_route(self::NAMESPACE, '/agent-runtime/beta-readiness', [
            'methods' => 'GET',
            'callback' => [$this, 'beta_readiness'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        // /vfs/active-preview, /vfs/apply, /vfs/discard removed: the VFS
        // no longer carries a separate draft/preview layer — write_file
        // persists straight into wp-pfworkflow as a draft, and the
        // activate_workflow agent tool (sideEffect=true) promotes it
        // to active. Nothing left to preview, apply, or discard.

        register_rest_route(self::NAMESPACE, '/vfs/library-refresh', [
            'methods' => 'POST',
            'callback' => [$this, 'vfs_library_refresh'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
        ]);

        // Active LLM selection. Single source of truth for which provider +
        // model the operator picked in the wizard. Persisted server-side so
        // any plugin (e.g. wp-pfmanagement) can read it without depending
        // on the browser. The frontend reads on mount and writes on change.
        register_rest_route(self::NAMESPACE, '/active-llm', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_active_llm'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'save_active_llm'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);
    }

    public function vfs_library_refresh(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('writes')) {
            return $error;
        }
        // Each plugin owns its TS — ask them to rebuild, then pull.
        if (class_exists('\\ProjectFlash\\Workflow\\Agent\\TypingsBuilder')) {
            \ProjectFlash\Workflow\Agent\TypingsBuilder::rebuild();
        }
        if (class_exists('\\ProjectFlash\\Management\\Agent\\TypingsBuilder')) {
            \ProjectFlash\Management\Agent\TypingsBuilder::rebuild();
        }
        $nodes = Sourcecode\LibraryBuilder::nodesLibrary();
        $manage = Sourcecode\LibraryBuilder::manageLibrary();
        $backfill = Sourcecode\DecompileCache::backfill();
        $templates = Sourcecode\TemplateDecompileCache::backfill();
        return rest_ensure_response([
            'schema' => 'projectflash.agent.vfs.library_refresh',
            'schemaVersion' => 1,
            'nodesBytes' => strlen($nodes),
            'manageBytes' => strlen($manage),
            'workflowsBackfilled' => $backfill,
            'templatesBackfilled' => $templates,
        ]);
    }

    public function beta_readiness(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }

        return rest_ensure_response($this->beta_readiness->evaluate());
    }

    public function provider_presets(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $catalog = $this->presets->catalog();
        if ($catalog instanceof WP_Error) {
            return $catalog;
        }

        return rest_ensure_response($catalog);
    }

    public function contract(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $contract = $this->agent_contract->build();
        if ($contract instanceof WP_Error) {
            return $contract;
        }

        return rest_ensure_response($contract);
    }

    public function openapi_contract(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $contract = $this->agent_contract->openapi();
        if ($contract instanceof WP_Error) {
            return $contract;
        }

        return rest_ensure_response($contract);
    }

    public function credential_statuses(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }

        return rest_ensure_response(['credentials' => $this->credentials->statuses()]);
    }

    public function save_credential(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        $provider_id = sanitize_key((string) $request['provider']);
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        $api_key = (string) ($params['apiKey'] ?? $params['api_key'] ?? '');
        $settings = is_array($params['settings'] ?? null) ? $params['settings'] : [];
        $status = $this->credentials->save($provider_id, $api_key, $settings);
        if ($status instanceof WP_Error) {
            return $status;
        }

        return new WP_REST_Response($status, 200);
    }

    public function delete_credential(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        $status = $this->credentials->delete(sanitize_key((string) $request['provider']));
        if ($status instanceof WP_Error) {
            return $status;
        }

        return rest_ensure_response($status);
    }

    public function test_credential(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        $this->health->check(sanitize_key((string) $request['provider']));
        $status = $this->credentials->status(sanitize_key((string) $request['provider']));
        if ($status instanceof WP_Error) {
            return $status;
        }

        return rest_ensure_response($status);
    }

    public function provider_models(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $result = $this->models->discover(sanitize_key((string) $request['provider']), (bool) $request->get_param('force'));
        if ($result instanceof WP_Error) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public function save_manual_provider_models(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        $params = $request->get_json_params();
        $models = is_array($params) && is_array($params['models'] ?? null) ? $params['models'] : [];
        $result = $this->models->save_manual_models(sanitize_key((string) $request['provider']), $models);
        if ($result instanceof WP_Error) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Persist the wizard's confirmed per-model configuration into the
     * credential's settings.models[]. This is what runtime gateways read
     * to discover caps + pricing + features — i.e. the user-owned source
     * of truth, parallel to the user's API key. See CredentialStore::save_models.
     */
    public function save_provider_models(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        $params = $request->get_json_params();
        $models = is_array($params) && is_array($params['models'] ?? null) ? $params['models'] : [];
        $result = $this->credentials->save_models(sanitize_key((string) $request['provider']), $models);
        if ($result instanceof WP_Error) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public function provider_health(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }

        return rest_ensure_response($this->health->check(sanitize_key((string) $request['provider'])));
    }

    public function generate_smoke(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('llm')) {
            return $error;
        }
        $result = $this->runtime->generate_smoke(sanitize_key((string) $request['provider']));
        if ($result instanceof WP_Error) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public function agent_tools(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }

        return rest_ensure_response(['tools' => $this->agent_tools->tools()]);
    }

    public function agent_internal_docs(): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $docs = $this->internal_docs->build();
        if ($docs instanceof WP_Error) {
            return $docs;
        }

        return rest_ensure_response($docs);
    }

    public function agent_fix_suggestions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }

        return rest_ensure_response($this->fix_advisor->suggest($this->json_params($request)));
    }

    public function support_export(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }

        $contract = $this->agent_contract->build();
        if ($contract instanceof WP_Error) {
            return $contract;
        }

        global $wp_version;
        $current = get_current_user_id();
        $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        $recent = $this->trace_logger->query(['user_id' => $current, 'limit' => 50]);
        $failures = $this->trace_logger->query(['user_id' => $current, 'status' => TraceLogger::STATUS_FAILED, 'limit' => 20]);
        $aggregate = $this->trace_logger->aggregate(['user_id' => $current, 'since' => $since]);

        $providers = [];
        foreach ($this->credentials->statuses() as $status) {
            $providers[] = [
                'providerId' => $status['providerId'] ?? '',
                'family' => $status['family'] ?? '',
                'status' => $status['status'] ?? '',
                'configured' => (bool) ($status['configured'] ?? false),
                'maskedKey' => $status['maskedKey'] ?? null,
                'configuredAt' => $status['configuredAt'] ?? null,
                'updatedAt' => $status['updatedAt'] ?? null,
                'validationMessage' => $status['validationMessage'] ?? '',
            ];
        }

        return rest_ensure_response([
            'schema' => 'projectflash.agent.support_export',
            'schemaVersion' => 1,
            'generatedAt' => gmdate('c'),
            'agent' => [
                'schema' => $contract['schema'] ?? '',
                'version' => $contract['plugin']['version'] ?? '',
                'namespace' => $contract['plugin']['namespace'] ?? '',
                'capabilityCount' => is_array($contract['capabilities'] ?? null) ? count($contract['capabilities']) : 0,
                'agentToolCount' => is_array($contract['agentTools'] ?? null) ? count($contract['agentTools']) : 0,
                'routeCount' => is_array($contract['routes'] ?? null) ? count($contract['routes']) : 0,
            ],
            'workflow' => $contract['workflowDependency'] ?? ['active' => false],
            'environment' => [
                'wpVersion' => (string) ($wp_version ?? ''),
                'phpVersion' => PHP_VERSION,
                'locale' => function_exists('get_locale') ? get_locale() : '',
                'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : '',
                'multisite' => is_multisite(),
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
            ],
            'providers' => $providers,
            'metrics' => [
                'window' => '24h',
                'since' => $since,
                'totals' => $aggregate,
            ],
            'recentTraces' => $recent,
            'recentFailures' => $failures,
            'security' => [
                'secretsInExport' => false,
                'credentialValuesExposed' => false,
            ],
        ]);
    }

    public function agent_metrics(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }

        $current = get_current_user_id();
        $window_hours = max(1, min(168, (int) ($request->get_param('windowHours') ?? 24)));
        $since = gmdate('Y-m-d H:i:s', time() - $window_hours * HOUR_IN_SECONDS);

        return rest_ensure_response([
            'schema' => 'projectflash.agent.metrics',
            'schemaVersion' => 1,
            'generatedAt' => gmdate('c'),
            'windowHours' => $window_hours,
            'since' => $since,
            'totals' => $this->trace_logger->aggregate(['user_id' => $current, 'since' => $since]),
        ]);
    }

    public function agent_turn_v2(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('agent_turn')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }
        $params = $this->json_params($request);
        $started_at = microtime(true);
        $result = $this->framework_runtime->turn($params);
        if ($result instanceof WP_Error) {
            return $result;
        }
        $this->log_turn_metric($params, $result, $started_at);

        return rest_ensure_response($result);
    }

    /**
     * Emit one summary row into pfa_trace_log per completed turn so the
     * /agent-runtime/metrics aggregation (which reads pfa_trace_log) reflects
     * real agent activity. The Sprint C cutover moved per-round telemetry to
     * the Framework store (pfaf_traces) but never re-pointed the metrics
     * aggregation nor emitted a per-turn summary, so metrics read 0 rows for
     * every real turn. This restores the agent_turn count + token/cost totals
     * without touching the per-round trace surface.
     *
     * @param array<string, mixed> $params The turn input (providerId, model).
     * @param array<string, mixed> $result The serialised turn result.
     */
    private function log_turn_metric(array $params, array $result, float $started_at): void
    {
        $subtype = (string) ($result['subtype'] ?? '');
        $status = match (true) {
            $subtype === 'success' => TraceLogger::STATUS_SUCCESS,
            $subtype === 'needs_confirmation' => TraceLogger::STATUS_NEEDS_CONFIRMATION,
            default => TraceLogger::STATUS_FAILED,
        };
        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $this->trace_logger->log(
            $this->trace_logger->new_trace_id(),
            TraceLogger::KIND_AGENT_TURN,
            $status,
            [
                'providerId' => sanitize_key((string) ($params['providerId'] ?? '')),
                'tokensTotal' => (int) ($usage['totalTokens'] ?? 0),
                'costMicros' => (int) ($result['costMicros'] ?? 0),
                'durationMs' => (int) round((microtime(true) - $started_at) * 1000),
                'context' => [
                    'conversationId' => (int) ($result['conversationId'] ?? 0),
                    'rounds' => (int) ($result['rounds'] ?? 0),
                    'model' => (string) ($params['model'] ?? ''),
                ],
            ]
        );
    }

    public function get_permission_rules(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $stored = get_option('wp_pfagent_permission_rules', []);
        return rest_ensure_response([
            'rules' => is_array($stored) ? $stored : [],
            'updatedAt' => (string) get_option('wp_pfagent_permission_rules_updated_at', ''),
        ]);
    }

    public function save_permission_rules(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }
        $params = $this->json_params($request);
        $rules = is_array($params['rules'] ?? null) ? $params['rules'] : null;
        if ($rules === null) {
            return new WP_Error('pfa_permission_rules_invalid', __('rules must be a JSON object.', 'wp-pfagent'), ['status' => 400]);
        }
        // Sanity-check: PermissionRuleset tolerates unknown shapes (verdicts
        // outside allow/deny/ask are simply ignored at evaluate time), so
        // we accept whatever the operator pastes. The validation is
        // primarily about JSON shape, not content.
        $now = gmdate('c');
        update_option('wp_pfagent_permission_rules', $rules, false);
        update_option('wp_pfagent_permission_rules_updated_at', $now, false);
        return rest_ensure_response([
            'rules' => $rules,
            'updatedAt' => $now,
        ]);
    }

    public function get_active_llm(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('reads')) {
            return $error;
        }
        $stored = get_option('wp_pfagent_active_llm', []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return rest_ensure_response([
            'providerId' => (string) ($stored['providerId'] ?? ''),
            'model' => (string) ($stored['model'] ?? ''),
            'sessionId' => isset($stored['sessionId']) ? (int) $stored['sessionId'] : null,
            'updatedAt' => (string) ($stored['updatedAt'] ?? ''),
        ]);
    }

    public function save_active_llm(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('config')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }
        $params = $this->json_params($request);
        $providerId = sanitize_key((string) ($params['providerId'] ?? ''));
        $model = sanitize_text_field((string) ($params['model'] ?? ''));
        $sessionId = isset($params['sessionId']) && $params['sessionId'] !== null
            ? (int) $params['sessionId']
            : null;
        $now = gmdate('c');
        $payload = [
            'providerId' => $providerId,
            'model' => $model,
            'sessionId' => $sessionId,
            'updatedAt' => $now,
        ];
        update_option('wp_pfagent_active_llm', $payload, false);
        return rest_ensure_response($payload);
    }

    public function agent_resume_v2(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('agent_turn')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }
        $params = $this->json_params($request);
        $conversationId = (int) ($params['conversationId'] ?? 0);
        $token = (string) ($params['confirmationToken'] ?? '');
        $approved = (bool) ($params['approved'] ?? false);
        $providerId = (string) ($params['providerId'] ?? '');
        $model = (string) ($params['model'] ?? '');

        if ($conversationId <= 0 || $token === '' || $providerId === '' || $model === '') {
            return new WP_Error('pfa_resume_invalid', __('conversationId, confirmationToken, providerId and model are required.', 'wp-pfagent'), ['status' => 400]);
        }

        $started_at = microtime(true);
        $result = $this->framework_runtime->resume($conversationId, $token, $approved, $providerId, $model);
        if ($result instanceof WP_Error) {
            return $result;
        }
        $this->log_turn_metric($params, $result, $started_at);

        return rest_ensure_response($result);
    }

    /**
     * H5: continue a turn that paused on its time budget. Same auth + rate gate
     * as a turn; no confirmation token — just conversationId + provider/model.
     */
    public function agent_continue_v2(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->enforce_rate('agent_turn')) {
            return $error;
        }
        if ($error = $this->enforce_body_size($request)) {
            return $error;
        }
        $params = $this->json_params($request);
        $conversationId = (int) ($params['conversationId'] ?? 0);
        $providerId = (string) ($params['providerId'] ?? '');
        $model = (string) ($params['model'] ?? '');

        if ($conversationId <= 0 || $providerId === '' || $model === '') {
            return new WP_Error('pfa_continue_invalid', __('conversationId, providerId and model are required.', 'wp-pfagent'), ['status' => 400]);
        }

        $started_at = microtime(true);
        $result = $this->framework_runtime->continueTurn($conversationId, $providerId, $model);
        if ($result instanceof WP_Error) {
            return $result;
        }
        $this->log_turn_metric($params, $result, $started_at);

        return rest_ensure_response($result);
    }

    /**
     * Live-progress poll: returns whatever tool calls and assistant rounds
     * for the conversation have landed since the cursors the frontend
     * sends. Used by the chat to surface a customer-facing "working on
     * it" trace while /turn-v2 is still executing synchronously. The
     * shape is intentionally minimal — names and timestamps only, no
     * internal arguments or payloads, because the customer must not see
     * implementation details (per the system prompt's hard rule).
     */
    public function agent_progress(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $conversationId = (int) ($request->get_param('conversationId') ?? 0);
        if ($conversationId <= 0) {
            return new WP_Error('pfa_progress_invalid', __('conversationId is required.', 'wp-pfagent'), ['status' => 400]);
        }
        $sinceToolCallId = max(0, (int) ($request->get_param('sinceToolCallId') ?? 0));
        $sinceTraceId = max(0, (int) ($request->get_param('sinceTraceId') ?? 0));
        // sinceMessageOrdinal lets the frontend stream new assistant
        // narrations bubble-by-bubble during a long turn instead of
        // waiting for /turn-v2 to return. -1 means "I haven't seen any
        // yet"; the value the poll returns in lastMessageOrdinal is
        // the cursor for the next request.
        $sinceMessageOrdinal = max(-1, (int) ($request->get_param('sinceMessageOrdinal') ?? -1));

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return new WP_Error('pfa_progress_db', __('Database unavailable.', 'wp-pfagent'), ['status' => 500]);
        }
        $tc = $wpdb->prefix . 'pfaf_tool_calls';
        $tr = $wpdb->prefix . 'pfaf_traces';
        $msgs = $wpdb->prefix . 'pfaf_messages';

        $tool_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, tool_name, status, started_at, arguments_json, result_json FROM {$tc}
             WHERE conversation_id = %d AND id > %d
             ORDER BY id ASC LIMIT 50",
            $conversationId,
            $sinceToolCallId
        ), ARRAY_A);

        $trace_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, kind, created_at FROM {$tr}
             WHERE conversation_id = %d AND id > %d
               AND kind IN ('llm_round', 'compaction_applied')
             ORDER BY id ASC LIMIT 50",
            $conversationId,
            $sinceTraceId
        ), ARRAY_A);

        // New assistant narrations since the cursor. Mid-loop rows the
        // Loop persists between tool rounds — "Voy a hacer X", "Ahora
        // creo Y" — must surface live so the operator sees the agent's
        // thinking as it happens, not as a burst at end-of-turn. We
        // mirror serialize_full's filter: role=assistant + non-empty
        // content (rows with only tool_calls are implementation
        // detail).
        $msg_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ordinal, content_json, created_at FROM {$msgs}
             WHERE conversation_id = %d AND role = 'assistant' AND ordinal > %d
             ORDER BY ordinal ASC LIMIT 50",
            $conversationId,
            $sinceMessageOrdinal
        ), ARRAY_A);

        $tools = array_map(static function (array $r): array {
            $args = json_decode((string) ($r['arguments_json'] ?? ''), true);
            $res = json_decode((string) ($r['result_json'] ?? ''), true);
            $argsArr = is_array($args) ? $args : [];
            $resArr = is_array($res) ? $res : [];
            // Compact focus payload: kind/ref for pfm tools,
            // workflowId/path for VFS tools. Lets the frontend rebuild
            // the iframe URL without a second fetch.
            $focus = [];
            foreach (['kind', 'ref', 'path'] as $k) {
                if (isset($argsArr[$k]) && is_scalar($argsArr[$k])) {
                    $focus[$k] = (string) $argsArr[$k];
                }
            }
            // pfm_apply doesn't carry `ref` at the top level — the slug
            // / sys_id lives in payload, with a shape that varies per
            // kind. Without this extraction the iframe URL had
            // kind=entity but no ref, and the pfm SPA stayed on its
            // landing page ("Bienvenido") instead of focusing on the
            // entity / record the agent just touched. Mirror the same
            // unwrap the frontend's buildPreviewTargetFromExecution
            // does so the pfm tab dances with the agent's activity.
            if (
                ($r['tool_name'] ?? '') === 'pfm_apply'
                && (!isset($focus['ref']) || $focus['ref'] === '')
            ) {
                $payload = isset($argsArr['payload']) && is_array($argsArr['payload']) ? $argsArr['payload'] : null;
                $kind = $focus['kind'] ?? '';
                if (is_array($payload) && $kind !== '') {
                    $ref = '';
                    if ($kind === 'record') {
                        // record: { entity: 'slug', values: {...}, sys_id? }
                        $eslug = isset($payload['entity']) && is_string($payload['entity']) ? $payload['entity'] : '';
                        $sysId = '';
                        foreach (['sys_id', 'id'] as $k) {
                            if (isset($payload[$k]) && is_scalar($payload[$k]) && (string) $payload[$k] !== '') {
                                $sysId = (string) $payload[$k];
                                break;
                            }
                        }
                        if ($eslug !== '' && $sysId !== '') {
                            $ref = $eslug . ':' . $sysId;
                        } elseif ($eslug !== '') {
                            // No sys_id yet (create record): point the
                            // tab at the entity's record list. Better
                            // than leaving the SPA on landing.
                            $ref = $eslug;
                        }
                    } else {
                        // entity / action / application / module /
                        // group / role / page: payload[kind].slug, with
                        // a legacy bare-payload.slug fallback.
                        $inner = isset($payload[$kind]) && is_array($payload[$kind]) ? $payload[$kind] : $payload;
                        if (isset($inner['slug']) && is_string($inner['slug'])) {
                            $ref = $inner['slug'];
                        }
                    }
                    if ($ref !== '') {
                        $focus['ref'] = $ref;
                    }
                }
            }
            if (isset($resArr['workflowId']) && is_numeric($resArr['workflowId'])) {
                $focus['workflowId'] = (int) $resArr['workflowId'];
            }
            return [
                'id' => (int) $r['id'],
                'tool' => (string) $r['tool_name'],
                'status' => (string) $r['status'],
                'at' => (string) $r['started_at'],
                'focus' => $focus,
            ];
        }, $tool_rows);

        $traces = array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'kind' => (string) $r['kind'],
            'at' => (string) $r['created_at'],
        ], $trace_rows);

        // Drop empty-content rows here (the LIMIT above already trims
        // them on the next poll because their ordinal advances the
        // cursor regardless). The bumped cursor MUST cover every row
        // we saw, including empties, so the next poll doesn't refetch
        // them.
        $assistantTexts = [];
        foreach ($msg_rows as $row) {
            $ord = (int) ($row['ordinal'] ?? 0);
            $raw = $row['content_json'] ?? '';
            $content = is_string($raw) ? (string) (json_decode($raw, true) ?? '') : '';
            if ($content === '') {
                continue;
            }
            $assistantTexts[] = [
                'ordinal' => $ord,
                'content' => $content,
                'at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        // lastMessageOrdinal must be the TRUE max(ordinal) for this
        // conversation regardless of the LIMIT 50 cap on $msg_rows.
        // Fallback is -1 (no rows yet) — NOT $sinceMessageOrdinal:
        // if the frontend's warm-up call sends a high probe value
        // (e.g. 2147483647 to bypass the LIMIT and just learn the
        // cursor) and the conversation is empty, echoing the probe
        // back as the cursor pins lastSeenNarrationOrdinalRef sky-
        // high in the chat and every subsequent narration on that
        // conversation is filtered out as "already seen". Empty
        // conv MUST return -1 so the chat starts from the real
        // beginning. MAX is monotonic on insert (no row deletion
        // mid-turn), so no "don't go backwards" guard is needed.
        $lastMessageOrdinalSeen = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(ordinal), -1) FROM {$msgs} WHERE conversation_id = %d",
            $conversationId
        ));

        $lastToolCallId = $tools === [] ? $sinceToolCallId : (int) end($tools)['id'];
        $lastTraceId = $traces === [] ? $sinceTraceId : (int) end($traces)['id'];

        return rest_ensure_response([
            'conversationId' => $conversationId,
            'tools' => $tools,
            'traces' => $traces,
            'assistantTexts' => $assistantTexts,
            'lastToolCallId' => $lastToolCallId,
            'lastTraceId' => $lastTraceId,
            'lastMessageOrdinal' => $lastMessageOrdinalSeen,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function json_params(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();

        return is_array($params) ? $params : [];
    }
}

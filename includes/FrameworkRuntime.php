<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use ProjectFlash\Agent\Framework\LlmCompactor;
use ProjectFlash\Agent\Framework\Llm\GatewayFactory;
use ProjectFlash\Agent\Framework\Llm\ModelCatalog;
use ProjectFlash\Agent\Framework\Loop;
use ProjectFlash\Agent\Framework\LoopOptions;
use ProjectFlash\Agent\Framework\LoopResult;
use ProjectFlash\Agent\Framework\OutputFilter;
use ProjectFlash\Agent\Framework\PermissionRuleset;
use ProjectFlash\Agent\Framework\Tools\Registry;
use ProjectFlash\Agent\Framework\WordPress\HumanModalApprovalGate;
use ProjectFlash\Agent\Framework\WordPress\Storage\WpDbStore;
use ProjectFlash\Agent\Framework\WordPress\Tools\FilterBridgeTool;
use ProjectFlash\Agent\Framework\WordPress\TransientApprovalStore;
use WP_Error;

/**
 * Bridge between wp-pfagent's REST surface and the multi-provider Framework
 * Loop. Builds, on each `turn()` call, everything the Loop needs:
 *
 *  - WpDbStore over wp_pfaf_* tables (created by the activation hook).
 *  - Registry of FilterBridgeTool instances mirroring agent-tools.json — so
 *    the LLM sees the same tool surface as the legacy AgentRuntime, but
 *    routed through the Framework's loop discipline (fingerprinting,
 *    idempotency, oscillation detection, side-effect approval).
 *  - Gateway selected by the active credential's preset family
 *    (OpenAiCompatibleGateway / AnthropicGateway / GeminiGateway), with the
 *    ModelCatalog wired in for cost + caps fallback.
 *
 * No state is held between calls; the WpDbStore IS the state.
 */
final class FrameworkRuntime
{
    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly ProviderPresets $presets,
        private readonly AgentToolRegistry $toolRegistry
    ) {
    }

    /**
     * Run one user message through the Loop. Returns a normalised result
     * envelope suitable for the REST response.
     *
     * @param array{providerId: string, model?: string, message: string, conversationId?: ?int, label?: string} $input
     * @return array<string, mixed>|WP_Error
     */
    public function turn(array $input): array|WP_Error
    {
        $providerId = sanitize_key((string) ($input['providerId'] ?? ''));
        if ($providerId === '') {
            return new WP_Error('pfa_runtime_provider_required', __('providerId is required.', 'wp-pfagent'), ['status' => 400]);
        }

        $userMessage = trim((string) ($input['message'] ?? ''));
        if ($userMessage === '') {
            return new WP_Error('pfa_runtime_message_required', __('message is required.', 'wp-pfagent'), ['status' => 400]);
        }

        $context = $this->credentials->runtime_context($providerId);
        if ($context instanceof WP_Error) {
            return $context;
        }

        $model = trim((string) ($input['model'] ?? ''));
        if ($model === '') {
            return new WP_Error('pfa_runtime_model_required', __('model is required for the framework runtime.', 'wp-pfagent'), ['status' => 400]);
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return new WP_Error('pfa_runtime_wpdb_missing', __('WordPress database is not available.', 'wp-pfagent'), ['status' => 500]);
        }

        try {
            $loop = $this->buildLoop($wpdb, $context, $providerId, $model);
        } catch (\Throwable $e) {
            return new WP_Error('pfa_runtime_build_failed', $e->getMessage(), ['status' => 500]);
        }

        $conversationId = isset($input['conversationId']) ? (int) $input['conversationId'] : null;
        if ($conversationId !== null && $conversationId <= 0) {
            $conversationId = null;
        }
        $label = (string) ($input['label'] ?? '');

        $turnStartIso = gmdate('c');
        try {
            $result = $loop->run($conversationId, $userMessage, $label !== '' ? $label : null);
        } catch (\Throwable $e) {
            return new WP_Error('pfa_runtime_turn_failed', $e->getMessage(), ['status' => 500]);
        }

        return $this->serialise($result, $wpdb, $turnStartIso);
    }

    /**
     * Resume after a side-effect approval verdict.
     */
    public function resume(int $conversationId, string $confirmationToken, bool $approved, string $providerId, string $model): array|WP_Error
    {
        $context = $this->credentials->runtime_context($providerId);
        if ($context instanceof WP_Error) {
            return $context;
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return new WP_Error('pfa_runtime_wpdb_missing', __('WordPress database is not available.', 'wp-pfagent'), ['status' => 500]);
        }

        $turnStartIso = gmdate('c');
        try {
            $loop = $this->buildLoop($wpdb, $context, $providerId, $model);
            $result = $loop->resume($conversationId, $confirmationToken, $approved);
        } catch (\Throwable $e) {
            return new WP_Error('pfa_runtime_resume_failed', $e->getMessage(), ['status' => 500]);
        }

        return $this->serialise($result, $wpdb, $turnStartIso);
    }

    /**
     * Wire the Loop from per-request context. Pure factory — no caching
     * across calls so a credential change is picked up immediately.
     *
     * @param array<string, mixed> $context  CredentialStore::runtime_context shape
     */
    private function buildLoop(\wpdb $wpdb, array $context, string $providerId, string $model): Loop
    {
        $store = new WpDbStore($wpdb);
        $registry = $this->buildRegistry();
        $approvalStore = new TransientApprovalStore();

        // Per-turn ModelCatalog assembled from the credential's confirmed
        // models[] (populated by the wizard from API discovery + user-entered
        // pricing/caps). A credential change is picked up immediately — no
        // cross-turn caching.
        $catalog = $this->buildCatalog($context);
        $factory = new GatewayFactory($catalog);

        $gateway = $factory->build([
            'family' => (string) ($context['preset']['family'] ?? ''),
            'apiKey' => (string) ($context['apiKey'] ?? ''),
            'baseUrl' => $this->resolveBaseUrl($context),
            'settings' => is_array($context['settings'] ?? null) ? $context['settings'] : [],
            'timeout' => 120,
        ]);

        $systemPrompt = $this->systemPrompt($context);

        // Per-model `defaultReasoningEffort` saved on the credential via the
        // wizard wins over any host default (Kilo Tier 2.4). null means
        // "let the model decide" / "no reasoning extension".
        $modelRecord = $this->credentials->model($providerId, $model);
        $reasoningEffort = is_array($modelRecord) && isset($modelRecord['defaultReasoningEffort']) && is_string($modelRecord['defaultReasoningEffort'])
            ? (string) $modelRecord['defaultReasoningEffort']
            : null;

        // Auto-compactor: when the running prompt estimate reaches the
        // model's context window, the Loop folds older history into an
        // anchored summary instead of overflowing. Prefer the credential's
        // small_model_id when the operator set one (Kilo Tier 2.5) so the
        // compaction LLM call is cheap; fall back to the active model.
        $settings = is_array($context['settings'] ?? null) ? $context['settings'] : [];
        $smallModel = is_string($settings['small_model_id'] ?? null) && (string) $settings['small_model_id'] !== ''
            ? (string) $settings['small_model_id']
            : $model;
        $compactor = new LlmCompactor($gateway, $smallModel);

        return new Loop(
            store: $store,
            registry: $registry,
            gateway: $gateway,
            systemPrompt: $systemPrompt,
            outputFilter: new OutputFilter(),
            options: new LoopOptions(
                approvalStore: $approvalStore,
                reasoningEffort: $reasoningEffort,
            ),
            approval: new HumanModalApprovalGate($this->permissionRuleset()),
            compactor: $compactor,
            activeProviderId: $providerId,
            activeModel: $model,
        );
    }

    /**
     * Build the per-turn PermissionRuleset (Kilo Tier 2.2). Merges, in
     * precedence order:
     *   1. wp_pfagent_permission_rules option (operator-configured)
     *   2. pfa_permission_rules filter (host-supplied at runtime)
     * The latter wins on key collisions so hosts can pin specific tool
     * verdicts above whatever the operator stored.
     *
     * When neither produces a non-empty map the ruleset is left empty
     * (effectively defaulting every side-effect tool to PENDING, the
     * pre-Sprint-D behaviour).
     */
    private function permissionRuleset(): PermissionRuleset
    {
        $stored = get_option('wp_pfagent_permission_rules', []);
        $rules = is_array($stored) ? $stored : [];

        if (function_exists('apply_filters')) {
            /**
             * Filter the permission ruleset before it's handed to the gate.
             * Hosts can append / override specific tool verdicts. The
             * filter receives the option-loaded array; return the
             * (possibly modified) array.
             *
             * @param array<string, mixed> $rules
             */
            $filtered = apply_filters('pfa_permission_rules', $rules);
            if (is_array($filtered)) {
                $rules = $filtered;
            }
        }

        return new PermissionRuleset($rules);
    }

    /**
     * Build a ModelCatalog for this turn from the credential's user-confirmed
     * per-model records (the wizard's output). Falls back to the legacy JSON
     * file only when the credential has no models[] saved yet, so existing
     * deployments keep working until their owner runs the wizard.
     *
     * @param array<string, mixed> $context  CredentialStore::runtime_context shape
     */
    private function buildCatalog(array $context): ?ModelCatalog
    {
        $settings = is_array($context['settings'] ?? null) ? $context['settings'] : [];
        $models = is_array($settings['models'] ?? null) ? $settings['models'] : [];
        if ($models !== []) {
            return ModelCatalog::fromArray($models);
        }

        $path = WP_PFAGENT_DIR . 'config/model-catalog.json';
        if (!file_exists($path)) {
            return null;
        }
        return ModelCatalog::fromFile($path);
    }

    /**
     * Build a Registry by iterating agent-tools.json — exactly the same
     * surface AgentToolRegistry exposes today, but adapted to the Framework
     * Tool contract via FilterBridgeTool.
     */
    private function buildRegistry(): Registry
    {
        $registry = new Registry();
        $tools = $this->toolRegistry->tools();

        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $name = (string) ($tool['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $phpService = is_array($tool['phpService'] ?? null) ? $tool['phpService'] : [];
            $filter = (string) ($phpService['filter'] ?? '');
            $method = (string) ($phpService['method'] ?? $name);
            if ($filter === '') {
                continue;
            }

            // sideEffect comes from the agent-tools manifest. When the LLM
            // calls a side-effect tool, the Loop pauses for approval before
            // execution (host UI surfaces the confirmation dialog).
            $sideEffect = (bool) ($tool['sideEffect'] ?? $phpService['sideEffect'] ?? false);
            $idempotent = (bool) ($tool['idempotent'] ?? $phpService['idempotent'] ?? false);
            $argMapping = is_array($phpService['argMapping'] ?? null)
                ? array_values(array_map('strval', $phpService['argMapping']))
                : null;

            // Normalize the JSON Schema so empty `{}` objects (decoded as
            // PHP arrays) become stdClass — otherwise json_encode emits []
            // and OpenAI-compat providers (DeepSeek) reject the tool with
            // "Invalid schema for function ...: [] is not of type object".
            // The legacy AgentRuntime path consumed llm_tool_definitions()
            // which already normalised; the Framework path needs the same
            // treatment since it reads tools() raw.
            $rawParameters = is_array($tool['parameters'] ?? null) ? $tool['parameters'] : ['type' => 'object', 'properties' => new \stdClass()];
            $parameters = $this->toolRegistry->normalize_json_schema($rawParameters);
            if (!is_array($parameters)) {
                $parameters = ['type' => 'object', 'properties' => new \stdClass()];
            }

            $registry->register(new FilterBridgeTool(
                name: $name,
                description: (string) ($tool['description'] ?? ''),
                parameters: $parameters,
                filter: $filter,
                method: $method,
                sideEffect: $sideEffect,
                idempotent: $idempotent,
                stateExtractor: null,
                argMapping: $argMapping,
                strict: false,
            ));
        }

        return $registry;
    }

    /**
     * Resolve the base URL for the active credential — handles preset
     * baseUrl templates with {{base_url}} (custom-* presets) by substituting
     * from the credential's settings.
     *
     * @param array<string, mixed> $context
     */
    private function resolveBaseUrl(array $context): string
    {
        $preset = is_array($context['preset'] ?? null) ? $context['preset'] : [];
        $settings = is_array($context['settings'] ?? null) ? $context['settings'] : [];
        $template = (string) ($preset['baseUrl'] ?? '');
        return (string) preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', static function (array $m) use ($settings): string {
            $key = sanitize_key((string) $m[1]);
            return array_key_exists($key, $settings) ? (string) $settings[$key] : (string) $m[0];
        }, $template);
    }

    /**
     * The operator-facing system prompt now lives in its own SystemPrompt
     * class (post-Sprint-C cleanup). Family-aware: hosts can register
     * different prompts per provider family via the
     * pfa_system_prompt_for_family filter (Kilo Tier 2.3).
     *
     * @param array<string, mixed> $context CredentialStore::runtime_context shape
     */
    private function systemPrompt(array $context = []): string
    {
        $family = (string) ($context['preset']['family'] ?? '');
        return SystemPrompt::forFamily($family);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(LoopResult $result, ?\wpdb $wpdb = null, string $turnStartIso = ''): array
    {
        $status = $this->mapSubtype($result->subtype);
        $confirmation = $result->confirmationToken === ''
            ? null
            : [
                'token' => $result->confirmationToken,
                'pendingCall' => $result->pendingToolCall,
            ];
        // Surface the pending tool's {name, arguments} at the top level for
        // the v1-shaped frontend (App.tsx renders the confirm modal from
        // result.tool). Empty when there's no pending call.
        $tool = null;
        if (is_array($result->pendingToolCall) && isset($result->pendingToolCall['name'])) {
            $tool = [
                'name' => (string) $result->pendingToolCall['name'],
                'arguments' => is_array($result->pendingToolCall['arguments'] ?? null)
                    ? $result->pendingToolCall['arguments']
                    : [],
            ];
        }

        $executions = $this->collectExecutions($wpdb, $result->conversationId, $turnStartIso);
        $assistantTexts = $this->collectAssistantTexts($wpdb, $result->conversationId, $turnStartIso);

        return [
            'status' => $status,
            'subtype' => $result->subtype,
            'conversationId' => $result->conversationId,
            'finalText' => $result->finalText,
            // v1-compat: the legacy /turn shape used `message` for the
            // assistant text, `confirmationId` + `tool` for the approval
            // payload, and `executions`/`timeline` for the tool trail. We
            // mirror them so the existing frontend renders v2 results
            // without needing a structural refactor of App.tsx.
            'message' => $result->finalText,
            // Every non-empty assistant bubble the Loop persisted in
            // this turn, in order. Frontend pushes each one as its own
            // chat bubble so the live chat matches what rehydration
            // shows when the operator reopens the session — no
            // hidden mid-loop narration ("Voy a hacer X", "Ahora creo
            // Y") that only surfaces on reload.
            'assistantTexts' => $assistantTexts,
            'confirmationId' => $result->confirmationToken,
            'tool' => $tool,
            'tools' => $tool !== null ? [$tool] : [],
            'evidence' => new \stdClass(),
            'executions' => $executions,
            'timeline' => [],
            'llmError' => $status === 'completed_with_response_error' && $result->errorMessage !== ''
                ? ['message' => $result->errorMessage]
                : null,
            'rounds' => $result->rounds,
            'usage' => $result->usage,
            'costMicros' => $result->costMicros,
            'errorMessage' => $result->errorMessage,
            'confirmation' => $confirmation,
        ];
    }

    /**
     * Read every tool_call row this turn produced and shape it as the
     * frontend's AgentRuntimeExecution list. The Loop writes one row per
     * call into wp_pfaf_tool_calls (success or error) via WpDbStore::
     * logToolCall — we just read them back filtered to the turn window so
     * the chat surfaces a real "N execution(s)" disclosure block instead of
     * the empty array that lived here before.
     *
     * @return list<array<string, mixed>>
     */
    private function collectExecutions(?\wpdb $wpdb, int $conversationId, string $turnStartIso): array
    {
        if ($wpdb === null || $conversationId <= 0 || $turnStartIso === '') {
            return [];
        }
        $table = $wpdb->prefix . 'pfaf_tool_calls';
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT tool_name, arguments_json, status, result_json, state_after_json,
                    error_code, error_message, duration_ms, started_at, ended_at
             FROM {$table}
             WHERE conversation_id = %d AND started_at >= %s
             ORDER BY id ASC",
            $conversationId,
            $turnStartIso,
        ), ARRAY_A);
        if ($rows === []) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $args = json_decode((string) ($row['arguments_json'] ?? ''), true);
            $stateAfter = json_decode((string) ($row['state_after_json'] ?? ''), true);
            $resultPayload = json_decode((string) ($row['result_json'] ?? ''), true);
            $entry = [
                'tool' => [
                    'name' => (string) ($row['tool_name'] ?? ''),
                    'arguments' => is_array($args) ? $args : [],
                ],
                'evidence' => is_array($stateAfter) ? $stateAfter : new \stdClass(),
                'result' => $resultPayload,
                'diff' => null,
                'startedAt' => (string) ($row['started_at'] ?? ''),
                'endedAt' => (string) ($row['ended_at'] ?? ''),
                'durationMs' => (int) ($row['duration_ms'] ?? 0),
                'status' => ((string) ($row['status'] ?? '') === 'ok') ? 'success' : 'error',
            ];
            $errorCode = (string) ($row['error_code'] ?? '');
            $errorMessage = (string) ($row['error_message'] ?? '');
            if ($errorCode !== '') {
                $entry['errorCode'] = $errorCode;
            }
            if ($errorMessage !== '') {
                $entry['errorMessage'] = $errorMessage;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Read every non-empty assistant message the Loop persisted during
     * this turn so the frontend can render them in real time, in the
     * same order rehydration would show them. This is the missing
     * piece that produced the operator's confusion: during a live
     * chat the UI only saw the final assistant reply, but reopening
     * the session re-rendered every mid-loop narration ("Voy a hacer
     * X", "Ahora creo Y") and they looked like fresh content. Emit
     * them as part of the turn response so live == rehydrated.
     *
     * Each entry carries its `ordinal` so the frontend can
     * deduplicate against narrations that already arrived via the
     * /agent-runtime/progress polling stream — without ordinals, a
     * narration that the polling already surfaced would re-appear
     * at end-of-turn as a duplicate bubble.
     *
     * Empty content rows are skipped (those are the narration-less
     * tool-call rounds that have no surface text).
     *
     * @return list<array{ordinal: int, content: string, at: string}>
     */
    private function collectAssistantTexts(?\wpdb $wpdb, int $conversationId, string $turnStartIso): array
    {
        if ($wpdb === null || $conversationId <= 0 || $turnStartIso === '') {
            return [];
        }
        $table = $wpdb->prefix . 'pfaf_messages';
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ordinal, content_json, created_at FROM {$table}
             WHERE conversation_id = %d AND role = 'assistant' AND created_at >= %s
             ORDER BY ordinal ASC",
            $conversationId,
            $turnStartIso,
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $contentRaw = $row['content_json'] ?? '';
            $content = is_string($contentRaw) ? (string) (json_decode($contentRaw, true) ?? '') : '';
            if ($content === '') {
                continue;
            }
            $out[] = [
                'ordinal' => (int) ($row['ordinal'] ?? 0),
                'content' => $content,
                'at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    private function mapSubtype(string $subtype): string
    {
        return match ($subtype) {
            LoopResult::SUBTYPE_SUCCESS => 'completed',
            LoopResult::SUBTYPE_NEEDS_CONFIRMATION => 'needs_confirmation',
            LoopResult::SUBTYPE_REFUSAL => 'refused',
            LoopResult::SUBTYPE_ERROR_MAX_TURNS => 'max_turns',
            LoopResult::SUBTYPE_ERROR_MAX_BUDGET => 'max_budget',
            LoopResult::SUBTYPE_ERROR_FINGERPRINT_LOOP => 'fingerprint_loop',
            LoopResult::SUBTYPE_ERROR_LLM => 'completed_with_response_error',
            default => 'completed',
        };
    }
}

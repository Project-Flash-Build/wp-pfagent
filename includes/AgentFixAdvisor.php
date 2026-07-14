<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

final class AgentFixAdvisor
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function suggest(array $input): array
    {
        $signals = $this->signals($input);
        $suggestions = [];

        $this->maybe_add($suggestions, $signals, ['pfa_workflow_inactive'], [
            'id' => 'activate-workflow-dependency',
            'severity' => 'high',
            'category' => 'dependency',
            'title' => 'WP PFWorkflow is inactive.',
            'rationale' => 'The agent can only inspect or operate workflows when the Workflow plugin is active.',
            'actions' => [
                'Activate WP PFWorkflow.',
                'Call the agent contract again and verify workflowDependency.active is true.',
                'Retry the failed operation after dependency status is healthy.',
            ],
        ]);

        $this->maybe_add($suggestions, $signals, ['rest_forbidden', 'rest_cookie_invalid_nonce', 'pfa_agent_permission_denied'], [
            'id' => 'repair-wordpress-permissions',
            'severity' => 'high',
            'category' => 'permission',
            'title' => 'WordPress permission or nonce rejected the request.',
            'rationale' => 'Agent and credential routes require an authenticated user with the expected WordPress capability and a valid REST nonce.',
            'actions' => [
                'Refresh the WordPress admin page to get a fresh nonce.',
                'Verify the current user has manage_options or the mapped Setyenv capability.',
                'Retry the exact endpoint and keep the returned HTTP status in the timeline.',
            ],
        ]);

        $this->maybe_add($suggestions, $signals, ['pfa_provider_not_configured', 'pfa_provider_unknown'], [
            'id' => 'configure-provider',
            'severity' => 'high',
            'category' => 'provider',
            'title' => 'The selected provider is not usable.',
            'rationale' => 'LLM gateway calls require a known provider preset and a stored credential.',
            'actions' => [
                'List provider presets from /provider-presets.',
                'Save or rotate the provider credential through the credential endpoint.',
                'Run provider health before retrying generation or tool calling.',
            ],
        ]);

        $this->maybe_add($suggestions, $signals, ['auth'], [
            'id' => 'rotate-invalid-provider-key',
            'severity' => 'high',
            'category' => 'provider',
            'title' => 'Provider rejected authentication.',
            'rationale' => 'The provider returned an auth-class error, so runtime calls must not be treated as model or workflow failures.',
            'actions' => [
                'Rotate the stored credential.',
                'Run provider health and confirm the error type is no longer auth.',
                'Do not retry expensive LLM calls until health passes.',
            ],
        ]);

        $this->maybe_add($suggestions, $signals, ['quota'], [
            'id' => 'resolve-provider-quota',
            'severity' => 'medium',
            'category' => 'provider',
            'title' => 'Provider quota or balance blocked execution.',
            'rationale' => 'Quota failures are external account state, not workflow or agent logic failures.',
            'actions' => [
                'Check provider billing, credits or plan quota.',
                'Switch to another configured provider if the action is urgent.',
                'Retry only after provider health no longer reports quota.',
            ],
        ]);

        $this->maybe_add($suggestions, $signals, ['rate_limit'], [
            'id' => 'backoff-provider-rate-limit',
            'severity' => 'medium',
            'category' => 'provider',
            'title' => 'Provider rate limit blocked execution.',
            'rationale' => 'Rate limit failures should be retried with delay, not rewritten as generic provider failure.',
            'actions' => [
                'Wait before retrying.',
                'Reduce concurrent agent calls for the provider.',
                'Capture providerStatus and retry-after metadata if present.',
            ],
        ]);

        $this->maybe_add($suggestions, $signals, ['invalid_model', 'pfa_provider_no_text_model'], [
            'id' => 'refresh-provider-models',
            'severity' => 'medium',
            'category' => 'provider',
            'title' => 'The selected model is invalid or unsuitable.',
            'rationale' => 'The provider model catalog can change; runtime must use discovery/cache/manual model data instead of fixed model ids.',
            'actions' => [
                'Force model discovery for the provider.',
                'If the preset allows manual models, enter a current text-generation model id.',
                'Retry the gateway call and record the model used in evidence.',
            ],
        ]);

        $this->maybe_add($suggestions, $signals, ['pfa_agent_tool_not_allowed', 'pfa_agent_endpoint_not_documented'], [
            'id' => 'sync-tool-contract',
            'severity' => 'high',
            'category' => 'tooling',
            'title' => 'The model requested a tool or endpoint outside the declared contract.',
            'rationale' => 'Agent runtime must only execute tools declared by /agent-runtime/tools and documented in the tool contract.',
            'actions' => [
                'Fetch /agent-runtime/tools and verify the requested tool name exists.',
                'If the Workflow route is real, add it to the tool contract and smoke test it before exposing it.',
                'Reject the model response until the contract and endpoint match.',
            ],
        ]);

        $this->maybe_add($suggestions, $signals, ['pfa_agent_tool_argument_required', 'pfa_agent_tool_argument_type', 'pfa_agent_tool_argument_pattern', 'pfa_agent_tool_argument_unknown'], [
            'id' => 'repair-tool-arguments',
            'severity' => 'medium',
            'category' => 'tooling',
            'title' => 'Tool arguments failed schema validation.',
            'rationale' => 'Tool arguments are validated locally before Workflow API execution, so invalid arguments should be fixed before retrying.',
            'actions' => [
                'Read the tool parameters from /agent-runtime/tools.',
                'Regenerate only the invalid arguments.',
                'Do not call Workflow API until local schema validation passes.',
            ],
        ]);

        $this->maybe_add($suggestions, $signals, ['pfa_workflow_api_failed'], [
            'id' => 'inspect-workflow-api-failure',
            'severity' => 'medium',
            'category' => 'workflow',
            'title' => 'Workflow API returned an error.',
            'rationale' => 'The agent bridge propagated a Workflow API failure instead of rewriting it as success.',
            'actions' => [
                'Use the route and status from timeline evidence to reproduce the Workflow API call.',
                'If an execution id exists, call the Workflow execution support tool.',
                'Keep the original Workflow error code in the agent response.',
            ],
        ]);

        if ($suggestions === []) {
            $suggestions[] = [
                'id' => 'generic-agent-diagnostic',
                'severity' => 'low',
                'category' => 'diagnostic',
                'title' => 'No specific fix rule matched the supplied evidence.',
                'rationale' => 'The input did not include a known error code or timeline signal.',
                'actions' => [
                    'Fetch /contract and confirm the route is registered.',
                    'Fetch /agent-runtime/tools and confirm the tool exists.',
                    'Retry with the original error payload and timeline attached.',
                ],
                'evidence' => $signals,
            ];
        }

        return [
            'schema' => 'projectflash.agent.fix_suggestions',
            'schemaVersion' => 1,
            'generatedAt' => gmdate('c'),
            'status' => 'suggestions_ready',
            'inputSummary' => [
                'tool' => sanitize_text_field((string) ($input['tool']['name'] ?? $input['tool'] ?? '')),
                'signalCount' => count($signals),
            ],
            'suggestions' => array_values($suggestions),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $suggestions
     * @param array<int, string> $signals
     * @param array<int, string> $needles
     * @param array<string, mixed> $suggestion
     */
    private function maybe_add(array &$suggestions, array $signals, array $needles, array $suggestion): void
    {
        foreach ($needles as $needle) {
            if (in_array($needle, $signals, true)) {
                $suggestion['evidence'] = $signals;
                $suggestions[] = $suggestion;
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, string>
     */
    private function signals(array $input): array
    {
        $signals = [];
        $this->collect_signals($input['error'] ?? null, $signals);
        $this->collect_signals($input['result'] ?? null, $signals);
        $this->collect_signals($input['evidence'] ?? null, $signals);
        foreach (is_array($input['timeline'] ?? null) ? $input['timeline'] : [] as $event) {
            $this->collect_signals($event, $signals);
        }

        return array_values(array_unique(array_filter($signals)));
    }

    /**
     * @param mixed $value
     * @param array<int, string> $signals
     */
    private function collect_signals($value, array &$signals): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach (['code', 'errorCode', 'errorType', 'status'] as $key) {
            if (isset($value[$key]) && is_scalar($value[$key])) {
                $signals[] = sanitize_key((string) $value[$key]);
            }
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collect_signals($child, $signals);
            }
        }
    }
}

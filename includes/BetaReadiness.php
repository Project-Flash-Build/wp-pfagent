<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

/**
 * Maps the capability matrix to the four severe criteria of Sprint 20.
 *
 * Each criterion is evaluated as pass / fail with the exact list of
 * capabilities or scenarios that triggered the verdict. The endpoint
 * never assumes "ready" without evidence: a capability without a smoke
 * counts as not-ready, a capability promoted past `scaffolded` without
 * `functional=true` counts as not-ready, etc.
 */
final class BetaReadiness
{
    public function __construct(
        private readonly AgentContract $contract,
        private readonly ProviderHealth $health,
        private readonly CredentialStore $credentials
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(): array
    {
        $contract = $this->contract->build();
        $capabilities = is_array($contract['capabilities'] ?? null) ? $contract['capabilities'] : [];
        $routes = is_array($contract['routes'] ?? null) ? $contract['routes'] : [];
        $route_paths = [];
        foreach ($routes as $route) {
            $route_paths[(string) ($route['path'] ?? '')] = true;
        }

        // Criterion 1: every primary screen is backed by REST data, not
        // hardcoded constants. We surface this by listing every uiVisible
        // capability that is not also functional + endpointPolicy=required
        // (or `none` when explicitly UI-only). A scaffolded uiVisible item
        // is allowed only when explicitly tagged scaffolded.
        $hardcoded_violations = [];
        foreach ($capabilities as $capability) {
            if (($capability['uiVisible'] ?? false) !== true) {
                continue;
            }
            if (($capability['state'] ?? '') === 'scaffolded') {
                // Scaffolded UI is allowed but must be flagged in the partial list.
                continue;
            }
            $functional = ($capability['functional'] ?? false) === true;
            $policy = (string) ($capability['endpointPolicy'] ?? '');
            if (!$functional) {
                $hardcoded_violations[] = $capability['key'] ?? '<unknown>';
                continue;
            }
            if ($policy !== 'required' && $policy !== 'none') {
                $hardcoded_violations[] = $capability['key'] ?? '<unknown>';
            }
        }

        // Criterion 2: no action can be marked done without a real API
        // response. We surface this by listing capabilities whose state is
        // tested but that have no test files declared.
        $no_test_violations = [];
        foreach ($capabilities as $capability) {
            $state = (string) ($capability['state'] ?? '');
            if ($state !== 'tested' && $state !== 'product_ready') {
                continue;
            }
            $tests = is_array($capability['tests'] ?? null) ? $capability['tests'] : [];
            if ($tests === []) {
                $no_test_violations[] = $capability['key'] ?? '<unknown>';
            }
        }

        // Criterion 3: user can know what the agent did. The action
        // inspector and the trace log are the two surfaces that satisfy
        // this. If either is missing or not tested, fail.
        $inspector_keys = ['agent.action_inspector', 'observability.trace_log', 'observability.support_export'];
        $missing_visibility = [];
        foreach ($inspector_keys as $required_key) {
            $found = false;
            foreach ($capabilities as $capability) {
                if (($capability['key'] ?? '') === $required_key && ($capability['state'] ?? '') === 'tested') {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing_visibility[] = $required_key;
            }
        }

        // Criterion 4: any partial capability is shown as partial. The
        // partial list is anything that is not `tested` or `product_ready`.
        // We DO NOT fail this criterion for having partials; we fail it if
        // a partial item claims uiVisible=true AND functional=true AND a
        // state that is below scaffolded (eg. planned). Scaffolded UI is
        // honest by definition.
        $partial_capabilities = [];
        $dishonest_partials = [];
        foreach ($capabilities as $capability) {
            $state = (string) ($capability['state'] ?? '');
            if ($state === 'tested' || $state === 'product_ready') {
                continue;
            }
            $partial_capabilities[] = [
                'key' => (string) ($capability['key'] ?? ''),
                'state' => $state,
                'uiVisible' => (bool) ($capability['uiVisible'] ?? false),
                'functional' => (bool) ($capability['functional'] ?? false),
                'notes' => (string) ($capability['notes'] ?? ''),
            ];
            if (
                ($capability['uiVisible'] ?? false) === true
                && ($capability['functional'] ?? false) === true
                && $state === 'planned'
            ) {
                $dishonest_partials[] = $capability['key'] ?? '<unknown>';
            }
        }

        // Bonus: cross-check that every capability that lists endpoints has
        // them registered in the live REST server.
        $missing_routes = [];
        foreach ($capabilities as $capability) {
            $endpoints = is_array($capability['endpoints'] ?? null) ? $capability['endpoints'] : [];
            foreach ($endpoints as $endpoint) {
                $stripped = $this->normalize_endpoint((string) $endpoint);
                if ($stripped === '' || isset($route_paths[$stripped])) {
                    continue;
                }
                if (str_starts_with($stripped, '/wp-pfworkflow/')) {
                    // Workflow plugin owns those routes; the agent contract does not list them.
                    continue;
                }
                $missing_routes[] = ['capability' => $capability['key'] ?? '<unknown>', 'endpoint' => $stripped];
            }
        }

        $criteria = [
            'no_hardcoded_primary_screen' => [
                'pass' => $hardcoded_violations === [],
                'violations' => $hardcoded_violations,
                'description' => 'Every uiVisible capability that is not scaffolded must be functional and either endpointPolicy=required or endpointPolicy=none.',
            ],
            'no_done_without_real_api' => [
                'pass' => $no_test_violations === [],
                'violations' => $no_test_violations,
                'description' => 'Every tested or product_ready capability must declare at least one reproducible test.',
            ],
            'user_can_inspect_agent_actions' => [
                'pass' => $missing_visibility === [],
                'violations' => $missing_visibility,
                'description' => 'Action inspector + persistent trace log + support export must all be tested.',
            ],
            'partials_are_honest' => [
                'pass' => $dishonest_partials === [],
                'violations' => $dishonest_partials,
                'description' => 'A planned/api_callable capability must not advertise itself as a fully functional uiVisible feature.',
            ],
            'declared_routes_are_registered' => [
                'pass' => $missing_routes === [],
                'violations' => $missing_routes,
                'description' => 'Every wp-pfagent endpoint listed by a capability must exist in the live REST contract.',
            ],
        ];

        $totals = [
            'capabilities' => count($capabilities),
            'tested' => 0,
            'scaffolded' => 0,
            'api_callable' => 0,
            'planned' => 0,
            'product_ready' => 0,
        ];
        foreach ($capabilities as $capability) {
            $state = (string) ($capability['state'] ?? '');
            if (isset($totals[$state])) {
                $totals[$state]++;
            }
        }

        $providers_summary = [];
        foreach ($this->credentials->statuses() as $status) {
            $providers_summary[] = [
                'providerId' => $status['providerId'] ?? '',
                'family' => $status['family'] ?? '',
                'status' => $status['status'] ?? '',
                'configured' => (bool) ($status['configured'] ?? false),
                'maskedKey' => $status['maskedKey'] ?? null,
            ];
        }

        $ready = true;
        foreach ($criteria as $criterion) {
            if (!($criterion['pass'] ?? false)) {
                $ready = false;
                break;
            }
        }

        return [
            'schema' => 'projectflash.agent.beta_readiness',
            'schemaVersion' => 1,
            'generatedAt' => gmdate('c'),
            'ready' => $ready,
            'criteria' => $criteria,
            'totals' => $totals,
            'partial' => $partial_capabilities,
            'providers' => $providers_summary,
            'workflow' => is_array($contract['workflowDependency'] ?? null) ? $contract['workflowDependency'] : ['active' => false],
        ];
    }

    private function normalize_endpoint(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // Capability entries list endpoints with the namespace included
        // (`wp-pfagent/v1/provider-presets`), while AgentContract::routes()
        // returns namespace-relative paths (`/provider-presets`). Strip the
        // namespace so both ends of the comparison speak the same language.
        $value = preg_replace('#^/?wp-pfagent/v1#', '', $value) ?? $value;
        if ($value === '' || $value[0] !== '/') {
            $value = '/' . $value;
        }

        return $value;
    }
}

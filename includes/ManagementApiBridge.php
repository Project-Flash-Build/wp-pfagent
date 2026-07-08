<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

/**
 * Bridge between wp-pfagent and the wp-pfmanagement agent-ready service.
 *
 * wp-pfmanagement publishes its agent-ready service via the
 * `projectflash_management_agent_api` PHP filter — same pattern wp-pfworkflow
 * uses for `projectflash_workflow_agent_api`. The service exposes typed,
 * round-trippable methods that the LLM consumes via wrapped envelopes
 * `{ content, contextForYou }`.
 *
 * Tools this bridge serves:
 *   - pfm_get_contract — bootstrap contract (types, EQL ops, kinds vocab)
 *   - pfm_list({ kind, filters? }) — list a resource kind (entity/record/action/…)
 *   - pfm_get({ kind, ref }) — fetch one resource by slug or id
 *   - pfm_apply({ kind, payload }) — create or update one resource
 *   - pfm_delete({ kind, ref }) — delete one resource by reference
 */
final class ManagementApiBridge
{
    private const FILTER = 'projectflash_management_agent_api';

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $tool
     * @return array<string, mixed>|WP_Error
     */
    public function execute(string $tool_name, array $arguments, array $tool)
    {
        $service = $this->resolve_service();
        if ($service instanceof WP_Error) {
            return $service;
        }

        return match ($tool_name) {
            'pfm_get_contract' => $this->call_service($service, 'agent_contract', [], $tool_name),
            'pfm_list'         => $this->pfm_list($service, $arguments, $tool_name),
            'pfm_get'          => $this->pfm_get($service, $arguments, $tool_name),
            'pfm_apply'        => $this->pfm_apply($service, $arguments, $tool_name),
            'pfm_delete'       => $this->pfm_delete($service, $arguments, $tool_name),
            default            => new WP_Error('pfa_agent_tool_not_allowed', __('Tool is not executable by Management API bridge.', 'wp-pfagent'), ['status' => 400]),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function pfm_list(object $service, array $arguments, string $tool_name)
    {
        $kind = sanitize_key((string) ($arguments['kind'] ?? ''));
        if ($kind === '') {
            return new WP_Error('pfa_agent_kind_required', __('kind is required (entity, record, action, application, module, group, role, page).', 'wp-pfagent'), ['status' => 400]);
        }
        $filters = is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [];
        return $this->call_service($service, 'agent_list', [$kind, $filters], $tool_name);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function pfm_get(object $service, array $arguments, string $tool_name)
    {
        $kind = sanitize_key((string) ($arguments['kind'] ?? ''));
        $ref  = trim((string) ($arguments['ref'] ?? ''));
        if ($kind === '' || $ref === '') {
            return new WP_Error('pfa_agent_kind_ref_required', __('kind and ref are required.', 'wp-pfagent'), ['status' => 400]);
        }
        return $this->call_service($service, 'agent_get', [$kind, $ref], $tool_name);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function pfm_apply(object $service, array $arguments, string $tool_name)
    {
        // Canonical shape: { kind, payload }. We also accept lenient shapes
        // that smaller LLMs emit:
        //   - { kind, <kind>: {...} }     → payload is { <kind>: {...} }
        //   - { entity: {...}, fields: [...] } etc. → infer kind from the
        //     unique top-level resource key (entity, record, action,
        //     application, module, group, role, page) and wrap payload.
        // The downstream agent_apply re-validates the unwrapped payload,
        // so being forgiving here never bypasses real schema checks.
        $known_kinds = ['entity', 'record', 'action', 'application', 'module', 'group', 'role', 'page'];

        $kind = sanitize_key((string) ($arguments['kind'] ?? ''));
        $payload = is_array($arguments['payload'] ?? null) ? $arguments['payload'] : [];

        if ($payload === []) {
            // Try to assemble a payload from top-level kind-named keys.
            foreach ($known_kinds as $k) {
                if (isset($arguments[$k])) {
                    $payload[$k] = $arguments[$k];
                }
            }
            // Some kinds (record, module) require a sibling like `entity` or
            // `application` at the payload root — carry those over too.
            foreach (['entity', 'application', 'values', 'sys_id', 'numberSequence', 'formLayout', 'fields'] as $sibling) {
                if (isset($arguments[$sibling]) && !isset($payload[$sibling])) {
                    $payload[$sibling] = $arguments[$sibling];
                }
            }
        }

        if ($kind === '' && $payload !== []) {
            // Infer kind from the unique known top-level key inside payload.
            $matches = [];
            foreach ($known_kinds as $k) {
                if (isset($payload[$k])) {
                    $matches[] = $k;
                }
            }
            if (count($matches) === 1) {
                $kind = $matches[0];
            }
        }

        if ($kind === '' || $payload === []) {
            return new WP_Error('pfa_agent_kind_payload_required', __('Send { kind: "<entity|record|action|application|module|group|role|page>", payload: { <kind>: {...} } }.', 'wp-pfagent'), ['status' => 400]);
        }
        // options pass-through (agent_apply's third argument), notably
        // options.force. Without this, the Reconciler's own guidance
        // ("pass options.force=true") is impossible to follow from the
        // agent: any schema change on an entity that already has records
        // dead-ends in pfm_reconcile_unsafe. Accepted both at the tool
        // root (canonical, declared in agent-tools.json) and inside the
        // payload (lenient, where smaller LLMs tend to put it).
        $options = is_array($arguments['options'] ?? null) ? $arguments['options'] : [];
        if ($options === [] && is_array($payload['options'] ?? null)) {
            $options = $payload['options'];
            unset($payload['options']);
        }
        return $this->call_service($service, 'agent_apply', [$kind, $payload, $options], $tool_name);
    }

    /**
     * Delete ONE resource by { kind, ref }. Same shape as pfm_get; the ref is
     * the single explicit target (there is no bulk delete). Downstream
     * agent_delete re-applies the capability gate, the audit trail and the
     * cascade rules (business_rule delete refused, entity delete drops records,
     * record delete clears inbound relations), so this bridge stays a thin
     * argument adapter.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function pfm_delete(object $service, array $arguments, string $tool_name)
    {
        $kind = sanitize_key((string) ($arguments['kind'] ?? ''));
        $ref  = trim((string) ($arguments['ref'] ?? ''));
        if ($kind === '' || $ref === '') {
            return new WP_Error('pfa_agent_kind_ref_required', __('kind and ref are required.', 'wp-pfagent'), ['status' => 400]);
        }
        $options = is_array($arguments['options'] ?? null) ? $arguments['options'] : [];
        return $this->call_service($service, 'agent_delete', [$kind, $ref, $options], $tool_name);
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array<string, mixed>|WP_Error
     */
    private function call_service(object $service, string $method, array $arguments, string $tool_name)
    {
        if (!is_callable([$service, $method])) {
            return new WP_Error(
                'pfa_pfm_service_method_missing',
                'wp-pfmanagement agent service does not expose the requested method: ' . $method,
                ['status' => 502, 'method' => $method]
            );
        }

        try {
            $result = $service->$method(...$arguments);
        } catch (\Throwable $e) {
            return new WP_Error(
                'pfa_pfm_service_failed',
                'wp-pfmanagement agent service threw while executing ' . $method . ': ' . $e->getMessage(),
                ['status' => 502, 'method' => $method]
            );
        }

        if ($result instanceof WP_Error) {
            return $result;
        }

        if (!is_array($result) || !array_key_exists('content', $result) || !array_key_exists('contextForYou', $result)) {
            return new WP_Error(
                'pfa_pfm_service_invalid_envelope',
                'wp-pfmanagement agent service did not return a { content, contextForYou } envelope from ' . $method . '.',
                ['status' => 502, 'method' => $method]
            );
        }

        return [
            'method' => 'INTERNAL',
            'route' => 'internal:pfm:' . $method,
            'status' => 200,
            'data' => $result,
            'tool' => $tool_name,
        ];
    }

    /**
     * Read a resource snapshot for diff capture. Returns null when the
     * service is unavailable or the call fails — callers omit the diff
     * rather than fabricate one.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(string $kind, string $ref): ?array
    {
        if ($kind === '' || $ref === '') {
            return null;
        }
        $service = $this->resolve_service();
        if ($service instanceof WP_Error || !is_callable([$service, 'agent_get'])) {
            return null;
        }
        try {
            $result = $service->agent_get($kind, $ref);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($result) || !is_array($result['content'] ?? null)) {
            return null;
        }
        return [
            'kind' => $kind,
            'ref' => $ref,
            'content' => $result['content'],
        ];
    }

    /**
     * Resolve the wp-pfmanagement agent-ready service via the documented filter.
     *
     * @return object|WP_Error
     */
    private function resolve_service()
    {
        $service = apply_filters(self::FILTER, null);
        if (!is_object($service)) {
            return new WP_Error('pfa_pfm_service_unavailable', __('wp-pfmanagement agent-ready service is not available.', 'wp-pfagent'),
                ['status' => 502, 'filter' => self::FILTER]
            );
        }
        return $service;
    }
}

<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

final class AgentInternalDocs
{
    public function __construct(private readonly AgentContract $contract)
    {
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function build()
    {
        $contract = $this->contract->build();
        if ($contract instanceof WP_Error) {
            return $contract;
        }

        $routes = is_array($contract['routes'] ?? null) ? $contract['routes'] : [];
        $tools = is_array($contract['agentTools'] ?? null) ? $contract['agentTools'] : [];
        $capabilities = is_array($contract['capabilities'] ?? null) ? $contract['capabilities'] : [];
        $providers = is_array($contract['providers']['presets'] ?? null) ? $contract['providers']['presets'] : [];
        $workflow = is_array($contract['workflowDependency'] ?? null) ? $contract['workflowDependency'] : [];

        $sections = [
            [
                'id' => 'runtime-contract',
                'title' => 'Runtime Contract',
                'lines' => [
                    'Namespace: wp-pfagent/v1',
                    'Routes: ' . (string) count($routes),
                    'Capabilities: ' . (string) count($capabilities),
                    'Agent tools: ' . (string) count($tools),
                ],
            ],
            [
                'id' => 'workflow-dependency',
                'title' => 'Workflow Dependency',
                'lines' => [
                    'Active: ' . (((bool) ($workflow['active'] ?? false)) ? 'yes' : 'no'),
                    'Namespace: ' . (string) ($workflow['namespace'] ?? ''),
                    'Capabilities: ' . implode(', ', array_keys(array_filter(is_array($workflow['capabilities'] ?? null) ? $workflow['capabilities'] : []))),
                ],
            ],
            [
                'id' => 'tool-surface',
                'title' => 'Tool Surface',
                'lines' => array_values(array_map(
                    static fn(array $tool): string => (string) ($tool['name'] ?? '') . ' | sideEffect=' . (((bool) ($tool['sideEffect'] ?? false)) ? 'yes' : 'no'),
                    $tools
                )),
            ],
            [
                'id' => 'provider-surface',
                'title' => 'Provider Surface',
                'lines' => array_values(array_map(
                    static fn(string $key, array $provider): string => $key . ' | family=' . (string) ($provider['family'] ?? '') . ' | discovery=' . (string) ($provider['modelDiscovery'] ?? ''),
                    array_keys($providers),
                    $providers
                )),
            ],
        ];

        return [
            'schema' => 'projectflash.agent.internal_docs',
            'schemaVersion' => 1,
            'generatedAt' => gmdate('c'),
            'source' => 'generated_from_runtime_contract',
            'summary' => [
                'routeCount' => count($routes),
                'capabilityCount' => count($capabilities),
                'agentToolCount' => count($tools),
                'providerPresetCount' => count($providers),
                'workflowActive' => (bool) ($workflow['active'] ?? false),
            ],
            'sections' => $sections,
            'markdown' => $this->markdown($sections),
            'secretsIncluded' => false,
        ];
    }

    /**
     * @param array<int, array{id: string, title: string, lines: array<int, string>}> $sections
     */
    private function markdown(array $sections): string
    {
        $parts = ['# WP PFAgent Internal Docs', '', 'Generated from the live agent contract.'];
        foreach ($sections as $section) {
            $parts[] = '';
            $parts[] = '## ' . $section['title'];
            foreach ($section['lines'] as $line) {
                if ($line !== '') {
                    $parts[] = '- ' . $line;
                }
            }
        }

        return implode("\n", $parts);
    }
}

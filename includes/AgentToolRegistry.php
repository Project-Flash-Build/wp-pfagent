<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;

final class AgentToolRegistry
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function tools(): array
    {
        $catalog = $this->catalog();
        if ($catalog instanceof WP_Error) {
            return [];
        }

        $tools = [];
        foreach ($catalog['tools'] as $tool) {
            if (is_array($tool) && $this->tool_available($tool)) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function get(string $name)
    {
        foreach ($this->tools() as $tool) {
            if (($tool['name'] ?? '') === $name) {
                return $tool;
            }
        }

        return new WP_Error('pfa_agent_tool_not_allowed', __('The requested tool is not in the declared agent tool registry.', 'wp-pfagent'), ['status' => 400]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function catalog()
    {
        $path = WP_PFAGENT_DIR . 'config/agent-tools.json';
        if (!file_exists($path)) {
            return new WP_Error('pfa_agent_tools_missing', __('Agent tool contract file is missing.', 'wp-pfagent'), ['status' => 500]);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || ($decoded['schemaVersion'] ?? null) !== 1 || !is_array($decoded['tools'] ?? null)) {
            return new WP_Error('pfa_agent_tools_invalid', __('Agent tool contract file is invalid.', 'wp-pfagent'), ['status' => 500]);
        }

        $error = $this->validate_catalog($decoded);
        if ($error instanceof WP_Error) {
            return $error;
        }

        return $decoded;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function llm_tool_definitions(): array
    {
        return array_map(fn(array $tool): array => [
            'name' => (string) $tool['name'],
            'description' => (string) $tool['description'],
            'parameters' => $this->normalize_json_schema($tool['parameters']),
        ], $this->tools());
    }

    /**
     * Normalize a JSON Schema fragment for an LLM tool definition. PHP
     * decodes empty JSON objects (`{}`) as empty arrays (`[]`) when assoc
     * mode is on, and re-encoding emits `[]` again — which OpenAI rejects
     * for `properties` (must be an object). We coerce empty `properties`
     * to stdClass and recurse into nested object schemas + array items.
     * `required` stays a JSON array (its correct shape) and is preserved
     * untouched.
     *
     * Public surface: the Framework's FilterBridgeTool path goes through
     * tools() (raw shape) → FrameworkRuntime::buildRegistry → ToolDefinition,
     * bypassing llm_tool_definitions(). Without exposing this normaliser the
     * Framework Loop ships tools whose properties decode as [] and OpenAI-
     * compat providers (DeepSeek) reject the request with
     * "invalid_request_error: [] is not of type object".
     */
    public function normalize_json_schema(mixed $schema): mixed
    {
        if (!is_array($schema)) {
            return $schema;
        }

        $type = $schema['type'] ?? null;

        if ($type === 'object') {
            $properties = $schema['properties'] ?? [];
            if (is_array($properties)) {
                if ($properties === []) {
                    $schema['properties'] = (object) [];
                } else {
                    $normalized_properties = [];
                    foreach ($properties as $key => $value) {
                        $normalized_properties[(string) $key] = $this->normalize_json_schema($value);
                    }
                    $schema['properties'] = $normalized_properties;
                }
            }
        }

        if (isset($schema['items'])) {
            $schema['items'] = $this->normalize_json_schema($schema['items']);
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $schema['oneOf'] = array_map([$this, 'normalize_json_schema'], $schema['oneOf']);
        }
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $schema['anyOf'] = array_map([$this, 'normalize_json_schema'], $schema['anyOf']);
        }
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $schema['allOf'] = array_map([$this, 'normalize_json_schema'], $schema['allOf']);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $arguments
     */
    public function validate_arguments(array $tool, array $arguments): ?WP_Error
    {
        $schema = is_array($tool['parameters'] ?? null) ? $tool['parameters'] : [];

        return $this->validate_schema_value('arguments', $arguments, $schema);
    }

    /**
     * @param array<string, mixed> $catalog
     */
    private function validate_catalog(array $catalog): ?WP_Error
    {
        $capabilities = $this->capabilities_by_key();
        foreach ($catalog['tools'] as $tool) {
            if (!is_array($tool)) {
                return new WP_Error('pfa_agent_tools_invalid', __('Agent tool entries must be objects.', 'wp-pfagent'), ['status' => 500]);
            }

            $name = (string) ($tool['name'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
                return new WP_Error('pfa_agent_tools_invalid', 'Agent tool name is not provider-safe: ' . $name, ['status' => 500]);
            }

            foreach (['description', 'permission'] as $field) {
                if (!is_string($tool[$field] ?? null) || trim((string) $tool[$field]) === '') {
                    return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' is missing ' . $field . '.', ['status' => 500]);
                }
            }

            if (!is_bool($tool['sideEffect'] ?? null)) {
                return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' must declare sideEffect.', ['status' => 500]);
            }

            if (!is_array($tool['parameters'] ?? null) || ($tool['parameters']['type'] ?? '') !== 'object') {
                return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' must declare an object JSON schema.', ['status' => 500]);
            }

            if (!is_array($tool['tests'] ?? null) || $tool['tests'] === []) {
                return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' must declare tests.', ['status' => 500]);
            }
            foreach ($tool['tests'] as $test) {
                if (!is_string($test) || $test === '') {
                    return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' references an invalid test entry.', ['status' => 500]);
                }
                // Runtime does NOT gate on smoke-file existence — a smoke
                // rename or move is a CI/contract concern, never grounds
                // to take the entire tool surface offline.
            }

            $capability_keys = is_array($tool['capabilityKeys'] ?? null) ? $tool['capabilityKeys'] : [];
            if ($capability_keys === []) {
                return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' must declare capabilityKeys.', ['status' => 500]);
            }

            $documented = [];
            foreach ($capability_keys as $key) {
                if (!is_string($key) || !is_array($capabilities[$key] ?? null) || ($capabilities[$key]['functional'] ?? false) !== true) {
                    return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' references an unavailable capability: ' . (string) $key, ['status' => 500]);
                }
                foreach (is_array($capabilities[$key]['endpoints'] ?? null) ? $capabilities[$key]['endpoints'] : [] as $endpoint) {
                    if (is_string($endpoint)) {
                        $documented[] = $endpoint;
                    }
                }
            }

            $endpoints = is_array($tool['endpoints'] ?? null) ? $tool['endpoints'] : [];
            $php_service = is_array($tool['phpService'] ?? null) ? $tool['phpService'] : null;
            if ($endpoints === [] && $php_service === null) {
                return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' must declare either endpoints or phpService.', ['status' => 500]);
            }
            foreach ($endpoints as $endpoint) {
                if (!is_array($endpoint) || !is_string($endpoint['method'] ?? null) || !is_string($endpoint['path'] ?? null) || !is_string($endpoint['documentedAs'] ?? null)) {
                    return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' has an invalid endpoint contract.', ['status' => 500]);
                }
                if (!in_array($endpoint['documentedAs'], $documented, true)) {
                    return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' calls an endpoint not documented by its capabilities: ' . $endpoint['documentedAs'], ['status' => 500]);
                }
            }
            if ($php_service !== null) {
                if (!is_string($php_service['filter'] ?? null) || trim((string) $php_service['filter']) === '') {
                    return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' phpService.filter must be a non-empty string.', ['status' => 500]);
                }
                if (!is_string($php_service['method'] ?? null) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $php_service['method'])) {
                    return new WP_Error('pfa_agent_tools_invalid', 'Agent tool ' . $name . ' phpService.method must be a valid PHP identifier.', ['status' => 500]);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function capabilities_by_key(): array
    {
        $path = WP_PFAGENT_DIR . 'config/capabilities.json';
        if (!file_exists($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['capabilities'] ?? null)) {
            return [];
        }

        $capabilities = [];
        foreach ($decoded['capabilities'] as $capability) {
            if (is_array($capability) && is_string($capability['key'] ?? null)) {
                $capabilities[(string) $capability['key']] = $capability;
            }
        }

        return $capabilities;
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function tool_available(array $tool): bool
    {
        $workflow_capabilities = WorkflowDependency::capabilities();
        $permission = (string) ($tool['permission'] ?? '');

        return (bool) ($workflow_capabilities[$permission] ?? false);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validate_schema_value(string $path, mixed $value, array $schema): ?WP_Error
    {
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $this->schema_type_matches($candidate, $value)) {
                    return $this->validate_schema_constraints($path, $value, array_merge($schema, ['type' => $candidate]));
                }
            }

            return $this->argument_error('Tool argument does not match allowed types: ' . $path);
        }

        if (!is_string($type) || $type === '') {
            return null;
        }

        if (!$this->schema_type_matches($type, $value)) {
            return $this->argument_error('Tool argument must be ' . $type . ': ' . $path);
        }

        return $this->validate_schema_constraints($path, $value, $schema);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validate_schema_constraints(string $path, mixed $value, array $schema): ?WP_Error
    {
        $type = (string) ($schema['type'] ?? '');

        if (($schema['enum'] ?? null) !== null) {
            $allowed = is_array($schema['enum']) ? $schema['enum'] : [];
            if (!in_array($value, $allowed, true)) {
                return $this->argument_error('Tool argument is not one of the allowed values: ' . $path);
            }
        }

        if (($type === 'integer' || $type === 'number') && is_numeric($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                return $this->argument_error('Tool argument is below minimum: ' . $path);
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return $this->argument_error('Tool argument is above maximum: ' . $path);
            }
        }

        if ($type === 'string' && is_string($value)) {
            if (isset($schema['minLength']) && strlen($value) < (int) $schema['minLength']) {
                return $this->argument_error('Tool argument is shorter than minLength: ' . $path);
            }
            if (isset($schema['maxLength']) && strlen($value) > (int) $schema['maxLength']) {
                return $this->argument_error('Tool argument is longer than maxLength: ' . $path);
            }
            if (isset($schema['pattern']) && is_string($schema['pattern']) && @preg_match('/' . str_replace('/', '\\/', $schema['pattern']) . '/', '') !== false) {
                if (!preg_match('/' . str_replace('/', '\\/', $schema['pattern']) . '/', $value)) {
                    return $this->argument_error('Tool argument does not match pattern: ' . $path);
                }
            }
        }

        if ($type === 'object') {
            return $this->validate_object_schema($path, $value, $schema);
        }

        if ($type === 'array' && is_array($value)) {
            return $this->validate_array_schema($path, $value, $schema);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validate_object_schema(string $path, mixed $value, array $schema): ?WP_Error
    {
        $object = $value instanceof \stdClass ? get_object_vars($value) : $value;
        if (!is_array($object)) {
            return $this->argument_error('Tool argument must be object: ' . $path);
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $key) {
            if (!is_string($key) || !array_key_exists($key, $object)) {
                return $this->argument_error('Required tool argument is missing: ' . $this->child_path($path, (string) $key));
            }
        }

        if (($schema['additionalProperties'] ?? null) === false) {
            foreach ($object as $key => $_value) {
                if (!is_string($key) || !array_key_exists($key, $properties)) {
                    return $this->argument_error('Tool argument is not declared by schema: ' . $this->child_path($path, (string) $key));
                }
            }
        }

        foreach ($object as $key => $child_value) {
            if (!is_string($key) || !is_array($properties[$key] ?? null)) {
                continue;
            }

            $error = $this->validate_schema_value($this->child_path($path, $key), $child_value, $properties[$key]);
            if ($error instanceof WP_Error) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validate_array_schema(string $path, array $value, array $schema): ?WP_Error
    {
        if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
            return $this->argument_error('Tool argument has fewer items than minItems: ' . $path);
        }
        if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
            return $this->argument_error('Tool argument has more items than maxItems: ' . $path);
        }

        $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];
        if ($items === []) {
            return null;
        }

        foreach ($value as $index => $item) {
            $error = $this->validate_schema_value($path . '[' . (string) $index . ']', $item, $items);
            if ($error instanceof WP_Error) {
                return $error;
            }
        }

        return null;
    }

    private function schema_type_matches(string $type, mixed $value): bool
    {
        return match ($type) {
            'object' => is_array($value) || $value instanceof \stdClass,
            'array' => is_array($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    private function child_path(string $path, string $key): string
    {
        return $path === '' ? $key : $path . '.' . $key;
    }

    private function argument_error(string $message): WP_Error
    {
        return new WP_Error('pfa_agent_tool_arguments_invalid', $message, ['status' => 400]);
    }
}

<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Sourcecode;

/**
 * Compiler-internal lookup table mapping verbs (category.verb_name) to
 * node contracts. The compiler queries this when resolving
 * `await category.verb({...})` calls to decide which arg goes to a
 * data input pin vs which becomes a config field on node.data.
 *
 * NOT the LLM's view of the catalogue — that is a generated TypeScript
 * file written by `LibraryBuilder`. See the
 * [[reference_pfagent_node_library]] memory.
 *
 * The catalog comes from wp-pfworkflow's `AgentContractHelper::contract()`
 * (PHP-internal, via the `projectflash_workflow_agent_api` filter). We
 * cache it per-request because the contract is expensive to build and
 * doesn't change during a request.
 */
final class VerbCatalog
{
    /** @var array<string, mixed>|null */
    private static ?array $contractCache = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $verbsCache = null;

    /** @var array<int, string>|null */
    private static ?array $entitySlugsCache = null;

    /**
     * @return array<string, mixed>|null  Returns the loaded contract or
     *         null if wp-pfworkflow's service is unavailable.
     */
    public static function contract(): ?array
    {
        if (self::$contractCache !== null) {
            return self::$contractCache;
        }
        $service = apply_filters('projectflash_workflow_agent_api', null);
        if (!is_object($service) || !method_exists($service, 'agent_contract')) {
            return null;
        }
        $envelope = $service->agent_contract();
        if (!is_array($envelope) || !is_array($envelope['content'] ?? null)) {
            return null;
        }
        self::$contractCache = $envelope['content'];
        return self::$contractCache;
    }

    /**
     * Force a refresh on the next call. Called when wp-pfworkflow signals
     * that its catalogue changed.
     */
    public static function flush(): void
    {
        self::$contractCache = null;
        self::$verbsCache = null;
        self::$entitySlugsCache = null;
    }

    /**
     * Flat list of every entity slug registered in wp-pfmanagement.
     * The compiler consults this when lowering `<slug>.create/update/
     * remove/query` virtual calls: the rewrite only fires when the
     * leading segment matches a real entity, so unrelated dotted calls
     * (data.foo, workflow.bar, ...) reach the verb catalog unchanged.
     *
     * Empty when wp-pfmanagement is inactive — the lowering then never
     * matches and the compiler falls through to the regular dispatch,
     * which surfaces a clean `unknown_verb` error.
     *
     * @return array<int, string>
     */
    public static function entitySlugs(): array
    {
        if (self::$entitySlugsCache !== null) {
            return self::$entitySlugsCache;
        }
        $slugs = apply_filters('projectflash_management_entity_slugs', null);
        if (!is_array($slugs)) {
            self::$entitySlugsCache = [];
            return self::$entitySlugsCache;
        }
        $clean = [];
        foreach ($slugs as $s) {
            if (!is_string($s) || $s === '') {
                continue;
            }
            $clean[] = $s;
        }
        self::$entitySlugsCache = array_values(array_unique($clean));
        return self::$entitySlugsCache;
    }

    /**
     * Returns the map { key => normalized verb shape } for every node
     * the contract advertises.
     *
     * Shape:
     *   {
     *     key:           'email.send_email',
     *     category:      'email',
     *     verb:          'send_email',
     *     kind:          'action',
     *     label:         'Send email',
     *     description:   'Send an email using wp_mail.',
     *     inputs:        [ { key, type, required, label } ],  // data pins
     *     outputs:       [ { key, type, label } ],
     *     config:        [ { key, type, required, label } ],
     *     execOutputs:   [ 'next', 'error' ],
     *     dynamicInputs: false,
     *     dynamicOutputs:false,
     *     security:      { destructive, externalNetwork, requiresSecrets },
     *     honestStatus:  'real_executable'
     *   }
     *
     * @return array<string, array<string, mixed>>
     */
    public static function verbs(): array
    {
        if (self::$verbsCache !== null) {
            return self::$verbsCache;
        }
        $contract = self::contract();
        if (!is_array($contract) || !is_array($contract['nodes'] ?? null)) {
            self::$verbsCache = [];
            return self::$verbsCache;
        }

        $out = [];
        foreach ($contract['nodes'] as $key => $node) {
            $key_str = (string) $key;
            if (!is_array($node) || $key_str === '') {
                continue;
            }
            [$category, $verb] = self::splitKey($key_str);
            $out[$key_str] = [
                'key' => $key_str,
                'category' => $category,
                'verb' => $verb,
                'kind' => (string) ($node['kind'] ?? 'action'),
                'label' => (string) ($node['label'] ?? $key_str),
                'description' => (string) ($node['description'] ?? ''),
                'inputs' => self::normalizePins($node['inputs'] ?? []),
                'outputs' => self::normalizePins($node['outputs'] ?? []),
                'config' => self::normalizeConfig($node['config'] ?? []),
                'execOutputs' => array_values(array_map('strval', (array) ($node['exec']['outputs'] ?? []))),
                'dynamicInputs' => (bool) ($node['dynamicInputs'] ?? false),
                'dynamicOutputs' => (bool) ($node['dynamicOutputs'] ?? false),
                'requireOneOf' => self::normalizeRequireOneOf($node['requireOneOf'] ?? null),
                'security' => is_array($node['security'] ?? null) ? $node['security'] : [],
                'honestStatus' => (string) ($node['honestStatus']['status'] ?? ''),
            ];
        }
        ksort($out);
        self::$verbsCache = $out;
        return self::$verbsCache;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        $verbs = self::verbs();
        return $verbs[$key] ?? null;
    }

    /**
     * Resolve a verb by category + verb name. The category is everything
     * before the first dot in the canonical key.
     *
     * @return array<string, mixed>|null
     */
    public static function findByCategoryVerb(string $category, string $verb): ?array
    {
        return self::find($category . '.' . $verb);
    }

    /**
     * @return array<string, mixed>
     */
    public static function typeCatalog(): array
    {
        $contract = self::contract();
        if (!is_array($contract) || !is_array($contract['types'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($contract['types'] as $entry) {
            if (is_array($entry) && isset($entry['type'])) {
                $out[(string) $entry['type']] = $entry;
            }
        }
        return $out;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function compatibilityMatrix(): array
    {
        $contract = self::contract();
        if (!is_array($contract) || !is_array($contract['compatibility'] ?? null)) {
            return [];
        }
        return $contract['compatibility'];
    }

    /**
     * @return array<int, string>
     */
    public static function conditionOperators(): array
    {
        $contract = self::contract();
        if (!is_array($contract) || !is_array($contract['enums']['conditionOperators'] ?? null)) {
            return [];
        }
        return array_values(array_filter($contract['enums']['conditionOperators'], 'is_string'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function splitKey(string $key): array
    {
        $pos = strpos($key, '.');
        if ($pos === false) {
            return ['', $key];
        }
        return [substr($key, 0, $pos), substr($key, $pos + 1)];
    }

    /**
     * @param mixed $pins
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param mixed $groups
     * @return array<int, array<int, string>>
     */
    private static function normalizeRequireOneOf($groups): array
    {
        if (!is_array($groups)) {
            return [];
        }
        $out = [];
        foreach ($groups as $group) {
            if (!is_array($group)) continue;
            $keys = array_values(array_filter(array_map('strval', $group), static fn (string $k): bool => $k !== ''));
            if ($keys !== []) {
                $out[] = $keys;
            }
        }
        return $out;
    }

    private static function normalizePins($pins): array
    {
        if (!is_array($pins)) {
            return [];
        }
        $out = [];
        foreach ($pins as $pin) {
            if (!is_array($pin) || !isset($pin['key'])) {
                continue;
            }
            $entry = [
                'key' => (string) $pin['key'],
                'type' => (string) ($pin['type'] ?? 'any'),
                'label' => (string) ($pin['label'] ?? $pin['key']),
                'required' => (bool) ($pin['required'] ?? false),
                'multiple' => (bool) ($pin['multiple'] ?? false),
            ];
            // Preserve the enriched-type-system metadata the compiler
            // / runtime / validator need downstream:
            //   - brand: branded socket / brand-check at apply time.
            //   - requiresAutoVariable: tells the compiler to seed a
            //     workflow variable + auto-wire when the LLM omits this pin.
            //   - _sourcePin / _sourceField: object-pin flattening
            //     metadata so the runtime can dereference the nested
            //     value emitted by the executor.
            foreach (['brand', 'requiresAutoVariable', '_sourcePin', '_sourceField', 'default', 'description', 'options'] as $extra) {
                if (array_key_exists($extra, $pin)) {
                    $entry[$extra] = $pin[$extra];
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param mixed $config
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeConfig($config): array
    {
        if (!is_array($config)) {
            return [];
        }
        // The contract sometimes wraps as { schemaVersion, fields: [...] }.
        if (isset($config['fields']) && is_array($config['fields'])) {
            $fields = $config['fields'];
        } else {
            $fields = $config;
        }
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['key'])) {
                continue;
            }
            $out[] = [
                'key' => (string) $field['key'],
                'type' => (string) ($field['type'] ?? 'string'),
                'label' => (string) ($field['label'] ?? $field['key']),
                'required' => (bool) ($field['required'] ?? false),
                'sensitive' => (bool) ($field['sensitive'] ?? false),
                'description' => (string) ($field['description'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Disambiguate an arg key: returns 'input' | 'config' | null. The
     * compiler uses this to decide whether a `key: value` pair becomes
     * a data connection or a node.data field.
     */
    public static function argKind(array $verb, string $argKey): ?string
    {
        foreach ($verb['inputs'] ?? [] as $pin) {
            if (($pin['key'] ?? '') === $argKey) {
                return 'input';
            }
        }
        foreach ($verb['config'] ?? [] as $field) {
            if (($field['key'] ?? '') === $argKey) {
                return 'config';
            }
        }
        if (($verb['dynamicInputs'] ?? false)) {
            return 'input';
        }
        return null;
    }

    /**
     * Pick the "primary output" pin: the output the compiler will assume
     * a `const x = await verb(...)` binding refers to. Heuristic:
     *   1. exactly one output → that one
     *   2. an output whose key is 'value' / 'result' / 'data' (in that order)
     *   3. the first output
     *   4. null if no outputs at all (caller must not capture)
     */
    public static function primaryOutputKey(array $verb): ?string
    {
        $outs = is_array($verb['outputs'] ?? null) ? $verb['outputs'] : [];
        if ($outs === []) {
            return null;
        }
        if (count($outs) === 1) {
            return (string) $outs[0]['key'];
        }
        foreach (['value', 'result', 'data'] as $preferred) {
            foreach ($outs as $pin) {
                if (($pin['key'] ?? '') === $preferred) {
                    return $preferred;
                }
            }
        }
        return (string) $outs[0]['key'];
    }
}

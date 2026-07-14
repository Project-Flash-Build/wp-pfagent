<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Sourcecode;

/**
 * Recursive descent parser for the workflow source language subset.
 *
 * Produces an AST as plain assoc arrays. Top level shape:
 *   {
 *     type: 'Program',
 *     declarations: [   // trigger / name / status / flow-function
 *       { type: 'TriggerDecl', triggerKey, eventAlias, line, column },
 *       { type: 'NameDecl', value, line, column },
 *       { type: 'StatusDecl', value, line, column },
 *       { type: 'FlowDecl', body: [stmts], line, column },
 *     ]
 *   }
 *
 * Statement node types: VarDecl, AssignStmt, IfStmt, ExprStmt, ReturnStmt,
 *   StopStmt, BlockStmt.
 *
 * Expression node types: Identifier, MemberExpr, CallExpr, AwaitExpr,
 *   BinaryExpr, UnaryExpr, TernaryExpr, LiteralString, LiteralNumber,
 *   LiteralBool, LiteralNull, ObjectExpr, ArrayExpr, TemplateExpr.
 */
final class Parser
{
    /** @var array<int, array<string, mixed>> */
    private array $tokens;
    private int $pos = 0;
    private int $count;

    /**
     * @param array<int, array<string, mixed>> $tokens
     */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
        $this->count = count($tokens);
    }

    /**
     * @return array<string, mixed>
     */
    public function parseProgram(): array
    {
        $declarations = [];
        while (!$this->isAtEnd()) {
            $declarations[] = $this->parseTopLevelDeclaration();
        }

        // F21 + F26 + F27: post-parse structural validation. Count
        // trigger / name / status declarations and reject programs
        // that don't have exactly one of each. The flow declaration is
        // optional from this pass' perspective (a workflow may decompile
        // with only a trigger + metadata), but a workflow without name
        // or status is operator-illegal and the LLM keeps inventing
        // those without scaffold so we force the explicit declaration.
        $triggerCount = 0;
        $nameCount = 0;
        $statusCount = 0;
        $firstTok = $this->tokens[0] ?? ['line' => 0, 'column' => 0];
        foreach ($declarations as $decl) {
            $t = (string) ($decl['type'] ?? '');
            if ($t === 'TriggerDecl') {
                $triggerCount++;
            } elseif ($t === 'NameDecl') {
                $nameCount++;
            } elseif ($t === 'StatusDecl') {
                $statusCount++;
            }
        }
        if ($triggerCount === 0) {
            throw CompileError::parse(
                'missing_trigger',
                (int) ($firstTok['line'] ?? 0),
                (int) ($firstTok['column'] ?? 0),
                'A workflow must declare exactly one trigger.',
                'Add a `trigger <Identifier> as event;` line near the top of the file.'
            );
        }
        if ($triggerCount > 1) {
            throw CompileError::parse(
                'multiple_triggers',
                (int) ($firstTok['line'] ?? 0),
                (int) ($firstTok['column'] ?? 0),
                sprintf('A workflow must declare exactly one trigger; found %d.', $triggerCount),
                'Only the first event channel can drive a workflow. Split into multiple workflows or pick one trigger.'
            );
        }
        if ($nameCount === 0) {
            throw CompileError::parse(
                'missing_name',
                (int) ($firstTok['line'] ?? 0),
                (int) ($firstTok['column'] ?? 0),
                'A workflow must declare a `name` directive.',
                'Add `name "<workflow title>";` near the top of the file.'
            );
        }
        if ($statusCount === 0) {
            throw CompileError::parse(
                'missing_status',
                (int) ($firstTok['line'] ?? 0),
                (int) ($firstTok['column'] ?? 0),
                'A workflow must declare a `status` directive.',
                'Add `status "active";` or `status "draft";` near the top of the file.'
            );
        }

        return ['type' => 'Program', 'declarations' => $declarations];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTopLevelDeclaration(): array
    {
        $tok = $this->peek();
        $value = is_string($tok['value']) ? $tok['value'] : '';
        // F20: `async function workflow(event)` without the `flow` prefix
        // is the leniency the cert hunt surfaced. The documented grammar
        // requires `flow async function workflow(event) { ... }`. Reject
        // the bare-async form and direct the LLM to the canonical shape.
        if ($tok['type'] === Lexer::T_KEYWORD && $value === 'async') {
            throw $this->error(
                'flow_keyword_required',
                'Workflow function declaration must be preceded by the `flow` keyword.',
                'Write: flow async function workflow(event) { ... }'
            );
        }
        if ($tok['type'] === Lexer::T_IDENT || $tok['type'] === Lexer::T_KEYWORD) {
            return match ($value) {
                'trigger' => $this->parseTriggerDecl(),
                'name' => $this->parseNameDecl(),
                'status' => $this->parseStatusDecl(),
                'flow' => $this->parseFlowDecl(),
                default => throw $this->error('unknown_top_level', sprintf('Unknown top-level declaration "%s".', $value), 'Allowed at top level: trigger, name, status, flow.'),
            };
        }
        throw $this->error('top_level_expected', sprintf('Expected a top-level declaration (trigger, name, status, flow), got "%s".', (string) (is_array($tok['value']) ? '' : $tok['value'])), 'A program must begin with declarations; statements only live inside the flow body.');
    }

    /**
     * trigger '<key>' as <eventName> [with { <config> }];
     *
     * The optional `with { ... }` block lets the source carry the trigger
     * node's configuration — required for trigger keys whose runtime
     * needs concrete arguments (e.g. developer.wp_hook needs hookName,
     * webhooks.poll_endpoint needs url, etc.). Without it the workflow
     * never fires because the trigger does not know what to listen for.
     *
     * @return array<string, mixed>
     */
    private function parseTriggerDecl(): array
    {
        $tok = $this->expectSoftKeyword('trigger');

        // The trigger key is preferably a typed identifier (e.g.
        // `Incidentes$Updated$Trigger`, `Workflow$manual$Trigger`,
        // `Content$post_published$Trigger`), but we also accept the
        // legacy string-form `'<entity>.<verb>'` and normalise it
        // internally. The legacy form survives because workflows
        // authored before the typed-identifier hardening still ship
        // with quoted trigger keys in their Decompile cache — see
        // F11 Path A in cross-plugin/attack-plan/04-pfagent-fixes.md.
        // Sprint 4 (Path B) teaches the Decompiler to emit the typed
        // form going forward so the legacy branch only services old data.
        $peek = $this->peek();
        $peek_type = is_array($peek) ? ($peek['type'] ?? null) : null;
        if ($peek_type === Lexer::T_IDENT) {
            $id_tok = $this->advance();
            $key_value = (string) $id_tok['value'];
        } elseif ($peek_type === Lexer::T_STRING) {
            // Legacy string form is passed through verbatim. The Compiler's
            // VerbCatalog::find accepts the dotted "<entity>.<verb>" key
            // directly, so no Parser-side transformation is needed. The
            // typed form (Incidentes$Updated$Trigger) goes through the
            // virtualResolver and is mapped to the same verb downstream.
            $str_tok = $this->advance();
            $legacy = (string) $str_tok['value']['value'];
            if ($legacy === '') {
                throw $this->error(
                    'trigger_key_empty',
                    'trigger key string must not be empty.'
                );
            }
            $key_value = $legacy;
        } else {
            throw $this->error(
                'trigger_key_must_be_identifier_or_string',
                'trigger key must be a typed identifier (e.g. Incidentes$Updated$Trigger) or the legacy string form (e.g. "incidentes.created"). String-form is deprecated; emit the typed form going forward.'
            );
        }

        $this->expectSoftKeyword('as');
        $alias_tok = $this->expect(Lexer::T_IDENT, 'identifier', 'trigger alias must be the identifier `event`');
        // F25: the trigger alias is canonical — every workflow body refers
        // to the trigger payload as `event.<field>`. Aliasing to anything
        // else (`as e;`, `as ctx;`, etc.) makes the workflow body unreadable
        // and breaks the typed-identifier resolver.
        if ((string) $alias_tok['value'] !== 'event') {
            throw $this->error(
                'trigger_alias_must_be_event',
                sprintf('Trigger alias must be exactly "event"; got "%s".', (string) $alias_tok['value']),
                'Write: trigger <Identifier> as event;'
            );
        }
        if ($this->matchSoftKeyword('with')) {
            throw $this->error(
                'trigger_with_clause_unsupported',
                'trigger declarations do not take a `with { ... }` configuration clause. Every trigger identifier is fully baked — its output pins are declared in the typings and the compiler injects any structural config (entity filter, hook name, etc.) automatically.'
            );
        }
        $config = null;
        $this->expectPunct(';', 'declarations end with ;');
        return [
            'type' => 'TriggerDecl',
            'triggerKey' => $key_value,
            'eventAlias' => (string) $alias_tok['value'],
            'config' => $config,
            'line' => $tok['line'],
            'column' => $tok['column'],
        ];
    }

    /**
     * name 'My workflow';
     *
     * @return array<string, mixed>
     */
    private function parseNameDecl(): array
    {
        $tok = $this->expectSoftKeyword('name');
        $val_tok = $this->expect(Lexer::T_STRING, 'string', 'name value must be a string literal');
        $this->expectPunct(';', 'declarations end with ;');
        return ['type' => 'NameDecl', 'value' => $val_tok['value']['value'], 'line' => $tok['line'], 'column' => $tok['column']];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseStatusDecl(): array
    {
        $tok = $this->expectSoftKeyword('status');
        $val_tok = $this->expect(Lexer::T_STRING, 'string', 'status value must be a string literal');
        $val = (string) $val_tok['value']['value'];
        if (!in_array($val, ['active', 'draft'], true)) {
            throw $this->error('invalid_status', sprintf('status must be "active" or "draft", got "%s".', $val));
        }
        $this->expectPunct(';', 'declarations end with ;');
        return ['type' => 'StatusDecl', 'value' => $val, 'line' => $tok['line'], 'column' => $tok['column']];
    }

    /**
     * flow async function workflow(event) { ... }
     *
     * @return array<string, mixed>
     */
    private function parseFlowDecl(): array
    {
        $start = $this->expectSoftKeyword('flow');
        $this->expectKeyword('async');
        $this->expectKeyword('function');
        $name_tok = $this->expect(Lexer::T_IDENT, 'identifier', 'flow function needs a name (conventionally "workflow")');
        $params = $this->parseParamList();
        if (count($params) !== 1) {
            throw $this->error('flow_param_count', sprintf('Flow function expects exactly one parameter (the event alias), got %d.', count($params)));
        }
        $body = $this->parseBlockBody();
        return [
            'type' => 'FlowDecl',
            'name' => (string) $name_tok['value'],
            'paramName' => $params[0],
            'body' => $body,
            'line' => $start['line'],
            'column' => $start['column'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseParamList(): array
    {
        $this->expectPunct('(', 'parameter list opens with (');
        $params = [];
        if (!$this->check(Lexer::T_PUNCT, ')')) {
            do {
                $tok = $this->expect(Lexer::T_IDENT, 'identifier', 'parameter must be an identifier');
                $params[] = (string) $tok['value'];
            } while ($this->match(Lexer::T_PUNCT, ','));
        }
        $this->expectPunct(')', 'parameter list closes with )');
        return $params;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseBlockBody(): array
    {
        $this->expectPunct('{', 'function body opens with {');
        $stmts = [];
        while (!$this->check(Lexer::T_PUNCT, '}') && !$this->isAtEnd()) {
            $stmts[] = $this->parseStatement();
        }
        $this->expectPunct('}', 'function body closes with }');
        return $stmts;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseStatement(): array
    {
        $tok = $this->peek();
        if ($tok['type'] === Lexer::T_KEYWORD) {
            switch ($tok['value']) {
                case 'const':
                case 'let':
                case 'var':
                    return $this->parseVarDecl();
                case 'if':
                    return $this->parseIfStmt();
                case 'return':
                    return $this->parseReturnStmt();
                case 'for':
                    return $this->parseForOfStmt();
                case 'try':
                    return $this->parseTryStmt();
                case 'while':
                case 'do':
                case 'switch':
                case 'throw':
                case 'break':
                case 'continue':
                case 'class':
                case 'import':
                case 'export':
                case 'new':
                case 'this':
                case 'super':
                    throw $this->error('unsupported_statement', sprintf('"%s" is not supported in the source language.', (string) $tok['value']), 'This DSL is a small JavaScript subset. To iterate a collection use `for (const item of items) { ... }` — there is no `while`/`do`/`switch`, and no loop-until-condition (a workflow runs once per trigger event). See the AUTHORING RULES at the top of /lib/nodes.d.ts.');
            }
        }
        // Soft keyword: stop() — kept as a structural statement so we never
        // accidentally treat a stop call as an arbitrary expression.
        if ($this->checkSoftKeyword('stop')) {
            // Peek two tokens to make sure it's `stop()` and not `stop = ...`.
            $next = $this->tokens[$this->pos + 1] ?? null;
            if (is_array($next) && $next['type'] === Lexer::T_PUNCT && $next['value'] === '(') {
                return $this->parseStopStmt();
            }
        }
        if ($tok['type'] === Lexer::T_PUNCT && $tok['value'] === '{') {
            $body = $this->parseBlockBody();
            return ['type' => 'BlockStmt', 'body' => $body, 'line' => $tok['line'], 'column' => $tok['column']];
        }
        return $this->parseExprStmt();
    }

    /**
     * for (const item of arr) { ... }
     *
     * @return array<string, mixed>
     */
    private function parseForOfStmt(): array
    {
        $kw = $this->expectKeyword('for');
        $this->expectPunct('(', 'for opens with (');
        // Accept `const`, `let`, or bare identifier (we always treat the
        // binding as block-scoped to the loop body).
        if ($this->checkKeyword('const') || $this->checkKeyword('let') || $this->checkKeyword('var')) {
            $this->advance();
        }
        $name_tok = $this->expect(Lexer::T_IDENT, 'identifier', 'for-of loop variable must be an identifier');
        $this->expectKeyword('of');
        $iter_expr = $this->parseExpression();
        $this->expectPunct(')', 'for header closes with )');
        $body = $this->parseBlockOrStmt();
        return [
            'type' => 'ForOfStmt',
            'itemName' => (string) $name_tok['value'],
            'iterable' => $iter_expr,
            'body' => $body,
            'line' => $kw['line'],
            'column' => $kw['column'],
        ];
    }

    /**
     * try { ... } catch (e?) { ... }
     *
     * @return array<string, mixed>
     */
    private function parseTryStmt(): array
    {
        $kw = $this->expectKeyword('try');
        $this->expectPunct('{', 'try opens with {');
        $try_body = [];
        while (!$this->check(Lexer::T_PUNCT, '}') && !$this->isAtEnd()) {
            $try_body[] = $this->parseStatement();
        }
        $this->expectPunct('}', 'try closes with }');
        $this->expectKeyword('catch');
        $error_alias = null;
        if ($this->match(Lexer::T_PUNCT, '(')) {
            if (!$this->check(Lexer::T_PUNCT, ')')) {
                $alias_tok = $this->expect(Lexer::T_IDENT, 'identifier', 'catch alias must be an identifier');
                $error_alias = (string) $alias_tok['value'];
            }
            $this->expectPunct(')', 'catch header closes with )');
        }
        $this->expectPunct('{', 'catch opens with {');
        $catch_body = [];
        while (!$this->check(Lexer::T_PUNCT, '}') && !$this->isAtEnd()) {
            $catch_body[] = $this->parseStatement();
        }
        $this->expectPunct('}', 'catch closes with }');
        return [
            'type' => 'TryStmt',
            'tryBody' => $try_body,
            'catchBody' => $catch_body,
            'errorAlias' => $error_alias,
            'line' => $kw['line'],
            'column' => $kw['column'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseVarDecl(): array
    {
        $kw = $this->advance();
        $kind = (string) $kw['value']; // const | let | var
        $name_tok = $this->expect(Lexer::T_IDENT, 'identifier', 'variable name must be an identifier');
        $init = null;
        if ($this->match(Lexer::T_PUNCT, '=')) {
            $init = $this->parseExpression();
        }
        $this->expectPunct(';', 'statement ends with ;');
        return [
            'type' => 'VarDecl',
            'kind' => $kind,
            'name' => (string) $name_tok['value'],
            'init' => $init,
            'line' => $kw['line'],
            'column' => $kw['column'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseIfStmt(): array
    {
        $kw = $this->expectKeyword('if');
        $this->expectPunct('(', 'if condition opens with (');
        $cond = $this->parseExpression();
        $this->expectPunct(')', 'if condition closes with )');
        $consequent = $this->parseBlockOrStmt();
        $alternate = null;
        if ($this->matchKeyword('else')) {
            // else if chains: keep parsing as another IfStmt
            if ($this->checkKeyword('if')) {
                $alternate = $this->parseIfStmt();
            } else {
                $alternate = $this->parseBlockOrStmt();
            }
        }
        return [
            'type' => 'IfStmt',
            'test' => $cond,
            'consequent' => $consequent,
            'alternate' => $alternate,
            'line' => $kw['line'],
            'column' => $kw['column'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBlockOrStmt(): array
    {
        if ($this->check(Lexer::T_PUNCT, '{')) {
            $tok = $this->peek();
            $body = $this->parseBlockBody();
            return ['type' => 'BlockStmt', 'body' => $body, 'line' => $tok['line'], 'column' => $tok['column']];
        }
        return $this->parseStatement();
    }

    /**
     * @return array<string, mixed>
     */
    private function parseReturnStmt(): array
    {
        $kw = $this->expectKeyword('return');
        $value = null;
        if (!$this->check(Lexer::T_PUNCT, ';')) {
            $value = $this->parseExpression();
        }
        $this->expectPunct(';', 'statement ends with ;');
        return ['type' => 'ReturnStmt', 'value' => $value, 'line' => $kw['line'], 'column' => $kw['column']];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseStopStmt(): array
    {
        $kw = $this->expectSoftKeyword('stop');
        $this->expectPunct('(', 'stop() call opens with (');
        $this->expectPunct(')', 'stop() takes no arguments');
        $this->expectPunct(';', 'statement ends with ;');
        return ['type' => 'StopStmt', 'line' => $kw['line'], 'column' => $kw['column']];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseExprStmt(): array
    {
        $tok = $this->peek();
        $expr = $this->parseExpression();
        // Assignment statement: lhs = rhs
        if ($this->check(Lexer::T_PUNCT, '=')) {
            $this->advance();
            $rhs = $this->parseExpression();
            $this->expectPunct(';', 'statement ends with ;');
            return [
                'type' => 'AssignStmt',
                'target' => $expr,
                'value' => $rhs,
                'line' => $tok['line'],
                'column' => $tok['column'],
            ];
        }
        $this->expectPunct(';', 'statement ends with ;');
        return ['type' => 'ExprStmt', 'expression' => $expr, 'line' => $tok['line'], 'column' => $tok['column']];
    }

    // -----------------------------------------------------------------
    // Expressions (precedence climbing).
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function parseExpression(): array
    {
        return $this->parseTernary();
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTernary(): array
    {
        $cond = $this->parseLogicalOr();
        if ($this->match(Lexer::T_PUNCT, '?')) {
            $then = $this->parseTernary();
            $this->expectPunct(':', 'ternary needs a : between branches');
            $else = $this->parseTernary();
            return [
                'type' => 'TernaryExpr',
                'test' => $cond,
                'consequent' => $then,
                'alternate' => $else,
                'line' => $cond['line'] ?? 0,
                'column' => $cond['column'] ?? 0,
            ];
        }
        return $cond;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseLogicalOr(): array
    {
        $left = $this->parseLogicalAnd();
        while ($this->match(Lexer::T_PUNCT, '||')) {
            $right = $this->parseLogicalAnd();
            $left = ['type' => 'BinaryExpr', 'operator' => '||', 'left' => $left, 'right' => $right, 'line' => $left['line'] ?? 0, 'column' => $left['column'] ?? 0];
        }
        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseLogicalAnd(): array
    {
        $left = $this->parseEquality();
        while ($this->match(Lexer::T_PUNCT, '&&')) {
            $right = $this->parseEquality();
            $left = ['type' => 'BinaryExpr', 'operator' => '&&', 'left' => $left, 'right' => $right, 'line' => $left['line'] ?? 0, 'column' => $left['column'] ?? 0];
        }
        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseEquality(): array
    {
        $left = $this->parseComparison();
        while ($this->checkAny(Lexer::T_PUNCT, ['===', '!==', '==', '!='])) {
            $op = (string) $this->advance()['value'];
            $right = $this->parseComparison();
            $left = ['type' => 'BinaryExpr', 'operator' => $op, 'left' => $left, 'right' => $right, 'line' => $left['line'] ?? 0, 'column' => $left['column'] ?? 0];
        }
        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseComparison(): array
    {
        $left = $this->parseAdditive();
        while ($this->checkAny(Lexer::T_PUNCT, ['<', '>', '<=', '>='])) {
            $op = (string) $this->advance()['value'];
            $right = $this->parseAdditive();
            $left = ['type' => 'BinaryExpr', 'operator' => $op, 'left' => $left, 'right' => $right, 'line' => $left['line'] ?? 0, 'column' => $left['column'] ?? 0];
        }
        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAdditive(): array
    {
        $left = $this->parseMultiplicative();
        while ($this->checkAny(Lexer::T_PUNCT, ['+', '-'])) {
            $op = (string) $this->advance()['value'];
            $right = $this->parseMultiplicative();
            $left = ['type' => 'BinaryExpr', 'operator' => $op, 'left' => $left, 'right' => $right, 'line' => $left['line'] ?? 0, 'column' => $left['column'] ?? 0];
        }
        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMultiplicative(): array
    {
        $left = $this->parseUnary();
        while ($this->checkAny(Lexer::T_PUNCT, ['*', '/', '%'])) {
            $op = (string) $this->advance()['value'];
            $right = $this->parseUnary();
            $left = ['type' => 'BinaryExpr', 'operator' => $op, 'left' => $left, 'right' => $right, 'line' => $left['line'] ?? 0, 'column' => $left['column'] ?? 0];
        }
        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseUnary(): array
    {
        if ($this->checkAny(Lexer::T_PUNCT, ['!', '-', '+'])) {
            $tok = $this->advance();
            $arg = $this->parseUnary();
            return ['type' => 'UnaryExpr', 'operator' => (string) $tok['value'], 'argument' => $arg, 'line' => $tok['line'], 'column' => $tok['column']];
        }
        if ($this->checkKeyword('await')) {
            $tok = $this->advance();
            $arg = $this->parseUnary();
            return ['type' => 'AwaitExpr', 'argument' => $arg, 'line' => $tok['line'], 'column' => $tok['column']];
        }
        return $this->parseCallMember();
    }

    /**
     * Handles call and member-access chains: foo.bar.baz({...})(...).qux
     *
     * @return array<string, mixed>
     */
    private function parseCallMember(): array
    {
        $node = $this->parsePrimary();
        while (true) {
            if ($this->match(Lexer::T_PUNCT, '.')) {
                $prop_tok = $this->expect(Lexer::T_IDENT, 'identifier', 'member access expects a property name after .');
                $node = [
                    'type' => 'MemberExpr',
                    'object' => $node,
                    'property' => (string) $prop_tok['value'],
                    'computed' => false,
                    'line' => $node['line'] ?? 0,
                    'column' => $node['column'] ?? 0,
                ];
                continue;
            }
            if ($this->match(Lexer::T_PUNCT, '[')) {
                $expr = $this->parseExpression();
                $this->expectPunct(']', 'computed member access closes with ]');
                $node = [
                    'type' => 'MemberExpr',
                    'object' => $node,
                    'property' => $expr,
                    'computed' => true,
                    'line' => $node['line'] ?? 0,
                    'column' => $node['column'] ?? 0,
                ];
                continue;
            }
            if ($this->check(Lexer::T_PUNCT, '(')) {
                $args = $this->parseArgList();
                $node = [
                    'type' => 'CallExpr',
                    'callee' => $node,
                    'arguments' => $args,
                    'line' => $node['line'] ?? 0,
                    'column' => $node['column'] ?? 0,
                ];
                continue;
            }
            break;
        }
        return $node;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseArgList(): array
    {
        $this->expectPunct('(', 'argument list opens with (');
        $args = [];
        if (!$this->check(Lexer::T_PUNCT, ')')) {
            do {
                $args[] = $this->parseExpression();
            } while ($this->match(Lexer::T_PUNCT, ','));
        }
        $this->expectPunct(')', 'argument list closes with )');
        return $args;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePrimary(): array
    {
        $tok = $this->peek();
        if ($tok['type'] === Lexer::T_NUMBER) {
            $this->advance();
            return ['type' => 'LiteralNumber', 'value' => $tok['value']['value'], 'raw' => $tok['value']['raw'], 'line' => $tok['line'], 'column' => $tok['column']];
        }
        if ($tok['type'] === Lexer::T_STRING) {
            $this->advance();
            return ['type' => 'LiteralString', 'value' => $tok['value']['value'], 'raw' => $tok['value']['raw'], 'line' => $tok['line'], 'column' => $tok['column']];
        }
        if ($tok['type'] === Lexer::T_TEMPLATE) {
            $this->advance();
            $parts = $this->parseTemplateParts($tok['value']['parts'], $tok['line'], $tok['column']);
            return ['type' => 'TemplateExpr', 'parts' => $parts, 'line' => $tok['line'], 'column' => $tok['column']];
        }
        if ($tok['type'] === Lexer::T_KEYWORD) {
            switch ($tok['value']) {
                case 'true':
                    $this->advance();
                    return ['type' => 'LiteralBool', 'value' => true, 'line' => $tok['line'], 'column' => $tok['column']];
                case 'false':
                    $this->advance();
                    return ['type' => 'LiteralBool', 'value' => false, 'line' => $tok['line'], 'column' => $tok['column']];
                case 'null':
                case 'undefined':
                    $this->advance();
                    return ['type' => 'LiteralNull', 'line' => $tok['line'], 'column' => $tok['column']];
            }
        }
        // Soft keyword `now()` is just an identifier called as a function;
        // recognise it explicitly so the compiler can map to the
        // execution.date token without leaking a fake "now" function.
        if ($tok['type'] === Lexer::T_IDENT && (string) $tok['value'] === 'now') {
            $next = $this->tokens[$this->pos + 1] ?? null;
            if (is_array($next) && $next['type'] === Lexer::T_PUNCT && $next['value'] === '(') {
                $this->advance();
                $this->expectPunct('(', 'now() call opens with (');
                $this->expectPunct(')', 'now() takes no arguments');
                return ['type' => 'NowExpr', 'line' => $tok['line'], 'column' => $tok['column']];
            }
        }
        if ($tok['type'] === Lexer::T_IDENT) {
            $this->advance();
            return ['type' => 'Identifier', 'name' => (string) $tok['value'], 'line' => $tok['line'], 'column' => $tok['column']];
        }
        if ($tok['type'] === Lexer::T_PUNCT) {
            if ($tok['value'] === '(') {
                // Disambiguate between a parenthesised expression and an
                // arrow function. Arrow functions in our subset are only
                // accepted in the empty-arg form: `() => { ... }` and
                // `async () => { ... }`. Real arg lists are not needed
                // because the only consumer (retry()) does not pass args.
                $next = $this->tokens[$this->pos + 1] ?? null;
                $after = $this->tokens[$this->pos + 2] ?? null;
                if (is_array($next) && $next['type'] === Lexer::T_PUNCT && $next['value'] === ')'
                    && is_array($after) && $after['type'] === Lexer::T_PUNCT && $after['value'] === '=>'
                ) {
                    $start = $this->advance(); // (
                    $this->advance();          // )
                    $this->advance();          // =>
                    $this->expectPunct('{', 'arrow function body opens with {');
                    $body = [];
                    while (!$this->check(Lexer::T_PUNCT, '}') && !$this->isAtEnd()) {
                        $body[] = $this->parseStatement();
                    }
                    $this->expectPunct('}', 'arrow function body closes with }');
                    return ['type' => 'ArrowFunctionExpr', 'async' => false, 'body' => $body, 'line' => $start['line'], 'column' => $start['column']];
                }
                $this->advance();
                $expr = $this->parseExpression();
                $this->expectPunct(')', 'parenthesised expression closes with )');
                return $expr;
            }
            if ($tok['value'] === '{') {
                return $this->parseObjectLiteral();
            }
            if ($tok['value'] === '[') {
                return $this->parseArrayLiteral();
            }
        }
        // async () => { ... } prefix
        if ($tok['type'] === Lexer::T_KEYWORD && $tok['value'] === 'async') {
            $next = $this->tokens[$this->pos + 1] ?? null;
            $next2 = $this->tokens[$this->pos + 2] ?? null;
            $next3 = $this->tokens[$this->pos + 3] ?? null;
            if (is_array($next) && $next['type'] === Lexer::T_PUNCT && $next['value'] === '('
                && is_array($next2) && $next2['type'] === Lexer::T_PUNCT && $next2['value'] === ')'
                && is_array($next3) && $next3['type'] === Lexer::T_PUNCT && $next3['value'] === '=>'
            ) {
                $start = $this->advance(); // async
                $this->advance();           // (
                $this->advance();           // )
                $this->advance();           // =>
                $this->expectPunct('{', 'arrow function body opens with {');
                $body = [];
                while (!$this->check(Lexer::T_PUNCT, '}') && !$this->isAtEnd()) {
                    $body[] = $this->parseStatement();
                }
                $this->expectPunct('}', 'arrow function body closes with }');
                return ['type' => 'ArrowFunctionExpr', 'async' => true, 'body' => $body, 'line' => $start['line'], 'column' => $start['column']];
            }
        }
        throw $this->error('unexpected_token', sprintf('Unexpected token "%s".', (string) ($tok['value']['raw'] ?? $tok['value'])), 'Expected an expression here.');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseObjectLiteral(): array
    {
        $start = $this->expectPunct('{', 'object literal opens with {');
        $properties = [];
        if (!$this->check(Lexer::T_PUNCT, '}')) {
            do {
                $key_tok = $this->peek();
                if ($key_tok['type'] === Lexer::T_IDENT || $key_tok['type'] === Lexer::T_KEYWORD) {
                    $this->advance();
                    $key = (string) $key_tok['value'];
                } elseif ($key_tok['type'] === Lexer::T_STRING) {
                    $this->advance();
                    $key = (string) $key_tok['value']['value'];
                } else {
                    throw $this->error('invalid_object_key', 'Object literal keys must be identifiers or string literals.');
                }
                // Shorthand `{x}` → `{x: x}`
                if (!$this->check(Lexer::T_PUNCT, ':')) {
                    $value = ['type' => 'Identifier', 'name' => $key, 'line' => $key_tok['line'], 'column' => $key_tok['column']];
                } else {
                    $this->expectPunct(':', 'object property expects : between key and value');
                    $value = $this->parseExpression();
                }
                $properties[] = ['key' => $key, 'value' => $value, 'line' => $key_tok['line'], 'column' => $key_tok['column']];
            } while ($this->match(Lexer::T_PUNCT, ','));
        }
        $this->expectPunct('}', 'object literal closes with }');
        return ['type' => 'ObjectExpr', 'properties' => $properties, 'line' => $start['line'], 'column' => $start['column']];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseArrayLiteral(): array
    {
        $start = $this->expectPunct('[', 'array literal opens with [');
        $elements = [];
        if (!$this->check(Lexer::T_PUNCT, ']')) {
            do {
                $elements[] = $this->parseExpression();
            } while ($this->match(Lexer::T_PUNCT, ','));
        }
        $this->expectPunct(']', 'array literal closes with ]');
        return ['type' => 'ArrayExpr', 'elements' => $elements, 'line' => $start['line'], 'column' => $start['column']];
    }

    /**
     * Parse template parts (lexer hands raw strings and { __expr, line, column } items).
     * Returns an array alternating { type: 'TplLiteral', value } and { type: 'TplExpr', expression: <parsed>, line, column }.
     *
     * @param array<int, mixed> $rawParts
     * @return array<int, array<string, mixed>>
     */
    private function parseTemplateParts(array $rawParts, int $line, int $column): array
    {
        $out = [];
        foreach ($rawParts as $part) {
            if (is_string($part)) {
                $out[] = ['type' => 'TplLiteral', 'value' => $part];
                continue;
            }
            if (is_array($part) && isset($part['__expr'])) {
                $subLexer = new Lexer((string) $part['__expr']);
                $subTokens = $subLexer->tokenize();
                $subParser = new self($subTokens);
                $expr = $subParser->parseExpression();
                if (!$subParser->isAtEnd()) {
                    throw CompileError::parse('extra_template_tokens', (int) $part['line'], (int) $part['column'], 'Template interpolation contains more than one expression.');
                }
                $out[] = ['type' => 'TplExpr', 'expression' => $expr, 'line' => (int) $part['line'], 'column' => (int) $part['column']];
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Token cursor helpers.
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    /**
     * @return array<string, mixed>
     */
    private function advance(): array
    {
        $tok = $this->tokens[$this->pos];
        if ($this->pos < $this->count - 1) {
            $this->pos++;
        }
        return $tok;
    }

    private function isAtEnd(): bool
    {
        return $this->peek()['type'] === Lexer::T_EOF;
    }

    private function check(string $type, ?string $value = null): bool
    {
        $tok = $this->peek();
        if ($tok['type'] !== $type) {
            return false;
        }
        if ($value !== null) {
            $tok_val = is_array($tok['value']) ? null : (string) $tok['value'];
            return $tok_val === $value;
        }
        return true;
    }

    /**
     * @param array<int, string> $values
     */
    private function checkAny(string $type, array $values): bool
    {
        $tok = $this->peek();
        if ($tok['type'] !== $type) {
            return false;
        }
        return in_array((string) $tok['value'], $values, true);
    }

    private function checkKeyword(string $value): bool
    {
        return $this->check(Lexer::T_KEYWORD, $value);
    }

    /**
     * Match a "soft keyword" (an identifier that the DSL gives a special
     * meaning in some positions but lets the LLM use freely as a variable
     * name in others). Accepts either KEYWORD or IDENT with the value.
     */
    private function checkSoftKeyword(string $value): bool
    {
        $tok = $this->peek();
        if ($tok['type'] !== Lexer::T_KEYWORD && $tok['type'] !== Lexer::T_IDENT) {
            return false;
        }
        return (is_string($tok['value']) ? $tok['value'] : '') === $value;
    }

    private function matchSoftKeyword(string $value): bool
    {
        if ($this->checkSoftKeyword($value)) {
            $this->advance();
            return true;
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function expectSoftKeyword(string $value): array
    {
        $tok = $this->peek();
        $matches = ($tok['type'] === Lexer::T_KEYWORD || $tok['type'] === Lexer::T_IDENT)
            && (is_string($tok['value']) ? $tok['value'] : '') === $value;
        if (!$matches) {
            throw $this->error('expected_keyword', sprintf('Expected "%s", got "%s".', $value, (string) (is_array($tok['value']) ? '' : $tok['value'])));
        }
        return $this->advance();
    }

    private function match(string $type, ?string $value = null): bool
    {
        if ($this->check($type, $value)) {
            $this->advance();
            return true;
        }
        return false;
    }

    private function matchKeyword(string $value): bool
    {
        return $this->match(Lexer::T_KEYWORD, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function expect(string $type, string $label, string $hint = ''): array
    {
        $tok = $this->peek();
        if ($tok['type'] !== $type) {
            throw $this->error('expected_' . $type, sprintf('Expected %s, got %s "%s".', $label, $tok['type'], (string) ($tok['value']['raw'] ?? (is_array($tok['value']) ? '' : $tok['value']))), $hint);
        }
        return $this->advance();
    }

    /**
     * @return array<string, mixed>
     */
    private function expectKeyword(string $value): array
    {
        $tok = $this->peek();
        if ($tok['type'] !== Lexer::T_KEYWORD || (string) $tok['value'] !== $value) {
            throw $this->error('expected_keyword', sprintf('Expected keyword "%s", got "%s".', $value, (string) (is_array($tok['value']) ? '' : $tok['value'])));
        }
        return $this->advance();
    }

    /**
     * @return array<string, mixed>
     */
    private function expectPunct(string $value, string $hint = ''): array
    {
        $tok = $this->peek();
        if ($tok['type'] !== Lexer::T_PUNCT || (string) $tok['value'] !== $value) {
            throw $this->error('expected_punctuation', sprintf('Expected "%s", got "%s".', $value, (string) (is_array($tok['value']) ? '' : $tok['value'])), $hint);
        }
        return $this->advance();
    }

    private function error(string $code, string $reason, string $hint = ''): CompileError
    {
        $tok = $this->peek();
        return CompileError::parse($code, (int) ($tok['line'] ?? 0), (int) ($tok['column'] ?? 0), $reason, $hint);
    }
}

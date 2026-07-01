<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Sourcecode;

/**
 * Tokenises the workflow source language subset.
 *
 * Tokens are emitted as plain assoc arrays: { type, value, line, column }.
 * The lexer recognises the JS subset documented in
 * docs/WORKFLOW_SOURCE_LANGUAGE.md — it deliberately rejects forms the
 * compiler cannot handle (classes, exports, arrow functions outside
 * specific positions, etc.) so the LLM gets a precise error early.
 */
final class Lexer
{
    public const T_EOF = 'EOF';
    public const T_IDENT = 'IDENT';
    public const T_NUMBER = 'NUMBER';
    public const T_STRING = 'STRING';
    public const T_TEMPLATE = 'TEMPLATE';
    public const T_REGEX = 'REGEX';
    public const T_PUNCT = 'PUNCT';
    public const T_KEYWORD = 'KEYWORD';

    // Hard keywords. Soft keywords like `name`, `status`, `now`, `stop`,
    // `trigger`, `flow`, `pure`, `as`, `with` are demoted to identifiers
    // so the LLM can use them as variable / property names; the parser
    // disambiguates them by position (top-level vs inside a function body).
    private const KEYWORDS = [
        'async', 'await', 'function', 'return', 'if', 'else',
        'true', 'false', 'null', 'undefined',
        'const', 'let', 'var',
        'for', 'of', 'in', 'try', 'catch', 'finally', 'throw',
        'while', 'do', 'switch', 'case', 'default', 'break', 'continue',
        'new', 'this', 'class', 'extends', 'super', 'import', 'export',
        'typeof', 'instanceof', 'delete', 'void', 'yield',
    ];

    private string $src;
    private int $pos = 0;
    private int $line = 1;
    private int $col = 1;
    private int $length;

    public function __construct(string $source)
    {
        $this->src = $source;
        $this->length = strlen($source);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tokenize(): array
    {
        $tokens = [];
        while ($this->pos < $this->length) {
            $this->skipWhitespaceAndComments();
            if ($this->pos >= $this->length) {
                break;
            }
            $start_line = $this->line;
            $start_col = $this->col;
            $ch = $this->src[$this->pos];

            // Identifier / keyword
            if (ctype_alpha($ch) || $ch === '_' || $ch === '$') {
                $value = $this->readIdent();
                $type = in_array($value, self::KEYWORDS, true) ? self::T_KEYWORD : self::T_IDENT;
                $tokens[] = ['type' => $type, 'value' => $value, 'line' => $start_line, 'column' => $start_col];
                continue;
            }

            // Number
            if (ctype_digit($ch) || ($ch === '.' && $this->pos + 1 < $this->length && ctype_digit($this->src[$this->pos + 1]))) {
                $tokens[] = ['type' => self::T_NUMBER, 'value' => $this->readNumber(), 'line' => $start_line, 'column' => $start_col];
                continue;
            }

            // String literal (single or double quote)
            if ($ch === '"' || $ch === "'") {
                $tokens[] = ['type' => self::T_STRING, 'value' => $this->readString($ch), 'line' => $start_line, 'column' => $start_col];
                continue;
            }

            // Template literal (backticks)
            if ($ch === '`') {
                $tokens[] = ['type' => self::T_TEMPLATE, 'value' => $this->readTemplate(), 'line' => $start_line, 'column' => $start_col];
                continue;
            }

            // Punctuation / operators (multi-char first)
            $two = substr($this->src, $this->pos, 2);
            $three = substr($this->src, $this->pos, 3);
            if (in_array($three, ['===', '!==', '...', '**=', '>>>'], true)) {
                $this->advance(3);
                $tokens[] = ['type' => self::T_PUNCT, 'value' => $three, 'line' => $start_line, 'column' => $start_col];
                continue;
            }
            if (in_array($two, ['==', '!=', '>=', '<=', '&&', '||', '=>', '+=', '-=', '*=', '/=', '++', '--', '??', '?.', '<<', '>>', '**'], true)) {
                $this->advance(2);
                $tokens[] = ['type' => self::T_PUNCT, 'value' => $two, 'line' => $start_line, 'column' => $start_col];
                continue;
            }
            if (strpos('{}()[];,.:?!=<>+-*/%&|^~', $ch) !== false) {
                $this->advance(1);
                $tokens[] = ['type' => self::T_PUNCT, 'value' => $ch, 'line' => $start_line, 'column' => $start_col];
                continue;
            }

            throw CompileError::lex(
                'unexpected_character',
                $this->line,
                $this->col,
                sprintf('Unexpected character %s.', json_encode($ch)),
                'Stick to JS-subset syntax described in the source language spec.'
            );
        }

        $tokens[] = ['type' => self::T_EOF, 'value' => '', 'line' => $this->line, 'column' => $this->col];
        return $tokens;
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->pos < $this->length) {
            $ch = $this->src[$this->pos];
            if ($ch === "\n") {
                $this->pos++;
                $this->line++;
                $this->col = 1;
                continue;
            }
            if ($ch === "\r" || $ch === "\t" || $ch === ' ') {
                $this->advance(1);
                continue;
            }
            // Line comment
            if ($ch === '/' && $this->pos + 1 < $this->length && $this->src[$this->pos + 1] === '/') {
                while ($this->pos < $this->length && $this->src[$this->pos] !== "\n") {
                    $this->advance(1);
                }
                continue;
            }
            // Block comment
            if ($ch === '/' && $this->pos + 1 < $this->length && $this->src[$this->pos + 1] === '*') {
                $this->advance(2);
                while ($this->pos + 1 < $this->length && !($this->src[$this->pos] === '*' && $this->src[$this->pos + 1] === '/')) {
                    if ($this->src[$this->pos] === "\n") {
                        $this->pos++;
                        $this->line++;
                        $this->col = 1;
                    } else {
                        $this->advance(1);
                    }
                }
                if ($this->pos + 1 < $this->length) {
                    $this->advance(2); // closing */
                }
                continue;
            }
            return;
        }
    }

    private function readIdent(): string
    {
        $start = $this->pos;
        while ($this->pos < $this->length) {
            $ch = $this->src[$this->pos];
            if (ctype_alnum($ch) || $ch === '_' || $ch === '$') {
                $this->advance(1);
                continue;
            }
            break;
        }
        return substr($this->src, $start, $this->pos - $start);
    }

    /**
     * @return array{raw: string, value: float|int}
     */
    private function readNumber(): array
    {
        $start = $this->pos;
        $has_dot = false;
        while ($this->pos < $this->length) {
            $ch = $this->src[$this->pos];
            if (ctype_digit($ch)) {
                $this->advance(1);
                continue;
            }
            if ($ch === '.' && !$has_dot) {
                $has_dot = true;
                $this->advance(1);
                continue;
            }
            if (($ch === 'e' || $ch === 'E') && $this->pos + 1 < $this->length) {
                $next = $this->src[$this->pos + 1];
                if (ctype_digit($next) || $next === '+' || $next === '-') {
                    $this->advance(1);
                    if ($next === '+' || $next === '-') {
                        $this->advance(1);
                    }
                    continue;
                }
            }
            break;
        }
        $raw = substr($this->src, $start, $this->pos - $start);
        return ['raw' => $raw, 'value' => $has_dot || strpbrk($raw, 'eE') !== false ? (float) $raw : (int) $raw];
    }

    /**
     * @return array{raw: string, value: string, quote: string}
     */
    private function readString(string $quote): array
    {
        $start = $this->pos;
        $start_line = $this->line;
        $start_col = $this->col;
        $this->advance(1); // opening quote
        $out = '';
        while ($this->pos < $this->length) {
            $ch = $this->src[$this->pos];
            if ($ch === $quote) {
                $raw = substr($this->src, $start, $this->pos - $start + 1);
                $this->advance(1);
                return ['raw' => $raw, 'value' => $out, 'quote' => $quote];
            }
            if ($ch === '\\') {
                if ($this->pos + 1 >= $this->length) {
                    throw CompileError::lex('unterminated_escape', $start_line, $start_col, 'Unterminated escape inside string literal.');
                }
                $next = $this->src[$this->pos + 1];
                $out .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '\\' => '\\',
                    "'" => "'",
                    '"' => '"',
                    '`' => '`',
                    '0' => "\0",
                    default => $next,
                };
                $this->advance(2);
                continue;
            }
            if ($ch === "\n") {
                throw CompileError::lex('unterminated_string', $start_line, $start_col, 'String literal must be on a single line. Use a template literal (backticks) for multi-line text.');
            }
            $out .= $ch;
            $this->advance(1);
        }
        throw CompileError::lex('unterminated_string', $start_line, $start_col, 'Unterminated string literal.');
    }

    /**
     * Reads a template literal and returns its parts.
     * Parts is a list alternating strings and expressions:
     *   ['Hi ', ['__expr' => '<source text>', 'line' => N, 'column' => N], '!']
     *
     * @return array{raw: string, parts: array<int, mixed>}
     */
    private function readTemplate(): array
    {
        $start = $this->pos;
        $start_line = $this->line;
        $start_col = $this->col;
        $this->advance(1); // opening `
        $parts = [];
        $buffer = '';
        while ($this->pos < $this->length) {
            $ch = $this->src[$this->pos];
            if ($ch === '`') {
                if ($buffer !== '' || $parts === []) {
                    $parts[] = $buffer;
                }
                $raw = substr($this->src, $start, $this->pos - $start + 1);
                $this->advance(1);
                return ['raw' => $raw, 'parts' => $parts];
            }
            if ($ch === '\\' && $this->pos + 1 < $this->length) {
                $next = $this->src[$this->pos + 1];
                if ($next === 'u' && $this->pos + 5 < $this->length) {
                    $hex = substr($this->src, $this->pos + 2, 4);
                    if (ctype_xdigit($hex)) {
                        $codepoint = hexdec($hex);
                        $buffer .= mb_chr($codepoint, 'UTF-8');
                        $this->advance(6);
                        continue;
                    }
                }
                $buffer .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '\\' => '\\',
                    '`' => '`',
                    '$' => '$',
                    default => '\\' . $next,
                };
                $this->advance(2);
                continue;
            }
            if ($ch === '$' && $this->pos + 1 < $this->length && $this->src[$this->pos + 1] === '{') {
                if ($buffer !== '' || $parts === []) {
                    $parts[] = $buffer;
                }
                $buffer = '';
                $expr_line = $this->line;
                $expr_col = $this->col;
                $this->advance(2); // ${
                $expr_start = $this->pos;
                $depth = 1;
                while ($this->pos < $this->length && $depth > 0) {
                    $c = $this->src[$this->pos];
                    if ($c === '{') {
                        $depth++;
                    } elseif ($c === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    } elseif ($c === "\n") {
                        $this->pos++;
                        $this->line++;
                        $this->col = 1;
                        continue;
                    } elseif ($c === '`') {
                        throw CompileError::lex('nested_template', $expr_line, $expr_col, 'Nested template literals are not supported. Use a const to compute the inner string first.');
                    }
                    $this->advance(1);
                }
                if ($depth !== 0) {
                    throw CompileError::lex('unterminated_interpolation', $expr_line, $expr_col, 'Unterminated ${...} interpolation in template literal.');
                }
                $expr_text = substr($this->src, $expr_start, $this->pos - $expr_start);
                $this->advance(1); // closing }
                $parts[] = ['__expr' => $expr_text, 'line' => $expr_line, 'column' => $expr_col];
                continue;
            }
            if ($ch === "\n") {
                $buffer .= "\n";
                $this->pos++;
                $this->line++;
                $this->col = 1;
                continue;
            }
            $buffer .= $ch;
            $this->advance(1);
        }
        throw CompileError::lex('unterminated_template', $start_line, $start_col, 'Unterminated template literal.');
    }

    private function advance(int $n): void
    {
        $this->pos += $n;
        $this->col += $n;
    }
}

<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Sourcecode;

/**
 * Typed error surfaced by the lexer / parser / compiler / decompiler.
 *
 * Carries the offending source position so the LLM can self-correct
 * with the next edit. Stringified as `<line>:<col>: <message>`.
 *
 * Note: PHP's base Exception already declares $code, $line, $file. We
 * therefore use prefixed property names (errorCode / errorLine /
 * errorColumn) to avoid the "cannot redeclare readonly" fatal.
 */
final class CompileError extends \RuntimeException
{
    public const STAGE_LEX = 'lex';
    public const STAGE_PARSE = 'parse';
    public const STAGE_COMPILE = 'compile';
    public const STAGE_DECOMPILE = 'decompile';

    public function __construct(
        public readonly string $stage,
        public readonly string $errorCode,
        public readonly int $errorLine,
        public readonly int $errorColumn,
        public readonly string $reason,
        public readonly string $hint = '',
        public readonly string $snippet = ''
    ) {
        parent::__construct(self::format($stage, $errorCode, $errorLine, $errorColumn, $reason, $hint));
    }

    public static function format(string $stage, string $errorCode, int $line, int $column, string $reason, string $hint = ''): string
    {
        $out = $line > 0
            ? sprintf('%d:%d: [%s/%s] %s', $line, $column, $stage, $errorCode, $reason)
            : sprintf('[%s/%s] %s', $stage, $errorCode, $reason);
        if ($hint !== '') {
            $out .= ' Hint: ' . $hint;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'code' => $this->errorCode,
            'line' => $this->errorLine,
            'column' => $this->errorColumn,
            'reason' => $this->reason,
            'hint' => $this->hint,
            'snippet' => $this->snippet,
            'formatted' => $this->getMessage(),
        ];
    }

    // -----------------------------------------------------------------
    // Factories — keep the messages stable.
    // -----------------------------------------------------------------

    public static function lex(string $errorCode, int $line, int $column, string $reason, string $hint = ''): self
    {
        return new self(self::STAGE_LEX, $errorCode, $line, $column, $reason, $hint);
    }

    public static function parse(string $errorCode, int $line, int $column, string $reason, string $hint = ''): self
    {
        return new self(self::STAGE_PARSE, $errorCode, $line, $column, $reason, $hint);
    }

    public static function compile(string $errorCode, int $line, int $column, string $reason, string $hint = ''): self
    {
        return new self(self::STAGE_COMPILE, $errorCode, $line, $column, $reason, $hint);
    }

    public static function decompile(string $errorCode, string $reason, string $hint = ''): self
    {
        return new self(self::STAGE_DECOMPILE, $errorCode, 0, 0, $reason, $hint);
    }
}

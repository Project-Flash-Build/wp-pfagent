<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\Llm;

/**
 * Raised by Gateway implementations when the provider responds with a 400
 * that we recognise as "you sent me a parameter I don't support" (typically
 * response_format / json_schema on older models). The gateway catches this
 * internally to retry without the offending field; it should bubble up only
 * if degradation was not possible.
 */
final class UnsupportedParameterException extends \RuntimeException
{
}

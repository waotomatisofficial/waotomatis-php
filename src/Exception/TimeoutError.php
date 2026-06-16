<?php

declare(strict_types=1);

namespace Waotomatis\Exception;

/** The request exceeded the configured timeout. */
class TimeoutError extends WaotomatisException
{
    public function __construct(string $message = 'Request timed out.')
    {
        parent::__construct('timeout', $message, null, 408);
    }
}

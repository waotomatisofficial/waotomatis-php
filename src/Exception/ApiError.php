<?php

declare(strict_types=1);

namespace Waotomatis\Exception;

/** 5xx — an unexpected server-side failure. Safe to retry idempotent calls. */
class ApiError extends WaotomatisException
{
    public function __construct(
        string $code,
        string $message,
        ?string $requestId = null,
        int $status = 500
    ) {
        parent::__construct($code, $message, $requestId, $status);
    }
}

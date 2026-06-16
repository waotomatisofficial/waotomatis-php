<?php

declare(strict_types=1);

namespace Waotomatis\Exception;

/** 409 / 422 — the request was understood but rejected (validation, bad state). */
class ValidationError extends WaotomatisException
{
    public function __construct(
        string $code,
        string $message,
        ?string $requestId = null,
        int $status = 422
    ) {
        parent::__construct($code, $message, $requestId, $status);
    }
}

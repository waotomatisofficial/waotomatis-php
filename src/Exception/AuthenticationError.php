<?php

declare(strict_types=1);

namespace Waotomatis\Exception;

/** 401 — missing or invalid API key. */
class AuthenticationError extends WaotomatisException
{
    public function __construct(string $code, string $message, ?string $requestId = null)
    {
        parent::__construct($code, $message, $requestId, 401);
    }
}

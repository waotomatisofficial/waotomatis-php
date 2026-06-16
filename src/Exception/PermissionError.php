<?php

declare(strict_types=1);

namespace Waotomatis\Exception;

/** 403 — the key/user is not permitted to perform this action. */
class PermissionError extends WaotomatisException
{
    public function __construct(string $code, string $message, ?string $requestId = null)
    {
        parent::__construct($code, $message, $requestId, 403);
    }
}

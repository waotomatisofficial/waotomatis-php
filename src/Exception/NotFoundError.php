<?php

declare(strict_types=1);

namespace Waotomatis\Exception;

/** 404 — the addressed resource does not exist (or isn't visible to this key). */
class NotFoundError extends WaotomatisException
{
    public function __construct(string $code, string $message, ?string $requestId = null)
    {
        parent::__construct($code, $message, $requestId, 404);
    }
}

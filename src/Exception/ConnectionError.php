<?php

declare(strict_types=1);

namespace Waotomatis\Exception;

/** Network failure before a response was received (DNS, TLS, refused, reset). */
class ConnectionError extends WaotomatisException
{
    public function __construct(string $message, string $code = 'connection_error')
    {
        parent::__construct($code, $message, null, null);
    }
}

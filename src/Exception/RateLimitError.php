<?php

declare(strict_types=1);

namespace Waotomatis\Exception;

/** 429 — rate limited. `retryAfter` (seconds) is parsed from the `Retry-After` header. */
class RateLimitError extends WaotomatisException
{
    private ?int $retryAfter;

    public function __construct(
        string $message,
        ?string $requestId = null,
        ?int $retryAfter = null,
        string $code = 'rate_limited'
    ) {
        parent::__construct($code, $message, $requestId, 429);
        $this->retryAfter = $retryAfter;
    }

    /** Seconds to wait before retrying, as advised by the server, or null. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}

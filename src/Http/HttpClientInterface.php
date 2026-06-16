<?php

declare(strict_types=1);

namespace Waotomatis\Http;

use Waotomatis\Exception\ConnectionError;
use Waotomatis\Exception\TimeoutError;

/**
 * The transport seam. The SDK ships {@see HttpClient} (cURL + stream fallback);
 * implement this to plug in your own HTTP stack or to stub requests in tests.
 * Implementations must NOT throw on HTTP status — return the {@see Response} and
 * let the caller decide retry vs. error.
 */
interface HttpClientInterface
{
    /**
     * @param array<string,string> $headers
     *
     * @throws TimeoutError    on a request timeout
     * @throws ConnectionError on any other network failure before a response
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeoutSeconds
    ): Response;
}

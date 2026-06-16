<?php

declare(strict_types=1);

namespace Waotomatis\Http;

/** A minimal HTTP response value object used internally by the SDK. */
final class Response
{
    public int $status;

    /** Lower-cased header name => header value. */
    public array $headers;

    public string $body;

    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Decode the JSON body to an associative array. Returns [] for an empty body. */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}

<?php

declare(strict_types=1);

namespace Waotomatis\Http;

use Waotomatis\Exception\ConnectionError;
use Waotomatis\Exception\TimeoutError;

/**
 * Dependency-light HTTP transport. Prefers the cURL extension; falls back to the
 * PHP streams wrapper when cURL is unavailable. Both paths return the same
 * {@see Response} value object and never throw on HTTP status — the caller
 * decides retry vs. error (mirrors the TS SDK's `requestRaw`).
 */
final class HttpClient implements HttpClientInterface
{
    private bool $useCurl;

    public function __construct()
    {
        $this->useCurl = \extension_loaded('curl');
    }

    /**
     * Perform one request attempt.
     *
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
    ): Response {
        return $this->useCurl
            ? $this->sendCurl($method, $url, $headers, $body, $timeoutSeconds)
            : $this->sendStream($method, $url, $headers, $body, $timeoutSeconds);
    }

    /** @param array<string,string> $headers */
    private function sendCurl(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeoutSeconds
    ): Response {
        $ch = curl_init();

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$responseHeaders): int {
                $len = \strlen($line);
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $len;
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($errno === CURLE_OPERATION_TIMEOUTED) {
                throw new TimeoutError(sprintf('Request timed out after %.0fms.', $timeoutSeconds * 1000));
            }
            throw new ConnectionError('Network error: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Response($status, $responseHeaders, (string) $raw);
    }

    /** @param array<string,string> $headers */
    private function sendStream(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeoutSeconds
    ): Response {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true, // capture 4xx/5xx bodies instead of warning
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $error = error_get_last();
            $message = $error['message'] ?? 'unknown error';
            if (stripos($message, 'timed out') !== false) {
                throw new TimeoutError(sprintf('Request timed out after %.0fms.', $timeoutSeconds * 1000));
            }
            throw new ConnectionError('Network error: ' . $message);
        }

        // $http_response_header is populated in the local scope by the stream wrapper.
        [$status, $responseHeaders] = $this->parseStreamHeaders($http_response_header ?? []);

        return new Response($status, $responseHeaders, $raw);
    }

    /**
     * @param array<int,string> $lines
     *
     * @return array{0:int,1:array<string,string>}
     */
    private function parseStreamHeaders(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                // Reset on each status line so redirects keep the final status/headers.
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (\count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return [$status, $headers];
    }
}

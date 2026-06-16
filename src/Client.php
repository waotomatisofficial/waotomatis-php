<?php

declare(strict_types=1);

namespace Waotomatis;

use Waotomatis\Exception\ConnectionError;
use Waotomatis\Exception\TimeoutError;
use Waotomatis\Exception\WaotomatisException;
use Waotomatis\Http\HttpClient;
use Waotomatis\Http\HttpClientInterface;
use Waotomatis\Http\Multipart;
use Waotomatis\Http\Response;
use Waotomatis\Resources\SessionResource;
use Waotomatis\Resources\Sessions;

/**
 * The WAOtomatis API client — headless WhatsApp (WABA Cloud API).
 *
 *   use Waotomatis\Client;
 *
 *   $wao = new Client(getenv('WAO_API_KEY'));
 *
 *   $msg = $wao->sessions('sess_123')->messages->send([
 *       'to'   => '628123456789',
 *       'type' => 'text',
 *       'text' => 'Halo dari WAOtomatis 👋',
 *   ]);
 *
 *   echo $msg['id']; // msg_abc123
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.waotomatis.com';

    private const DEFAULT_MAX_RETRIES = 2;
    private const DEFAULT_TIMEOUT_SECONDS = 60.0;

    /**
     * Only statuses that can plausibly succeed on an identical retry. Mirrors the
     * TS SDK: the server's sole 409 is the permanent `session_disconnected`
     * conflict, so it is deliberately excluded.
     */
    private const RETRYABLE_STATUS = [408, 429, 500, 502, 503, 504];
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    /** Hard ceiling (seconds) on a server-supplied `Retry-After`. */
    private const MAX_RETRY_AFTER_SECONDS = 60;

    private string $apiKey;
    private string $baseUrl;
    private int $maxRetries;
    private float $timeoutSeconds;

    /** @var array<string,string> */
    private array $defaultHeaders;

    private HttpClientInterface $http;

    /** Session list/get scope plus a callable to scope into one session. */
    public Sessions $sessions;

    /**
     * @param string $apiKey  the API key (sent as `Authorization: Bearer <apiKey>`)
     * @param array{
     *     baseUrl?: string,
     *     maxRetries?: int,
     *     timeout?: float,
     *     headers?: array<string,string>,
     *     http?: HttpClientInterface
     * } $options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Waotomatis: `apiKey` is required.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($options['baseUrl'] ?? self::DEFAULT_BASE_URL, '/');
        $this->maxRetries = $options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES;
        $this->timeoutSeconds = $options['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS;
        $this->defaultHeaders = $options['headers'] ?? [];
        $this->http = $options['http'] ?? new HttpClient();

        $this->sessions = new Sessions($this);
    }

    /**
     * Scope to a single session:
     * `$wao->sessions('sess_123')->messages->send([...])`.
     *
     * A PHP class may expose a property and a method of the same name, so the
     * `sessions` property (collection: `$wao->sessions->list()`) and this
     * `sessions()` method (scope: `$wao->sessions('id')`) coexist — making the
     * advertised landing snippet work verbatim.
     */
    public function sessions(string $id): SessionResource
    {
        return new SessionResource($this, $id);
    }

    // ── Low-level request engine ────────────────────────────────────────────────

    /**
     * Send a JSON request and decode the response. Maps any non-2xx response to
     * the matching {@see WaotomatisException} subclass.
     *
     * @param array<string,scalar|null> $query
     * @param array<mixed>|null         $body           JSON body (encoded for you)
     * @param array<string,string>      $headers        extra per-request headers
     *
     * @return array<mixed>
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
        ?string $idempotencyKey = null
    ): array {
        $jsonBody = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $res = $this->requestRaw(
            $method,
            $path,
            $query,
            $jsonBody,
            $jsonBody === null ? $headers : $headers + ['Content-Type' => 'application/json'],
            $idempotencyKey
        );

        return $this->decode($res);
    }

    /**
     * Send a pre-built raw body (e.g. multipart). Such requests are never
     * auto-retried even with an idempotency key — the body may not be replayable.
     *
     * @param array<string,string> $headers
     *
     * @return array<mixed>
     */
    public function requestMultipart(
        string $method,
        string $path,
        Multipart $multipart,
        array $headers = [],
        ?string $idempotencyKey = null
    ): array {
        $res = $this->requestRaw(
            $method,
            $path,
            [],
            $multipart->body,
            $headers + ['Content-Type' => $multipart->contentType()],
            $idempotencyKey,
            false
        );

        return $this->decode($res);
    }

    /**
     * Raw request with full resilience: retries with exponential backoff + jitter
     * honoring `Retry-After`, only for idempotent verbs or requests carrying an
     * idempotency key. Returns the final {@see Response} (may be non-2xx).
     *
     * @param array<string,scalar|null> $query
     * @param array<string,string>      $headers
     */
    public function requestRaw(
        string $method,
        string $path,
        array $query = [],
        ?string $body = null,
        array $headers = [],
        ?string $idempotencyKey = null,
        bool $retryable = true
    ): Response {
        $method = strtoupper($method);
        $url = $this->buildUrl($path, $query);
        $finalHeaders = $this->buildHeaders($headers, $idempotencyKey);

        $canRetry = $retryable
            && (\in_array($method, self::IDEMPOTENT_METHODS, true) || $idempotencyKey !== null);

        $attempt = 0;
        while (true) {
            try {
                $res = $this->http->send($method, $url, $finalHeaders, $body, $this->timeoutSeconds);

                if (
                    $res->ok()
                    || !$canRetry
                    || $attempt >= $this->maxRetries
                    || !\in_array($res->status, self::RETRYABLE_STATUS, true)
                ) {
                    return $res;
                }

                $this->sleep($this->backoff($attempt, $this->parseRetryAfter($res)));
                $attempt++;
            } catch (TimeoutError | ConnectionError $e) {
                if (!$canRetry || $attempt >= $this->maxRetries) {
                    throw $e;
                }
                $this->sleep($this->backoff($attempt, null));
                $attempt++;
            }
        }
    }

    /**
     * Decode a response into an array, raising the right exception on non-2xx.
     *
     * @return array<mixed>
     */
    public function decode(Response $res): array
    {
        $json = $res->json();

        if (!$res->ok()) {
            throw $this->errorFromResponse($res, $json);
        }

        return $json;
    }

    /**
     * Map a non-2xx response (already-decoded body optional) to an exception.
     *
     * @param array<mixed>|null $json
     */
    public function errorFromResponse(Response $res, ?array $json = null): WaotomatisException
    {
        $json = $json ?? $res->json();
        $error = \is_array($json['error'] ?? null) ? $json['error'] : [];

        return WaotomatisException::fromStatus(
            $res->status,
            (string) ($error['code'] ?? 'internal_error'),
            (string) ($error['message'] ?? ('Request failed with status ' . $res->status)),
            isset($error['requestId']) ? (string) $error['requestId'] : null,
            $this->parseRetryAfter($res)
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /** @param array<string,scalar|null> $query */
    private function buildUrl(string $path, array $query): string
    {
        $url = $this->baseUrl . $path;
        $filtered = [];
        foreach ($query as $key => $value) {
            if ($value !== null) {
                $filtered[$key] = $value;
            }
        }
        if ($filtered !== []) {
            $url .= '?' . http_build_query($filtered);
        }

        return $url;
    }

    /**
     * @param array<string,string> $headers
     *
     * @return array<string,string>
     */
    private function buildHeaders(array $headers, ?string $idempotencyKey): array
    {
        $base = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'waotomatis-php/' . self::version(),
        ];
        if ($idempotencyKey !== null) {
            $base['Idempotency-Key'] = $idempotencyKey;
        }

        // Later arrays win: defaults < client defaults < per-request headers.
        return $base + $this->defaultHeaders + $headers;
    }

    private function backoff(int $attempt, ?int $retryAfterSeconds): float
    {
        if ($retryAfterSeconds !== null) {
            return (float) min($retryAfterSeconds, self::MAX_RETRY_AFTER_SECONDS);
        }
        $base = min(1.0 * (2 ** $attempt), 20.0);

        // Full-ish jitter, matching the TS SDK.
        return $base / 2 + (mt_rand() / mt_getrandmax()) * ($base / 2);
    }

    private function parseRetryAfter(Response $res): ?int
    {
        $raw = $res->header('retry-after');
        if ($raw === null) {
            return null;
        }
        if (is_numeric($raw)) {
            return max(0, (int) $raw);
        }
        $ts = strtotime($raw);
        if ($ts !== false) {
            return max(0, $ts - time());
        }

        return null;
    }

    private function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /** The installed SDK version (kept in sync with composer.json). */
    public static function version(): string
    {
        return '0.3.0';
    }
}

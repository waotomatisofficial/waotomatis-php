<?php

declare(strict_types=1);

namespace Waotomatis\Exception;

use RuntimeException;

/**
 * Base exception for every failure surfaced by the SDK. Mirrors the server's
 * uniform error model `{ error: { code, message, requestId } }` plus the HTTP
 * status. Branch on {@see WaotomatisException::getCodeName()} for stable
 * handling across API versions — the codes are the public contract shared by
 * the server, the SDK, and the MCP tools.
 *
 *   try {
 *       $wao->sessions($id)->messages->sendText($to, $text);
 *   } catch (RateLimitError $e) {
 *       sleep($e->getRetryAfter() ?? 1);
 *   } catch (WaotomatisException $e) {
 *       if ($e->getCodeName() === 'session_disconnected') { ... }
 *   }
 */
class WaotomatisException extends RuntimeException
{
    /** Stable, snake_case error code (e.g. "session_disconnected"). */
    protected string $codeName;

    /** Server-issued request id for support/correlation, when present. */
    protected ?string $requestId;

    /** HTTP status code, or null for client-side (network/timeout) failures. */
    protected ?int $status;

    public function __construct(
        string $codeName,
        string $message,
        ?string $requestId = null,
        ?int $status = null
    ) {
        parent::__construct($message);
        $this->codeName = $codeName;
        $this->requestId = $requestId;
        $this->status = $status;
    }

    /** The stable snake_case error code (use this for branching, not the message). */
    public function getCodeName(): string
    {
        return $this->codeName;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    /**
     * Construct the right subclass for an HTTP error response. Mirrors the TS
     * SDK's `errorFromStatus`.
     */
    public static function fromStatus(
        int $status,
        string $code,
        string $message,
        ?string $requestId = null,
        ?int $retryAfter = null
    ): WaotomatisException {
        switch ($status) {
            case 401:
                return new AuthenticationError($code, $message, $requestId);
            case 403:
                return new PermissionError($code, $message, $requestId);
            case 404:
                return new NotFoundError($code, $message, $requestId);
            case 408:
                return new TimeoutError($message);
            case 409:
            case 422:
                return new ValidationError($code, $message, $requestId, $status);
            case 429:
                return new RateLimitError($message, $requestId, $retryAfter, $code);
            default:
                if ($status >= 500) {
                    return new ApiError($code, $message, $requestId, $status);
                }
                // Other 4xx — surface as the base error with its code.
                return new WaotomatisException($code, $message, $requestId, $status);
        }
    }
}

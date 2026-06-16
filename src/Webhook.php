<?php

declare(strict_types=1);

namespace Waotomatis;

use Waotomatis\Exception\WaotomatisException;

/**
 * Verify and parse incoming WAOtomatis webhook deliveries. Stays in sync with
 * the server's scheme: HMAC-SHA256 over the EXACT raw request body, hex-encoded,
 * delivered in the `X-Wao-Signature` header as `sha256=<hex>`.
 *
 *   // e.g. inside your controller:
 *   $raw = file_get_contents('php://input');
 *   $sig = $_SERVER['HTTP_X_WAO_SIGNATURE'] ?? null;
 *
 *   if (!Webhook::verify($raw, $sig, $secret)) {
 *       http_response_code(401);
 *       return;
 *   }
 *   $event = Webhook::constructEvent($raw, $sig, $secret);
 *   if ($event['event'] === 'message.received') {
 *       echo $event['data']['text'] ?? '';
 *   }
 */
final class Webhook
{
    /** Header the server signs deliveries with (value: `sha256=<hex>`). */
    public const SIGNATURE_HEADER = 'X-Wao-Signature';

    /**
     * Verify an incoming webhook signature against the raw body. Uses a
     * constant-time comparison.
     *
     * @param string      $rawBody   the EXACT bytes received (do NOT re-encode a
     *                               parsed object — that breaks the HMAC)
     * @param string|null $signature the `X-Wao-Signature` header value
     * @param string      $secret    the webhook signing secret (returned once at
     *                               registration)
     */
    public static function verify(string $rawBody, ?string $signature, string $secret): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }
        $provided = preg_replace('/^sha256=/', '', $signature);
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, (string) $provided);
    }

    /**
     * Verify the signature AND parse the body into an event array. Throws a
     * {@see WaotomatisException} (`code: unauthorized`) on a bad signature, or
     * (`code: validation_failed`) on an unparseable / shapeless body.
     *
     * @return array<string,mixed> the decoded event envelope
     *                            ({ eventId, event, sessionId, createdAt, data })
     */
    public static function constructEvent(string $rawBody, ?string $signature, string $secret): array
    {
        if (!self::verify($rawBody, $signature, $secret)) {
            throw new WaotomatisException('unauthorized', 'Invalid webhook signature.', null, 401);
        }

        $parsed = json_decode($rawBody, true);
        if (!\is_array($parsed)) {
            throw new WaotomatisException('validation_failed', 'Webhook body is not valid JSON.', null, 422);
        }
        if (!isset($parsed['event']) || !\is_string($parsed['event'])) {
            throw new WaotomatisException('validation_failed', 'Webhook body is missing an `event`.', null, 422);
        }

        return $parsed;
    }
}

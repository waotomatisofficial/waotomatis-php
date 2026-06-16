<?php

declare(strict_types=1);

namespace Waotomatis\Tests;

use PHPUnit\Framework\TestCase;
use Waotomatis\Exception\WaotomatisException;
use Waotomatis\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'shh';
    private const BODY = '{"event":"message.received","data":{"text":"hi"}}';

    private function sign(string $body, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function testVerifyAcceptsAValidSignature(): void
    {
        self::assertTrue(Webhook::verify(self::BODY, $this->sign(self::BODY), self::SECRET));
    }

    public function testVerifyRejectsTamperedBody(): void
    {
        $sig = $this->sign(self::BODY);
        self::assertFalse(Webhook::verify(self::BODY . ' ', $sig, self::SECRET));
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        self::assertFalse(Webhook::verify(self::BODY, $this->sign(self::BODY), 'other'));
    }

    public function testVerifyRejectsNullSignature(): void
    {
        self::assertFalse(Webhook::verify(self::BODY, null, self::SECRET));
    }

    public function testConstructEventReturnsDecodedEnvelope(): void
    {
        $event = Webhook::constructEvent(self::BODY, $this->sign(self::BODY), self::SECRET);
        self::assertSame('message.received', $event['event']);
        self::assertSame('hi', $event['data']['text']);
    }

    public function testConstructEventRejectsBadSignature(): void
    {
        try {
            Webhook::constructEvent(self::BODY, 'sha256=bad', self::SECRET);
            self::fail('expected exception');
        } catch (WaotomatisException $e) {
            self::assertSame('unauthorized', $e->getCodeName());
            self::assertSame(401, $e->getStatus());
        }
    }

    public function testConstructEventRejectsInvalidJson(): void
    {
        $body = 'not json';
        try {
            Webhook::constructEvent($body, $this->sign($body), self::SECRET);
            self::fail('expected exception');
        } catch (WaotomatisException $e) {
            self::assertSame('validation_failed', $e->getCodeName());
        }
    }

    public function testConstructEventRejectsMissingEvent(): void
    {
        $body = '{"data":{}}';
        try {
            Webhook::constructEvent($body, $this->sign($body), self::SECRET);
            self::fail('expected exception');
        } catch (WaotomatisException $e) {
            self::assertSame('validation_failed', $e->getCodeName());
        }
    }
}

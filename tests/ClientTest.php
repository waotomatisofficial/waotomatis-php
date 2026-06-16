<?php

declare(strict_types=1);

namespace Waotomatis\Tests;

use PHPUnit\Framework\TestCase;
use Waotomatis\Client;
use Waotomatis\Exception\AuthenticationError;
use Waotomatis\Exception\NotFoundError;
use Waotomatis\Exception\RateLimitError;
use Waotomatis\Exception\ValidationError;
use Waotomatis\Exception\WaotomatisException;
use Waotomatis\Http\Response;

final class ClientTest extends TestCase
{
    private function client(StubHttpClient $http, int $maxRetries = 0): Client
    {
        return new Client('apiKey', ['http' => $http, 'maxRetries' => $maxRetries]);
    }

    public function testLandingSnippetSendsTextMessage(): void
    {
        $http = new StubHttpClient();
        $http->push(new Response(201, ['content-type' => 'application/json'], json_encode([
            'id' => 'msg_abc123', 'eventId' => 'ev_1', 'status' => 'sent',
        ])));

        $wao = $this->client($http);
        $msg = $wao->sessions('sess_123')->messages->sendText('628123456789', 'Halo dari WAOtomatis 👋');

        self::assertSame('msg_abc123', $msg['id']);

        $call = $http->lastCall();
        self::assertSame('POST', $call['method']);
        self::assertStringEndsWith('/v1/sessions/sess_123/messages/text', $call['url']);
        self::assertStringStartsWith('https://api.waotomatis.com', $call['url']);
        self::assertSame('Bearer apiKey', $call['headers']['Authorization']);
        self::assertSame('application/json', $call['headers']['Content-Type']);
        self::assertStringContainsString('👋', (string) $call['body']);

        $body = json_decode((string) $call['body'], true);
        self::assertSame('628123456789', $body['to']);
        self::assertSame('Halo dari WAOtomatis 👋', $body['text']);
    }

    public function testIdempotencyKeyBecomesHeaderAndIsStrippedFromBody(): void
    {
        $http = new StubHttpClient();
        $http->push(new Response(201, [], '{"id":"m","eventId":"e","status":"sent"}'));

        $wao = $this->client($http);
        $wao->sessions('s1')->messages->sendText('1', 'hi', idempotencyKey: 'k1');

        $call = $http->lastCall();
        self::assertSame('k1', $call['headers']['Idempotency-Key']);
        self::assertArrayNotHasKey('idempotencyKey', json_decode((string) $call['body'], true));
    }

    public function testMarkReadEncodesWamid(): void
    {
        $http = new StubHttpClient();
        $http->push(new Response(200, [], '{"status":"ok"}'));

        $this->client($http)->sessions('s1')->messages->markRead('wamid.AB/c+d=');

        self::assertStringContainsString('wamid.AB%2Fc%2Bd%3D/read', $http->lastCall()['url']);
    }

    public function testUploadFromUrlSendsJson(): void
    {
        $http = new StubHttpClient();
        $http->push(new Response(201, [], '{"mediaId":"md_1","mimeType":"image/png","size":1}'));

        $res = $this->client($http)->sessions('s1')->media->uploadFromUrl('https://x/y.png', 'image/png');

        self::assertSame('md_1', $res['mediaId']);
        $body = json_decode((string) $http->lastCall()['body'], true);
        self::assertSame('https://x/y.png', $body['url']);
        self::assertSame('image/png', $body['mimeType']);
    }

    public function testUploadRawBytesSendsMultipart(): void
    {
        $http = new StubHttpClient();
        $http->push(new Response(201, [], '{"mediaId":"md_2","mimeType":"text/plain","size":3}'));

        $this->client($http)->sessions('s1')->media->upload('abc', 'f.txt', 'text/plain');

        $call = $http->lastCall();
        self::assertStringStartsWith('multipart/form-data; boundary=', $call['headers']['Content-Type']);
        self::assertStringContainsString('name="file"', (string) $call['body']);
        self::assertStringContainsString('filename="f.txt"', (string) $call['body']);
    }

    public function testDownloadReturnsRawBytes(): void
    {
        $http = new StubHttpClient();
        $http->push(new Response(200, ['content-type' => 'image/jpeg'], "\xFF\xD8RAW"));

        $dl = $this->client($http)->sessions('s1')->media->download('md');

        self::assertSame("\xFF\xD8RAW", $dl['data']);
        self::assertSame('image/jpeg', $dl['mimeType']);
    }

    public function testSessionsListAndGet(): void
    {
        $http = new StubHttpClient();
        $http->push(new Response(200, [], '{"data":[{"id":"s1"}],"hasMore":false}'));
        $http->push(new Response(200, [], '{"id":"s1","status":"connected"}'));

        $wao = $this->client($http);
        $list = $wao->sessions->list();
        self::assertFalse($list['hasMore']);
        self::assertStringEndsWith('/v1/sessions', $http->calls[0]['url']);

        $one = $wao->sessions->get('s1');
        self::assertSame('s1', $one['id']);
    }

    /**
     * @dataProvider errorCases
     */
    public function testErrorMapping(int $status, string $expectedClass, string $code): void
    {
        $http = new StubHttpClient();
        $http->push(new Response($status, ['retry-after' => '5'], json_encode([
            'error' => ['code' => $code, 'message' => 'x', 'requestId' => 'req_9'],
        ])));

        $this->expectException($expectedClass);
        $this->client($http)->sessions->get('x');
    }

    /** @return array<string,array{0:int,1:class-string,2:string}> */
    public static function errorCases(): array
    {
        return [
            '401' => [401, AuthenticationError::class, 'unauthorized'],
            '404' => [404, NotFoundError::class, 'session_not_found'],
            '422' => [422, ValidationError::class, 'validation_failed'],
            '429' => [429, RateLimitError::class, 'rate_limited'],
        ];
    }

    public function testErrorCarriesRequestIdAndStatus(): void
    {
        $http = new StubHttpClient();
        $http->push(new Response(404, [], json_encode([
            'error' => ['code' => 'session_not_found', 'message' => 'gone', 'requestId' => 'req_42'],
        ])));

        try {
            $this->client($http)->sessions->get('x');
            self::fail('expected exception');
        } catch (WaotomatisException $e) {
            self::assertSame('session_not_found', $e->getCodeName());
            self::assertSame('req_42', $e->getRequestId());
            self::assertSame(404, $e->getStatus());
        }
    }

    public function testRateLimitErrorExposesRetryAfter(): void
    {
        $http = new StubHttpClient();
        $http->push(new Response(429, ['retry-after' => '7'], '{"error":{"code":"rate_limited","message":"slow","requestId":"r"}}'));

        try {
            $this->client($http)->sessions->get('x');
            self::fail('expected exception');
        } catch (RateLimitError $e) {
            self::assertSame(7, $e->getRetryAfter());
        }
    }

    public function testRetriesTransientGetThenSucceeds(): void
    {
        $http = new StubHttpClient();
        $http->push(new Response(503, [], '{"error":{"code":"internal_error","message":"x","requestId":"r"}}'));
        $http->push(new Response(200, [], '{"data":[],"hasMore":false}'));

        $list = $this->client($http, 2)->sessions->list();

        self::assertFalse($list['hasMore']);
        self::assertCount(2, $http->calls);
    }

    public function testPostWithoutIdempotencyKeyIsNotRetried(): void
    {
        $http = new StubHttpClient();
        $http->push(new Response(503, [], '{"error":{"code":"internal_error","message":"x","requestId":"r"}}'));
        $http->push(new Response(200, [], '{"id":"m"}'));

        $wao = $this->client($http, 2);
        try {
            $wao->sessions('s1')->messages->sendText('1', 'h');
            self::fail('expected exception');
        } catch (WaotomatisException $e) {
            self::assertCount(1, $http->calls);
        }
    }

    public function testEmptyApiKeyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }
}

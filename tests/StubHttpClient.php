<?php

declare(strict_types=1);

namespace Waotomatis\Tests;

use Waotomatis\Http\HttpClientInterface;
use Waotomatis\Http\Response;

/** A scriptable transport for tests — records calls, replays a queue of responses. */
final class StubHttpClient implements HttpClientInterface
{
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];

    /** @var Response[] */
    public array $queue = [];

    public function push(Response $res): void
    {
        $this->queue[] = $res;
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeoutSeconds
    ): Response {
        $this->calls[] = compact('method', 'url', 'headers', 'body');

        return array_shift($this->queue) ?? new Response(200, [], '{}');
    }

    /** @return array{method:string,url:string,headers:array<string,string>,body:?string} */
    public function lastCall(): array
    {
        return $this->calls[\count($this->calls) - 1];
    }
}

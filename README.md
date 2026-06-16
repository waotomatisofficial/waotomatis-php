# WAOtomatis PHP SDK

Official PHP SDK for [WAOtomatis](https://waotomatis.com) — headless WhatsApp
infrastructure on the WhatsApp Business Platform (WABA Cloud API).

- Dependency-light: standard library only (cURL extension, with a pure-PHP
  stream fallback). No Guzzle, no PSR-7.
- Idiomatic: typed properties, strict types, exceptions extending a single
  `WaotomatisException` base.
- Mirrors the official TypeScript SDK's surface, error model, and webhook HMAC.

## Requirements

- PHP 8.1+
- `ext-json` (required); `ext-curl` (recommended — falls back to streams if absent)

## Install

```bash
composer require waotomatis/sdk
```

## Quickstart

```php
use Waotomatis\Client;

$wao = new Client(getenv('WAO_API_KEY'));

$msg = $wao->sessions('sess_123')->messages->send([
    'to'   => '628123456789',
    'type' => 'text',
    'text' => 'Halo dari WAOtomatis 👋',
]);

echo $msg['id']; // msg_abc123
```

The client base URL defaults to `https://api.waotomatis.com`. Authentication is
sent as `Authorization: Bearer <apiKey>`.

## Configuration

```php
$wao = new Client('wao_live_...', [
    'baseUrl'    => 'https://api.waotomatis.com', // override the API base
    'timeout'    => 60.0,                         // per-request timeout (seconds)
    'maxRetries' => 2,                            // retries for 408/429/5xx/network
    'headers'    => ['X-Org-Id' => 'org_123'],    // sent on every request
]);
```

Transient failures (HTTP 408/429/500/502/503/504 and network errors) are retried
with exponential backoff + jitter, honoring the `Retry-After` header. Only
idempotent verbs — or requests carrying an idempotency key — are retried.

## Sessions

```php
// Scope into one session.
$session = $wao->sessions('sess_123');

// List / get / disconnect.
$page    = $wao->sessions->list();   // ['data' => [...], 'hasMore' => bool, 'cursor' => ?string]
$one     = $wao->sessions->get('sess_123');
$wao->sessions->delete('sess_123');

// (equivalent fluent forms)
$one = $wao->sessions('sess_123')->get();
$wao->sessions('sess_123')->delete();
```

## Sending messages

`messages->send()` takes an array matching the API's `SendMessageInput`.

```php
// Text (with link preview)
$wao->sessions('sess_123')->messages->send([
    'to'         => '628123456789',
    'type'       => 'text',
    'text'       => 'Cek https://waotomatis.com',
    'previewUrl' => true,
]);

// Image by uploaded media id
$wao->sessions('sess_123')->messages->send([
    'to'      => '628123456789',
    'type'    => 'image',
    'mediaId' => 'media_abc',
    'caption' => 'Invoice',
]);

// Document by public link
$wao->sessions('sess_123')->messages->send([
    'to'       => '628123456789',
    'type'     => 'document',
    'link'     => 'https://example.com/invoice.pdf',
    'fileName' => 'invoice.pdf',
]);

// Audio as a voice note
$wao->sessions('sess_123')->messages->send([
    'to'      => '628123456789',
    'type'    => 'audio',
    'mediaId' => 'media_xyz',
    'voice'   => true,
]);
```

Media types: `image`, `video`, `audio`, `document` (and `sticker`) accept either
`mediaId` (from an upload) or a public `link`. `template` and `interactive`
messages are also supported via their `template` / `interactive` keys.

### Idempotency

Pass a key so a retried send returns the original result instead of duplicating:

```php
$wao->sessions('sess_123')->messages->send($input, 'order-4711');
// or inline: $input['idempotencyKey'] = 'order-4711';
```

### Mark as read

```php
$wao->sessions('sess_123')->messages->markRead($providerMessageId);
```

## Media upload & download

```php
$session = $wao->sessions('sess_123');

// By URL
$m = $session->media->uploadFromUrl('https://example.com/photo.jpg', 'image/jpeg');

// By raw bytes
$m = $session->media->upload($bytes, 'photo.jpg', 'image/jpeg');

// By local file path
$m = $session->media->uploadFile('/path/to/photo.jpg');

echo $m['mediaId']; // pass this to messages->send([... 'mediaId' => $m['mediaId']])

// Download inbound media bytes
$dl = $session->media->download('media_abc');
file_put_contents('out.bin', $dl['data']); // $dl['mimeType'] holds the content type
```

## Webhooks

The server signs each delivery with HMAC-SHA256 over the **raw request body**,
hex-encoded, in the `X-Wao-Signature: sha256=<hex>` header. Verify against the
exact bytes you received (never re-encode a parsed object).

```php
use Waotomatis\Webhook;

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_WAO_SIGNATURE'] ?? null;
$secret = getenv('WAO_WEBHOOK_SECRET');

if (!Webhook::verify($raw, $sig, $secret)) {
    http_response_code(401);
    exit;
}

$event = Webhook::constructEvent($raw, $sig, $secret); // verifies + decodes
if ($event['event'] === 'message.received') {
    $text = $event['data']['text'] ?? null;
}
```

`constructEvent()` throws `WaotomatisException` (`code: unauthorized`) on a bad
signature and (`code: validation_failed`) on an unparseable body.

## Error handling

Every failure is a subclass of `Waotomatis\Exception\WaotomatisException`,
mirroring the server's `{ error: { code, message, requestId } }` model plus the
HTTP status. Branch on `getCodeName()` for stable handling.

```php
use Waotomatis\Exception\RateLimitError;
use Waotomatis\Exception\WaotomatisException;

try {
    $wao->sessions('sess_123')->messages->send([...]);
} catch (RateLimitError $e) {
    sleep($e->getRetryAfter() ?? 1);
} catch (WaotomatisException $e) {
    error_log("{$e->getCodeName()} ({$e->getStatus()}) req={$e->getRequestId()}: {$e->getMessage()}");
}
```

| Exception              | HTTP        | Notes                                          |
| ---------------------- | ----------- | ---------------------------------------------- |
| `AuthenticationError`  | 401         | Missing / invalid API key                      |
| `PermissionError`      | 403         | Key not permitted for this action              |
| `NotFoundError`        | 404         | Resource not found / not visible               |
| `ValidationError`      | 409 / 422   | Understood but rejected (validation, bad state)|
| `RateLimitError`       | 429         | `getRetryAfter()` returns seconds              |
| `ApiError`             | 5xx         | Unexpected server failure                      |
| `TimeoutError`         | 408         | Request exceeded the configured timeout        |
| `ConnectionError`      | —           | Network failure before a response              |
| `WaotomatisException`  | other       | Base class; other 4xx surface here             |

## License

MIT © WAOtomatis. See [LICENSE](./LICENSE).

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

The `$input` array maps 1:1 to the API body, so every message type below is sent
by setting `type` and the matching key — no special builders needed.

### Reactions

React to an inbound message by its provider `wamid`. Send an empty `emoji` to
clear a reaction you previously sent.

```php
$wao->sessions('sess_123')->messages->send([
    'to'       => '628123456789',
    'type'     => 'reaction',
    'reaction' => [
        'messageId' => 'wamid.HBgL...',
        'emoji'     => '👍',
    ],
]);

// Clear it
$wao->sessions('sess_123')->messages->send([
    'to'       => '628123456789',
    'type'     => 'reaction',
    'reaction' => ['messageId' => 'wamid.HBgL...', 'emoji' => ''],
]);
```

### Location

```php
$wao->sessions('sess_123')->messages->send([
    'to'       => '628123456789',
    'type'     => 'location',
    'location' => [
        'latitude'  => -6.2,
        'longitude' => 106.816666,
        'name'      => 'Monas',           // optional
        'address'   => 'Jakarta Pusat',   // optional
    ],
]);
```

### Contacts

`contacts` is an array of WhatsApp contact-card objects. Each card requires
`name.formatted_name`; `phones`, `emails`, `org`, `urls`, `addresses`, and
`birthday` are optional and passed through as-is.

```php
$wao->sessions('sess_123')->messages->send([
    'to'       => '628123456789',
    'type'     => 'contacts',
    'contacts' => [
        [
            'name'   => [
                'formatted_name' => 'Budi Santoso',
                'first_name'     => 'Budi',
                'last_name'      => 'Santoso',
            ],
            'phones' => [
                ['phone' => '+628123456789', 'type' => 'WORK', 'wa_id' => '628123456789'],
            ],
            'emails' => [
                ['email' => 'budi@example.com', 'type' => 'WORK'],
            ],
            'org'    => ['company' => 'WAOtomatis', 'title' => 'Engineer'],
        ],
    ],
]);
```

### Carousel (template)

A carousel template: a named, language-tagged template with up to ten cards,
each carrying its own media header, body params, and buttons.

```php
$wao->sessions('sess_123')->messages->send([
    'to'       => '628123456789',
    'type'     => 'carousel',
    'carousel' => [
        'name'         => 'summer_sale',
        'languageCode' => 'en_US',
        'bodyParams'   => ['Budi'],      // optional — fills the message bubble body
        'cards'        => [
            [
                'headerImageId' => 'media_abc',          // or 'headerImageLink' => 'https://...'
                'bodyParams'    => ['Shoes', '30%'],
                'buttons'       => [
                    ['subType' => 'quick_reply', 'index' => 0, 'payload' => 'BUY_SHOES'],
                    ['subType' => 'url', 'index' => 1, 'urlParam' => 'shoes'],
                ],
            ],
            [
                'headerVideoLink' => 'https://example.com/promo.mp4',
                'bodyParams'      => ['Bags', '20%'],
                'buttons'         => [
                    ['subType' => 'quick_reply', 'index' => 0, 'payload' => 'BUY_BAGS'],
                ],
            ],
        ],
    ],
]);
```

### Interactive messages

`interactive.type` selects the variant. Alongside the existing `button` and
`list` types, the API also supports `cta_url`, `flow`, `product`, and
`product_list`.

```php
// Call-to-action URL button
$wao->sessions('sess_123')->messages->send([
    'to'          => '628123456789',
    'type'        => 'interactive',
    'interactive' => [
        'type'           => 'cta_url',
        'bodyText'       => 'Visit our store',
        'headerText'     => 'New arrivals',   // optional
        'footerText'     => 'Limited time',   // optional
        'ctaDisplayText' => 'Shop now',
        'ctaUrl'         => 'https://example.com/shop',
    ],
]);

// WhatsApp Flow
$wao->sessions('sess_123')->messages->send([
    'to'          => '628123456789',
    'type'        => 'interactive',
    'interactive' => [
        'type'     => 'flow',
        'bodyText' => 'Book an appointment',
        'flow'     => [
            'flowCta'           => 'Book now',
            'flowId'            => '1234567890',          // optional
            'flowToken'         => 'tok_abc',             // optional
            'flowAction'        => 'navigate',           // 'navigate' | 'data_exchange'
            'flowActionPayload' => ['screen' => 'WELCOME'], // optional
            'mode'              => 'published',           // 'draft' | 'published'
        ],
    ],
]);

// Single catalog product
$wao->sessions('sess_123')->messages->send([
    'to'          => '628123456789',
    'type'        => 'interactive',
    'interactive' => [
        'type'              => 'product',
        'bodyText'          => 'Check this out',   // optional
        'footerText'        => 'In stock',         // optional
        'catalogId'         => 'cat_123',
        'productRetailerId' => 'SKU-1',
    ],
]);

// Multi-section product list
$wao->sessions('sess_123')->messages->send([
    'to'          => '628123456789',
    'type'        => 'interactive',
    'interactive' => [
        'type'            => 'product_list',
        'headerText'      => 'Our catalog',
        'bodyText'        => 'Browse our products',
        'footerText'      => 'Free shipping',      // optional
        'catalogId'       => 'cat_123',
        'productSections' => [
            [
                'title'        => 'Shoes',          // optional
                'productItems' => [
                    ['productRetailerId' => 'SKU-1'],
                    ['productRetailerId' => 'SKU-2'],
                ],
            ],
        ],
    ],
]);
```

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

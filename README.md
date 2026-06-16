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

$msg = $wao->sessions('sess_123')->messages->sendText(
    '628123456789',
    'Halo dari WAOtomatis 👋',
);

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

There is one method per message type — eight in total. Across all of them `$to`
is the first argument, required fields are required arguments, optionals are
nullable (and dropped from the wire body when `null`), and the last argument is
always an optional `$idempotencyKey`. Each posts to its own endpoint and returns
`['id' => ..., 'eventId' => ..., 'providerMessageId' => ..., 'status' => ...]`.

```php
$messages = $wao->sessions('sess_123')->messages;
```

### Text

`sendText(to, text, previewUrl?, replyTo?, idempotencyKey?)`

```php
// Plain text, with a link preview
$messages->sendText('628123456789', 'Cek https://waotomatis.com', previewUrl: true);
```

### Media

`sendMedia(to, type, mediaId?, link?, caption?, fileName?, voice?, replyTo?, idempotencyKey?)`

One method covers `image`, `video`, `audio`, `document`, and `sticker` — the
media kind is the `$type` argument. Provide exactly one of `$mediaId` (from an
upload) or `$link` (a public URL). `$caption` applies to image/video/document,
`$fileName` to document, and `$voice` marks audio as a voice note.

```php
// Image by uploaded media id (with caption + idempotency key)
$messages->sendMedia('628123456789', 'image', mediaId: 'media_abc', caption: 'Invoice', idempotencyKey: 'inv-4711');

// Document by public link
$messages->sendMedia('628123456789', 'document', link: 'https://example.com/invoice.pdf', fileName: 'invoice.pdf');

// Audio as a voice note
$messages->sendMedia('628123456789', 'audio', mediaId: 'media_xyz', voice: true);
```

### Template

`sendTemplate(to, name, languageCode, components?, replyTo?, idempotencyKey?)`

`$components` is Meta's component array (header/body/buttons params), passed
through as-is.

```php
$messages->sendTemplate('628123456789', 'order_update', 'en_US', [
    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'A1234']]],
]);
```

### Interactive

`sendInteractive(to, type, bodyText?, headerText?, footerText?, buttons?, listButton?, sections?, ctaDisplayText?, ctaUrl?, flow?, catalogId?, productRetailerId?, productSections?, replyTo?, idempotencyKey?)`

One method covers all interactive variants — the variant is the `$type` argument
(`button`, `list`, `cta_url`, `flow`, `product`, or `product_list`). Supply only
the fields that variant needs.

```php
// Reply buttons (max 3)
$messages->sendInteractive('628123456789', 'button', bodyText: 'Konfirmasi pesananmu?', buttons: [
    ['id' => 'yes', 'title' => 'Ya'],
    ['id' => 'no',  'title' => 'Tidak'],
]);

// List
$messages->sendInteractive('628123456789', 'list', bodyText: 'Pilih menu', listButton: 'Lihat', sections: [
    ['title' => 'Makanan', 'rows' => [
        ['id' => 'nasi', 'title' => 'Nasi goreng', 'description' => 'Pedas'],
    ]],
]);

// Call-to-action URL button
$messages->sendInteractive('628123456789', 'cta_url',
    bodyText: 'Visit our store',
    ctaDisplayText: 'Shop now',
    ctaUrl: 'https://example.com/shop',
);

// WhatsApp Flow
$messages->sendInteractive('628123456789', 'flow', bodyText: 'Book an appointment', flow: [
    'flowCta'           => 'Book now',
    'flowId'            => '1234567890',             // optional
    'flowAction'        => 'navigate',              // 'navigate' | 'data_exchange'
    'flowActionPayload' => ['screen' => 'WELCOME'], // optional
    'mode'              => 'published',             // 'draft' | 'published'
]);

// Single catalog product
$messages->sendInteractive('628123456789', 'product',
    catalogId: 'cat_123',
    productRetailerId: 'SKU-1',
);

// Multi-section product list
$messages->sendInteractive('628123456789', 'product_list',
    bodyText: 'Browse our products',
    catalogId: 'cat_123',
    productSections: [
        ['title' => 'Shoes', 'productItems' => [
            ['productRetailerId' => 'SKU-1'],
            ['productRetailerId' => 'SKU-2'],
        ]],
    ],
);
```

### Reaction

`sendReaction(to, messageId, emoji, idempotencyKey?)`

React to a message by its provider `wamid`. Pass an empty `$emoji` to clear a
reaction you previously sent.

```php
$messages->sendReaction('628123456789', 'wamid.HBgL...', '👍');

// Clear it
$messages->sendReaction('628123456789', 'wamid.HBgL...', '');
```

### Location

`sendLocation(to, latitude, longitude, name?, address?, replyTo?, idempotencyKey?)`

```php
$messages->sendLocation('628123456789', -6.2, 106.816666, name: 'Monas', address: 'Jakarta Pusat');
```

### Contacts

`sendContacts(to, contacts, replyTo?, idempotencyKey?)`

`$contacts` is an array of WhatsApp contact-card objects. Each card requires
`name.formatted_name`; `phones`, `emails`, `org`, `urls`, `addresses`, and
`birthday` are optional and passed through as-is.

```php
$messages->sendContacts('628123456789', [
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
]);
```

### Carousel

`sendCarousel(to, name, languageCode, cards, bodyParams?, replyTo?, idempotencyKey?)`

A carousel template: a named, language-tagged template with up to ten cards,
each carrying its own media header, body params, and buttons.

```php
$messages->sendCarousel('628123456789', 'summer_sale', 'en_US', [
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
], bodyParams: ['Budi']); // bodyParams fills the message bubble body
```

### Idempotency

Every send method takes an optional `$idempotencyKey` as its last argument. Pass
a key so a retried send returns the original result instead of duplicating:

```php
$messages->sendText('628123456789', 'Halo', idempotencyKey: 'order-4711');
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

echo $m['mediaId']; // pass this to messages->sendMedia($to, 'image', mediaId: $m['mediaId'])

// Download inbound media bytes
$dl = $session->media->download('media_abc');
file_put_contents('out.bin', $dl['data']); // $dl['mimeType'] holds the content type
```

## Templates

Manage WhatsApp message templates for a session (proxies Meta's WABA template
API). Reach them as `$wao->sessions('sess_123')->templates`.

```php
$templates = $wao->sessions('sess_123')->templates;

// List (all filters optional: limit 1–100, after cursor, name, language, status, category)
$page = $templates->list(['category' => 'MARKETING', 'limit' => 20]);
foreach ($page['data'] as $tpl) {
    echo "{$tpl['name']} [{$tpl['language']}] — {$tpl['status']}\n";
}
$next = $page['paging']['cursors']['after'] ?? null; // pass back as ['after' => $next]

// Get every language version registered under a name
$one = $templates->get('order_update'); // ['data' => [...], 'paging' => ...]

// Create — submit for Meta approval. `components` is Meta's component array.
$res = $templates->create('order_update', 'en_US', 'UTILITY', [
    ['type' => 'BODY', 'text' => 'Your order {{1}} has shipped.'],
    [
        'type'    => 'BUTTONS',
        'buttons' => [
            ['type' => 'URL', 'text' => 'Track', 'url' => 'https://example.com/track/{{1}}'],
        ],
    ],
], allowCategoryChange: true);
echo $res['id'] . ' ' . $res['status']; // e.g. "123456 PENDING"

// Delete by name (removes all language versions)
$templates->delete('order_update'); // ['success' => true]
```

A template object has the shape
`{ id, name, language, status, category, components, quality_score? }`. The
`components` array follows Meta's template-component schema
(HEADER/BODY/FOOTER/BUTTONS) and is passed through as-is.

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
    $wao->sessions('sess_123')->messages->sendText('628123456789', 'Halo');
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

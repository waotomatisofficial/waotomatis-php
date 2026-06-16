<?php

declare(strict_types=1);

namespace Waotomatis\Resources;

use Waotomatis\Client;

/**
 * Send messages and update read state for one session.
 *
 * Each message type maps to its own endpoint under
 * `POST /v1/sessions/{id}/messages/{kind}`. There is one method per endpoint:
 * `sendText`, `sendMedia`, `sendTemplate`, `sendInteractive`, `sendReaction`,
 * `sendLocation`, `sendContacts`, and `sendCarousel`. Across all of them `$to`
 * is the first argument, required fields are required arguments, optionals are
 * nullable (and dropped from the wire body when null), and the last argument is
 * always an optional `$idempotencyKey`.
 *
 * The wire keys are camelCase exactly as documented per method. Pass an
 * `$idempotencyKey` to dedupe retries — the same key returns the original
 * result (with `idempotent: true`). Every send returns
 * `{ id, eventId, providerMessageId, status }`.
 */
final class MessageResource
{
    private Client $client;
    private string $sessionId;

    public function __construct(Client $client, string $sessionId)
    {
        $this->client = $client;
        $this->sessionId = $sessionId;
    }

    /**
     * Send a plain text message.
     *
     * `POST /v1/sessions/{id}/messages/text`
     * Body: `{ to, text, previewUrl?, replyTo? }`.
     *
     * @param string      $to             Recipient in E.164 (e.g. "6281234567890").
     * @param string      $text           Message body.
     * @param bool|null   $previewUrl     Render a link preview for URLs in the text.
     * @param string|null $replyTo        Provider `wamid` to reply to (quoted reply).
     * @param string|null $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendText(
        string $to,
        string $text,
        ?bool $previewUrl = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->post('text', $this->prune([
            'to' => $to,
            'text' => $text,
            'previewUrl' => $previewUrl,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * Send a media message (image, video, audio, document, or sticker). Provide
     * exactly one of `$mediaId` (from an upload) or `$link` (a public URL); the
     * API rejects neither/both. `$caption` applies to image/video/document,
     * `$fileName` to document, and `$voice` marks audio as a voice note.
     *
     * `POST /v1/sessions/{id}/messages/media`
     * Body: `{ to, type, mediaId? | link?, caption?, fileName?, voice?, replyTo? }`.
     *
     * @param string      $to             Recipient in E.164.
     * @param string      $type           One of "image" | "video" | "audio" | "document" | "sticker".
     * @param string|null $mediaId        Uploaded media id (mutually exclusive with $link).
     * @param string|null $link           Public media URL (mutually exclusive with $mediaId).
     * @param string|null $caption        Optional caption (image/video/document).
     * @param string|null $fileName       File name shown to the recipient (document).
     * @param bool|null   $voice          Send audio as a voice note (push-to-talk bubble).
     * @param string|null $replyTo        Provider `wamid` to reply to.
     * @param string|null $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendMedia(
        string $to,
        string $type,
        ?string $mediaId = null,
        ?string $link = null,
        ?string $caption = null,
        ?string $fileName = null,
        ?bool $voice = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->post('media', $this->prune([
            'to' => $to,
            'type' => $type,
            'mediaId' => $mediaId,
            'link' => $link,
            'caption' => $caption,
            'fileName' => $fileName,
            'voice' => $voice,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * Send a pre-approved message template.
     *
     * `POST /v1/sessions/{id}/messages/template`
     * Body: `{ to, name, languageCode, components?, replyTo? }`.
     *
     * @param string                $to             Recipient in E.164.
     * @param string                $name           Approved template name.
     * @param string                $languageCode   Template language (e.g. "en_US", "id").
     * @param array<int,mixed>|null $components     Meta component objects (header/body/buttons params).
     * @param string|null           $replyTo        Provider `wamid` to reply to.
     * @param string|null           $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendTemplate(
        string $to,
        string $name,
        string $languageCode,
        ?array $components = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->post('template', $this->prune([
            'to' => $to,
            'name' => $name,
            'languageCode' => $languageCode,
            'components' => $components,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * Send an interactive message. `$type` selects the variant and the remaining
     * fields carry its payload — supply only the ones that variant needs:
     *
     *   - button       → bodyText, buttons (1–3)
     *   - list         → bodyText, sections, listButton
     *   - cta_url      → bodyText, ctaDisplayText, ctaUrl
     *   - flow         → bodyText, flow
     *   - product      → catalogId, productRetailerId
     *   - product_list → catalogId, productSections
     *
     * `POST /v1/sessions/{id}/messages/interactive`
     * Body: `{ to, type, bodyText?, headerText?, footerText?, buttons?, listButton?,
     *          sections?, ctaDisplayText?, ctaUrl?, flow?, catalogId?,
     *          productRetailerId?, productSections?, replyTo? }`.
     *
     * @param string                                          $to                Recipient in E.164.
     * @param string                                          $type              One of "button" | "list" | "cta_url" | "flow" | "product" | "product_list".
     * @param string|null                                     $bodyText          Main message text (button/list/cta_url/flow).
     * @param string|null                                     $headerText        Optional header.
     * @param string|null                                     $footerText        Optional footer.
     * @param array<int,array{id: string, title: string}>|null $buttons          Reply buttons, 1–3 (type "button").
     * @param string|null                                     $listButton        Label for the list-open button (type "list").
     * @param array<int,mixed>|null                           $sections          List sections, each `{title?, rows:[{id,title,description?}]}` (type "list").
     * @param string|null                                     $ctaDisplayText    Visible button label (type "cta_url").
     * @param string|null                                     $ctaUrl            Destination URL (type "cta_url").
     * @param array<string,mixed>|null                        $flow              Flow object `{flowCta, flowId?, flowToken?, flowAction?, flowActionPayload?, mode?}` (type "flow").
     * @param string|null                                     $catalogId         Catalog id (type "product" / "product_list").
     * @param string|null                                     $productRetailerId Product retailer (SKU) id (type "product").
     * @param array<int,mixed>|null                           $productSections   Sections, each `{title?, productItems:[{productRetailerId}]}` (type "product_list").
     * @param string|null                                     $replyTo           Provider `wamid` to reply to.
     * @param string|null                                     $idempotencyKey    Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendInteractive(
        string $to,
        string $type,
        ?string $bodyText = null,
        ?string $headerText = null,
        ?string $footerText = null,
        ?array $buttons = null,
        ?string $listButton = null,
        ?array $sections = null,
        ?string $ctaDisplayText = null,
        ?string $ctaUrl = null,
        ?array $flow = null,
        ?string $catalogId = null,
        ?string $productRetailerId = null,
        ?array $productSections = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->post('interactive', $this->prune([
            'to' => $to,
            'type' => $type,
            'bodyText' => $bodyText,
            'headerText' => $headerText,
            'footerText' => $footerText,
            'buttons' => $buttons,
            'listButton' => $listButton,
            'sections' => $sections,
            'ctaDisplayText' => $ctaDisplayText,
            'ctaUrl' => $ctaUrl,
            'flow' => $flow,
            'catalogId' => $catalogId,
            'productRetailerId' => $productRetailerId,
            'productSections' => $productSections,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * React to a message with an emoji. Pass an empty `$emoji` to clear a
     * reaction you previously sent. Reactions cannot quote a reply.
     *
     * `POST /v1/sessions/{id}/messages/reaction`
     * Body: `{ to, messageId, emoji }`.
     *
     * @param string      $to             Recipient in E.164.
     * @param string      $messageId      Provider `wamid` of the message to react to.
     * @param string      $emoji          Emoji to apply ("" removes the reaction).
     * @param string|null $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendReaction(
        string $to,
        string $messageId,
        string $emoji,
        ?string $idempotencyKey = null
    ): array {
        return $this->post('reaction', [
            'to' => $to,
            'messageId' => $messageId,
            'emoji' => $emoji,
        ], $idempotencyKey);
    }

    /**
     * Share a location pin.
     *
     * `POST /v1/sessions/{id}/messages/location`
     * Body: `{ to, latitude, longitude, name?, address?, replyTo? }`.
     *
     * @param string      $to             Recipient in E.164.
     * @param float       $latitude       Latitude in decimal degrees.
     * @param float       $longitude      Longitude in decimal degrees.
     * @param string|null $name           Optional place name.
     * @param string|null $address        Optional street address.
     * @param string|null $replyTo        Provider `wamid` to reply to.
     * @param string|null $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendLocation(
        string $to,
        float $latitude,
        float $longitude,
        ?string $name = null,
        ?string $address = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->post('location', $this->prune([
            'to' => $to,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'name' => $name,
            'address' => $address,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * Share one or more contact cards.
     *
     * `POST /v1/sessions/{id}/messages/contacts`
     * Body: `{ to, contacts[], replyTo? }`.
     *
     * @param string           $to             Recipient in E.164.
     * @param array<int,mixed> $contacts       WhatsApp contact-card objects (each requires `name.formatted_name`).
     * @param string|null      $replyTo        Provider `wamid` to reply to.
     * @param string|null      $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendContacts(
        string $to,
        array $contacts,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->post('contacts', $this->prune([
            'to' => $to,
            'contacts' => $contacts,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * Send a carousel template (a named, language-tagged template with cards).
     *
     * `POST /v1/sessions/{id}/messages/carousel`
     * Body: `{ to, name, languageCode, bodyParams?, cards[], replyTo? }`.
     *
     * @param string                 $to             Recipient in E.164.
     * @param string                 $name           Carousel template name.
     * @param string                 $languageCode   Template language (e.g. "en_US", "id").
     * @param array<int,mixed>       $cards          Cards, each with its own header/bodyParams/buttons.
     * @param array<int,string>|null $bodyParams     Params for the message bubble body.
     * @param string|null            $replyTo        Provider `wamid` to reply to.
     * @param string|null            $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendCarousel(
        string $to,
        string $name,
        string $languageCode,
        array $cards,
        ?array $bodyParams = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->post('carousel', $this->prune([
            'to' => $to,
            'name' => $name,
            'languageCode' => $languageCode,
            'bodyParams' => $bodyParams,
            'cards' => $cards,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * Mark an inbound message (by its provider `wamid`) as read.
     *
     * @return array<mixed>
     */
    public function markRead(string $providerMessageId): array
    {
        // wamids are `wamid.` + base64 that can contain `/`, `+`, `=`; encode so an
        // unescaped `/` doesn't split the path and miss the route (→ 404).
        return $this->client->request(
            'POST',
            '/v1/sessions/' . rawurlencode($this->sessionId)
                . '/messages/' . rawurlencode($providerMessageId) . '/read'
        );
    }

    /**
     * POST a built body to one per-type send endpoint (`.../messages/{kind}`),
     * forwarding the optional idempotency key as the `Idempotency-Key` header.
     *
     * @param array<string,mixed> $body
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    private function post(string $kind, array $body, ?string $idempotencyKey): array
    {
        return $this->client->request(
            'POST',
            '/v1/sessions/' . rawurlencode($this->sessionId) . '/messages/' . $kind,
            [],
            $body,
            [],
            $idempotencyKey
        );
    }

    /**
     * Drop `null` entries so optional fields are omitted from the wire body
     * (false/0/"" are intentionally kept — e.g. an empty reaction emoji).
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    private function prune(array $input): array
    {
        return array_filter($input, static fn ($v): bool => $v !== null);
    }
}

<?php

declare(strict_types=1);

namespace Waotomatis\Resources;

use Waotomatis\Client;

/** Send messages and update read state for one session. */
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
     * Send a message. The `$input` array maps directly to the API's
     * `SendMessageInput` (`to`, `type`, plus the per-type fields):
     *
     *   - text:     ['to' => ..., 'type' => 'text', 'text' => 'Hi', 'previewUrl' => true]
     *   - image:    ['to' => ..., 'type' => 'image', 'mediaId' => 'm_1', 'caption' => 'Hi']
     *               (or 'link' => 'https://...' instead of 'mediaId')
     *   - video / audio / document: same media shape (audio adds 'voice', document
     *     adds 'fileName')
     *   - sticker / template: per the API contract
     *   - reaction: ['type' => 'reaction', 'reaction' => ['messageId' => ..., 'emoji' => '👍']]
     *               (emoji '' clears a previously-sent reaction)
     *   - location: ['type' => 'location', 'location' => ['latitude' => ..., 'longitude' => ...,
     *               'name' => ?, 'address' => ?]]
     *   - contacts: ['type' => 'contacts', 'contacts' => [[...]]] — an array of WhatsApp
     *               contact-card objects (each requires name.formatted_name)
     *   - carousel: ['type' => 'carousel', 'carousel' => ['name' => ..., 'languageCode' => ...,
     *               'cards' => [...]]] — a carousel template
     *   - interactive: ['type' => 'interactive', 'interactive' => ['type' => ..., ...]] where
     *               interactive.type is one of button | list | cta_url | flow | product |
     *               product_list, per the API contract
     *
     * Pass `$idempotencyKey` (or include it as `idempotencyKey` in `$input`) to
     * dedupe retries — the same key returns the original result.
     *
     * @param array<string,mixed> $input
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function send(array $input, ?string $idempotencyKey = null): array
    {
        // An explicit per-call key wins; otherwise use the one on the payload.
        $key = $idempotencyKey ?? ($input['idempotencyKey'] ?? null);
        unset($input['idempotencyKey']);

        return $this->client->request(
            'POST',
            '/v1/sessions/' . rawurlencode($this->sessionId) . '/messages',
            [],
            $input,
            [],
            $key !== null ? (string) $key : null
        );
    }

    // ── Typed convenience helpers ────────────────────────────────────────────
    //
    // One method per message type. Each builds the exact `send()` body for that
    // type and delegates to send(), so they share its idempotency handling and
    // return shape. `to` is always first; required spec fields are required PHP
    // params; optionals are nullable and dropped when null. They are pure sugar
    // over send([...]) — nothing here that send() can't already express.

    /**
     * Send a plain text message.
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
        return $this->send($this->prune([
            'to' => $to,
            'type' => 'text',
            'text' => $text,
            'previewUrl' => $previewUrl,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * Send an image. Provide exactly one of `$mediaId` (from an upload) or `$link`
     * (a public URL).
     *
     * @param string      $to             Recipient in E.164.
     * @param string|null $mediaId        Uploaded media id (mutually exclusive with $link).
     * @param string|null $link           Public media URL (mutually exclusive with $mediaId).
     * @param string|null $caption        Optional caption.
     * @param string|null $replyTo        Provider `wamid` to reply to.
     * @param string|null $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendImage(
        string $to,
        ?string $mediaId = null,
        ?string $link = null,
        ?string $caption = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->sendMedia('image', $to, $mediaId, $link, $caption, null, null, $replyTo, $idempotencyKey);
    }

    /**
     * Send a video. Provide exactly one of `$mediaId` or `$link`.
     *
     * @param string      $to             Recipient in E.164.
     * @param string|null $mediaId        Uploaded media id (mutually exclusive with $link).
     * @param string|null $link           Public media URL (mutually exclusive with $mediaId).
     * @param string|null $caption        Optional caption.
     * @param string|null $replyTo        Provider `wamid` to reply to.
     * @param string|null $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendVideo(
        string $to,
        ?string $mediaId = null,
        ?string $link = null,
        ?string $caption = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->sendMedia('video', $to, $mediaId, $link, $caption, null, null, $replyTo, $idempotencyKey);
    }

    /**
     * Send an audio clip. Provide exactly one of `$mediaId` or `$link`. Set
     * `$voice` to send it as a voice note (push-to-talk bubble).
     *
     * @param string      $to             Recipient in E.164.
     * @param string|null $mediaId        Uploaded media id (mutually exclusive with $link).
     * @param string|null $link           Public media URL (mutually exclusive with $mediaId).
     * @param bool|null   $voice          Send as a voice note.
     * @param string|null $replyTo        Provider `wamid` to reply to.
     * @param string|null $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendAudio(
        string $to,
        ?string $mediaId = null,
        ?string $link = null,
        ?bool $voice = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->sendMedia('audio', $to, $mediaId, $link, null, null, $voice, $replyTo, $idempotencyKey);
    }

    /**
     * Send a document. Provide exactly one of `$mediaId` or `$link`.
     *
     * @param string      $to             Recipient in E.164.
     * @param string|null $mediaId        Uploaded media id (mutually exclusive with $link).
     * @param string|null $link           Public media URL (mutually exclusive with $mediaId).
     * @param string|null $caption        Optional caption.
     * @param string|null $fileName       File name shown to the recipient.
     * @param string|null $replyTo        Provider `wamid` to reply to.
     * @param string|null $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendDocument(
        string $to,
        ?string $mediaId = null,
        ?string $link = null,
        ?string $caption = null,
        ?string $fileName = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->sendMedia('document', $to, $mediaId, $link, $caption, $fileName, null, $replyTo, $idempotencyKey);
    }

    /**
     * Send a sticker. Provide exactly one of `$mediaId` or `$link`.
     *
     * @param string      $to             Recipient in E.164.
     * @param string|null $mediaId        Uploaded media id (mutually exclusive with $link).
     * @param string|null $link           Public media URL (mutually exclusive with $mediaId).
     * @param string|null $replyTo        Provider `wamid` to reply to.
     * @param string|null $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendSticker(
        string $to,
        ?string $mediaId = null,
        ?string $link = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->sendMedia('sticker', $to, $mediaId, $link, null, null, null, $replyTo, $idempotencyKey);
    }

    /**
     * Send a pre-approved message template.
     *
     * @param string                    $to             Recipient in E.164.
     * @param string                    $name           Template name.
     * @param string                    $languageCode   Template language (e.g. "en_US", "id").
     * @param array<int,mixed>|null     $components     Meta component objects (header/body/buttons params).
     * @param string|null               $replyTo        Provider `wamid` to reply to.
     * @param string|null               $idempotencyKey Dedupe key for safe retries.
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
        $template = $this->prune([
            'name' => $name,
            'languageCode' => $languageCode,
            'components' => $components,
        ]);

        return $this->send($this->prune([
            'to' => $to,
            'type' => 'template',
            'template' => $template,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * Send interactive reply buttons (max 3).
     *
     * @param string                                  $to             Recipient in E.164.
     * @param string                                  $bodyText       Main message text.
     * @param array<int,array{id: string, title: string}> $buttons    Reply buttons (1–3).
     * @param string|null                             $headerText     Optional header.
     * @param string|null                             $footerText     Optional footer.
     * @param string|null                             $replyTo        Provider `wamid` to reply to.
     * @param string|null                             $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendButtons(
        string $to,
        string $bodyText,
        array $buttons,
        ?string $headerText = null,
        ?string $footerText = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->sendInteractive($to, $this->prune([
            'type' => 'button',
            'bodyText' => $bodyText,
            'headerText' => $headerText,
            'footerText' => $footerText,
            'buttons' => $buttons,
        ]), $replyTo, $idempotencyKey);
    }

    /**
     * Send an interactive list message.
     *
     * @param string                $to             Recipient in E.164.
     * @param string                $bodyText       Main message text.
     * @param array<int,mixed>      $sections       Sections, each `{title?, rows:[{id,title,description?}]}`.
     * @param string|null           $listButton     Label for the list-open button.
     * @param string|null           $headerText     Optional header.
     * @param string|null           $footerText     Optional footer.
     * @param string|null           $replyTo        Provider `wamid` to reply to.
     * @param string|null           $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendList(
        string $to,
        string $bodyText,
        array $sections,
        ?string $listButton = null,
        ?string $headerText = null,
        ?string $footerText = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->sendInteractive($to, $this->prune([
            'type' => 'list',
            'bodyText' => $bodyText,
            'headerText' => $headerText,
            'footerText' => $footerText,
            'listButton' => $listButton,
            'sections' => $sections,
        ]), $replyTo, $idempotencyKey);
    }

    /**
     * Send an interactive call-to-action URL button.
     *
     * @param string      $to             Recipient in E.164.
     * @param string      $bodyText       Main message text.
     * @param string      $ctaDisplayText Visible button label.
     * @param string      $ctaUrl         Destination URL.
     * @param string|null $headerText     Optional header.
     * @param string|null $footerText     Optional footer.
     * @param string|null $replyTo        Provider `wamid` to reply to.
     * @param string|null $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendCtaUrl(
        string $to,
        string $bodyText,
        string $ctaDisplayText,
        string $ctaUrl,
        ?string $headerText = null,
        ?string $footerText = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->sendInteractive($to, $this->prune([
            'type' => 'cta_url',
            'bodyText' => $bodyText,
            'headerText' => $headerText,
            'footerText' => $footerText,
            'ctaDisplayText' => $ctaDisplayText,
            'ctaUrl' => $ctaUrl,
        ]), $replyTo, $idempotencyKey);
    }

    /**
     * Send an interactive WhatsApp Flow.
     *
     * @param string                    $to             Recipient in E.164.
     * @param string                    $bodyText       Main message text.
     * @param string                    $flowCta        Label for the flow-open button.
     * @param string|null               $flowId         Published flow id.
     * @param string|null               $flowToken      Opaque token echoed back on completion.
     * @param string|null               $flowAction     `navigate` | `data_exchange`.
     * @param array<string,mixed>|null  $flowActionPayload Initial screen/data payload.
     * @param string|null               $mode           `draft` | `published`.
     * @param string|null               $replyTo        Provider `wamid` to reply to.
     * @param string|null               $idempotencyKey Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendFlow(
        string $to,
        string $bodyText,
        string $flowCta,
        ?string $flowId = null,
        ?string $flowToken = null,
        ?string $flowAction = null,
        ?array $flowActionPayload = null,
        ?string $mode = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        $flow = $this->prune([
            'flowCta' => $flowCta,
            'flowId' => $flowId,
            'flowToken' => $flowToken,
            'flowAction' => $flowAction,
            'flowActionPayload' => $flowActionPayload,
            'mode' => $mode,
        ]);

        return $this->sendInteractive($to, $this->prune([
            'type' => 'flow',
            'bodyText' => $bodyText,
            'flow' => $flow,
        ]), $replyTo, $idempotencyKey);
    }

    /**
     * Send a single catalog product (Single Product Message).
     *
     * @param string      $to                Recipient in E.164.
     * @param string      $catalogId         Catalog id.
     * @param string      $productRetailerId Product retailer (SKU) id.
     * @param string|null $bodyText          Optional body text.
     * @param string|null $headerText        Optional header.
     * @param string|null $footerText        Optional footer.
     * @param string|null $replyTo           Provider `wamid` to reply to.
     * @param string|null $idempotencyKey    Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendProduct(
        string $to,
        string $catalogId,
        string $productRetailerId,
        ?string $bodyText = null,
        ?string $headerText = null,
        ?string $footerText = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->sendInteractive($to, $this->prune([
            'type' => 'product',
            'bodyText' => $bodyText,
            'headerText' => $headerText,
            'footerText' => $footerText,
            'catalogId' => $catalogId,
            'productRetailerId' => $productRetailerId,
        ]), $replyTo, $idempotencyKey);
    }

    /**
     * Send a multi-section catalog product list (Multi Product Message).
     *
     * @param string           $to              Recipient in E.164.
     * @param string           $catalogId       Catalog id.
     * @param array<int,mixed> $productSections Sections, each `{title?, productItems:[{productRetailerId}]}`.
     * @param string|null      $bodyText        Optional body text.
     * @param string|null      $headerText      Optional header.
     * @param string|null      $footerText      Optional footer.
     * @param string|null      $replyTo         Provider `wamid` to reply to.
     * @param string|null      $idempotencyKey  Dedupe key for safe retries.
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    public function sendProductList(
        string $to,
        string $catalogId,
        array $productSections,
        ?string $bodyText = null,
        ?string $headerText = null,
        ?string $footerText = null,
        ?string $replyTo = null,
        ?string $idempotencyKey = null
    ): array {
        return $this->sendInteractive($to, $this->prune([
            'type' => 'product_list',
            'bodyText' => $bodyText,
            'headerText' => $headerText,
            'footerText' => $footerText,
            'catalogId' => $catalogId,
            'productSections' => $productSections,
        ]), $replyTo, $idempotencyKey);
    }

    /**
     * React to a message with an emoji. Pass an empty `$emoji` to clear a
     * reaction you previously sent.
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
        return $this->send([
            'to' => $to,
            'type' => 'reaction',
            'reaction' => [
                'messageId' => $messageId,
                'emoji' => $emoji,
            ],
        ], $idempotencyKey);
    }

    /**
     * Share a location pin.
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
        $location = $this->prune([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'name' => $name,
            'address' => $address,
        ]);

        return $this->send($this->prune([
            'to' => $to,
            'type' => 'location',
            'location' => $location,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * Share one or more contact cards.
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
        return $this->send($this->prune([
            'to' => $to,
            'type' => 'contacts',
            'contacts' => $contacts,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * Send a carousel template (a named, language-tagged template with cards).
     *
     * @param string           $to             Recipient in E.164.
     * @param string           $name           Carousel template name.
     * @param string           $languageCode   Template language (e.g. "en_US", "id").
     * @param array<int,mixed> $cards          Cards, each with its own header/bodyParams/buttons.
     * @param array<int,string>|null $bodyParams Params for the message bubble body.
     * @param string|null      $replyTo        Provider `wamid` to reply to.
     * @param string|null      $idempotencyKey Dedupe key for safe retries.
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
        $carousel = $this->prune([
            'name' => $name,
            'languageCode' => $languageCode,
            'bodyParams' => $bodyParams,
            'cards' => $cards,
        ]);

        return $this->send($this->prune([
            'to' => $to,
            'type' => 'carousel',
            'carousel' => $carousel,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
    }

    /**
     * Shared media builder for image/video/audio/document/sticker. Exactly one of
     * `$mediaId` or `$link` should be set (the API rejects neither/both).
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    private function sendMedia(
        string $type,
        string $to,
        ?string $mediaId,
        ?string $link,
        ?string $caption,
        ?string $fileName,
        ?bool $voice,
        ?string $replyTo,
        ?string $idempotencyKey
    ): array {
        return $this->send($this->prune([
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
     * Shared builder for the `interactive` family — wraps the already-pruned
     * `interactive` object in the top-level body and delegates to send().
     *
     * @param array<string,mixed> $interactive
     *
     * @return array{id: string, eventId: string, status: string, providerMessageId?: string|null, idempotent?: bool}
     */
    private function sendInteractive(
        string $to,
        array $interactive,
        ?string $replyTo,
        ?string $idempotencyKey
    ): array {
        return $this->send($this->prune([
            'to' => $to,
            'type' => 'interactive',
            'interactive' => $interactive,
            'replyTo' => $replyTo,
        ]), $idempotencyKey);
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
}

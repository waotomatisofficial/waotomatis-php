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

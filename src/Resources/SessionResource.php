<?php

declare(strict_types=1);

namespace Waotomatis\Resources;

use Waotomatis\Client;

/**
 * Everything you can do scoped to one session. Mirrors the REST surface 1:1.
 *
 *   $session = $wao->sessions('sess_123');
 *   $session->messages->sendText('628123456789', 'Halo');
 *   $session->media->uploadFromUrl('https://...');
 *   $session->templates->list();
 */
final class SessionResource
{
    public string $id;

    public MessageResource $messages;
    public MediaResource $media;
    public TemplateResource $templates;

    private Client $client;

    public function __construct(Client $client, string $id)
    {
        $this->client = $client;
        $this->id = $id;
        $this->messages = new MessageResource($client, $id);
        $this->media = new MediaResource($client, $id);
        $this->templates = new TemplateResource($client, $id);
    }

    /**
     * Fetch this session's current state.
     *
     * @return array<mixed>
     */
    public function get(): array
    {
        return $this->client->request('GET', '/v1/sessions/' . rawurlencode($this->id));
    }

    /**
     * Disconnect this session and drop its stored token.
     *
     * @return array<mixed>
     */
    public function delete(): array
    {
        return $this->client->request('DELETE', '/v1/sessions/' . rawurlencode($this->id));
    }
}

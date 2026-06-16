<?php

declare(strict_types=1);

namespace Waotomatis\Resources;

use Waotomatis\Client;

/**
 * The `sessions` collection resource — `$wao->sessions->list()` / `->get($id)` /
 * `->delete($id)`. To scope into a single session, call the like-named method on
 * the client: `$wao->sessions('sess_123')` (see {@see Client::sessions()}).
 */
final class Sessions
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * List sessions for the authenticated key.
     *
     * @return array{data: array<int,array<mixed>>, hasMore: bool, cursor?: string|null}
     */
    public function list(): array
    {
        return $this->client->request('GET', '/v1/sessions');
    }

    /**
     * Get a single session by id.
     *
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/v1/sessions/' . rawurlencode($id));
    }

    /**
     * Disconnect a session and drop its stored token.
     *
     * @return array<mixed>
     */
    public function delete(string $id): array
    {
        return $this->client->request('DELETE', '/v1/sessions/' . rawurlencode($id));
    }
}

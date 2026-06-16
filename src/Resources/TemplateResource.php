<?php

declare(strict_types=1);

namespace Waotomatis\Resources;

use Waotomatis\Client;

/**
 * Manage WhatsApp message templates for one session (proxies Meta's WABA
 * template API). Reached as `$wao->sessions('sess_123')->templates`.
 *
 *   $session = $wao->sessions('sess_123');
 *   $session->templates->list(['category' => 'MARKETING']);
 *   $session->templates->get('order_update');
 *   $session->templates->create('order_update', 'en_US', 'UTILITY', [...]);
 *   $session->templates->delete('order_update');
 *
 * A `template` object has the shape
 * `{ id, name, language, status, category, components, quality_score? }`.
 * `components` is Meta's loose template-component array (HEADER/BODY/FOOTER/
 * BUTTONS objects) and is passed through as-is — it is not modeled here.
 */
final class TemplateResource
{
    private Client $client;
    private string $sessionId;

    public function __construct(Client $client, string $sessionId)
    {
        $this->client = $client;
        $this->sessionId = $sessionId;
    }

    /**
     * List message templates. All filters are optional; `null` values are
     * dropped so they never hit the wire.
     *
     * @param array{
     *     limit?: int,
     *     after?: string,
     *     name?: string,
     *     language?: string,
     *     status?: string,
     *     category?: string
     * } $query Optional filters: `limit` (1–100), `after` (Meta cursor),
     *         `name`, `language`, `status`, `category`.
     *
     * @return array{data: array<int,array<string,mixed>>, paging?: array<string,mixed>}
     */
    public function list(array $query = []): array
    {
        return $this->client->request(
            'GET',
            '/v1/sessions/' . rawurlencode($this->sessionId) . '/templates',
            $this->prune($query)
        );
    }

    /**
     * Get a template by name. Meta returns every language version registered
     * under that name, so the result is still a `{ data, paging? }` list.
     *
     * @param string $name Template name (e.g. "order_update").
     *
     * @return array{data: array<int,array<string,mixed>>, paging?: array<string,mixed>}
     */
    public function get(string $name): array
    {
        return $this->client->request(
            'GET',
            '/v1/sessions/' . rawurlencode($this->sessionId) . '/templates/' . rawurlencode($name)
        );
    }

    /**
     * Submit a new template for Meta approval.
     *
     * @param string                   $name                Lowercase, digits & underscores (e.g. "order_update").
     * @param string                   $language            Language code (e.g. "en_US", "id").
     * @param string                   $category            One of "MARKETING" | "UTILITY" | "AUTHENTICATION".
     * @param array<int,mixed>         $components          Meta template-component objects (HEADER/BODY/FOOTER/BUTTONS); at least one.
     * @param bool|null                $allowCategoryChange Let Meta re-categorize the template if needed.
     *
     * @return array{id: string, status: string, category: string}
     */
    public function create(
        string $name,
        string $language,
        string $category,
        array $components,
        ?bool $allowCategoryChange = null
    ): array {
        $body = $this->prune([
            'name' => $name,
            'language' => $language,
            'category' => $category,
            'components' => $components,
            'allowCategoryChange' => $allowCategoryChange,
        ]);

        return $this->client->request(
            'POST',
            '/v1/sessions/' . rawurlencode($this->sessionId) . '/templates',
            [],
            $body
        );
    }

    /**
     * Delete a template by name (removes all of its language versions).
     *
     * @param string $name Template name to delete.
     *
     * @return array{success: bool}
     */
    public function delete(string $name): array
    {
        return $this->client->request(
            'DELETE',
            '/v1/sessions/' . rawurlencode($this->sessionId) . '/templates/' . rawurlencode($name)
        );
    }

    /**
     * Drop `null` entries so optional fields are omitted from the wire request
     * (false/0/"" are intentionally kept).
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

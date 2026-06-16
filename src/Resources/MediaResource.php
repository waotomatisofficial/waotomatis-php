<?php

declare(strict_types=1);

namespace Waotomatis\Resources;

use Waotomatis\Client;
use Waotomatis\Http\Multipart;

/** Upload and download media for one session. */
final class MediaResource
{
    private Client $client;
    private string $sessionId;

    public function __construct(Client $client, string $sessionId)
    {
        $this->client = $client;
        $this->sessionId = $sessionId;
    }

    /**
     * Upload raw bytes (multipart `file`) and get a `mediaId` for `messages.send`.
     *
     * @return array{mediaId: string, mimeType: string, size: int}
     */
    public function upload(string $contents, string $fileName = 'upload', ?string $mimeType = null): array
    {
        $multipart = Multipart::file('file', $contents, $fileName, $mimeType);

        return $this->client->requestMultipart(
            'POST',
            '/v1/sessions/' . rawurlencode($this->sessionId) . '/media',
            $multipart
        );
    }

    /**
     * Upload a local file by path and get a `mediaId`.
     *
     * @return array{mediaId: string, mimeType: string, size: int}
     */
    public function uploadFile(string $path, ?string $fileName = null, ?string $mimeType = null): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException("Cannot read media file: {$path}");
        }

        return $this->upload($contents, $fileName ?? basename($path), $mimeType);
    }

    /**
     * Upload media by URL; returns a `mediaId` you can pass to `messages.send`.
     *
     * @return array{mediaId: string, mimeType: string, size: int}
     */
    public function uploadFromUrl(string $url, ?string $mimeType = null): array
    {
        $body = ['url' => $url];
        if ($mimeType !== null) {
            $body['mimeType'] = $mimeType;
        }

        return $this->client->request(
            'POST',
            '/v1/sessions/' . rawurlencode($this->sessionId) . '/media',
            [],
            $body
        );
    }

    /**
     * Download inbound media bytes by provider media id.
     *
     * @return array{data: string, mimeType: string}
     */
    public function download(string $mediaId): array
    {
        $res = $this->client->requestRaw(
            'GET',
            '/v1/sessions/' . rawurlencode($this->sessionId) . '/media/' . rawurlencode($mediaId)
        );

        if (!$res->ok()) {
            throw $this->client->errorFromResponse($res);
        }

        return [
            'data' => $res->body,
            'mimeType' => $res->header('content-type') ?? 'application/octet-stream',
        ];
    }
}

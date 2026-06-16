<?php

declare(strict_types=1);

namespace Waotomatis\Http;

/**
 * A `multipart/form-data` body built by hand so the SDK has no dependency on a
 * specific HTTP library. Holds the encoded body plus the matching boundary so
 * the caller can set the correct `Content-Type` header.
 */
final class Multipart
{
    public string $body;

    public string $boundary;

    private function __construct(string $body, string $boundary)
    {
        $this->body = $body;
        $this->boundary = $boundary;
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    /**
     * Build a single-file multipart body.
     *
     * @param string $field    the form field name (the API expects `file`)
     * @param string $contents raw file bytes
     */
    public static function file(
        string $field,
        string $contents,
        string $fileName = 'upload',
        ?string $mimeType = null
    ): self {
        $boundary = '----WaotomatisBoundary' . bin2hex(random_bytes(16));
        $mime = $mimeType ?? 'application/octet-stream';

        $body = "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $fileName . "\"\r\n"
            . "Content-Type: {$mime}\r\n\r\n"
            . $contents . "\r\n"
            . "--{$boundary}--\r\n";

        return new self($body, $boundary);
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly ?string $file = null,   // streamed instead of $body (downloads)
    ) {
    }

    /** A file sent as an attachment, streamed from disk. */
    public static function download(string $path, string $name, string $type = 'application/octet-stream'): self
    {
        return new self('', 200, ['Content-Type' => $type, 'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"'], $path);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE);
        return new self((string) $body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $location): self
    {
        return new self('', 303, ['Location' => $location]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->file !== null) {
            readfile($this->file);
            return;
        }
        echo $this->body;
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Http;

final class Request
{
    /** @var array<string, string> */
    public array $params = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly string $basePath = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        // The app may live in a sub-folder (e.g. /public_html/portfolio): strip the script's directory.
        // Only trust SCRIPT_NAME when it is the front controller: PHP's built-in server reports a path
        // with a file extension (a backup download, say) as the script itself.
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $base = basename($script) === 'index.php' ? rtrim(dirname($script), '/') : '';
        $path = $base !== '' && str_starts_with($uri, $base) ? substr($uri, strlen($base)) : $uri;
        $path = '/' . trim(rawurldecode($path), '/');
        if ($path === '/index.php') {
            $path = '/';
        }
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $_POST, $_SERVER, $base);
    }

    public function query(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public function param(string $key): string
    {
        return $this->params[$key] ?? '';
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on' || ($this->server['SERVER_PORT'] ?? '') === '443'
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** API routes and fetch() calls that ask for JSON (e.g. the transaction drawer). */
    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/') || str_contains((string) ($this->server['HTTP_ACCEPT'] ?? ''), 'application/json');
    }
}

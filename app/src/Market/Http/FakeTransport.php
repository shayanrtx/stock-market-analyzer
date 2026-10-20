<?php

declare(strict_types=1);

namespace Sabadgardan\Market\Http;

/**
 * Test double: routes are URL substrings mapped to a result (or a list consumed in order,
 * to simulate retries). Unknown URLs return 404.
 */
final class FakeTransport implements Transport
{
    /** @var list<string> */
    public array $calls = [];

    /** @param array<string, HttpResult|list<HttpResult>|array|callable> $routes */
    public function __construct(private array $routes = [])
    {
    }

    public function route(string $fragment, HttpResult|array|callable $result): void
    {
        $this->routes[$fragment] = $result;
    }

    public static function json(mixed $data, int $status = 200): HttpResult
    {
        return new HttpResult($status, (string) json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function fetchMany(array $urls, array $headers, int $timeout): array
    {
        $out = [];
        foreach ($urls as $url) {
            $this->calls[] = $url;
            $out[$url] = $this->resolve($url);
        }
        return $out;
    }

    private function resolve(string $url): HttpResult
    {
        $decoded = rawurldecode($url);
        foreach ($this->routes as $fragment => &$result) {
            if (!str_contains($decoded, $fragment)) {
                continue;
            }
            if (is_callable($result)) {
                return $result($decoded);
            }
            if ($result instanceof HttpResult) {
                return $result;
            }
            if (array_is_list($result) && $result && $result[0] instanceof HttpResult) {
                return count($result) > 1 ? array_shift($result) : $result[0];
            }
            return self::json($result);
        }
        return new HttpResult(404, '');
    }
}

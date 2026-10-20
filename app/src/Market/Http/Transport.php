<?php

declare(strict_types=1);

namespace Sabadgardan\Market\Http;

/** Fetches URLs. Swappable so tests and the demo mode never touch the network. */
interface Transport
{
    /**
     * Fetch several URLs, in parallel where possible.
     *
     * @param list<string> $urls
     * @param array<string, string> $headers
     * @return array<string, HttpResult> keyed by URL
     */
    public function fetchMany(array $urls, array $headers, int $timeout): array;
}

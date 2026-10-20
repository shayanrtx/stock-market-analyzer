<?php

declare(strict_types=1);

namespace Sabadgardan\Market\Http;

final class HttpResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $error = '',
    ) {
    }

    /** Network failure or a status worth retrying (rate limit, gateway errors). */
    public function isTransient(): bool
    {
        return $this->status === 0 || in_array($this->status, [429, 502, 503, 504], true);
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}

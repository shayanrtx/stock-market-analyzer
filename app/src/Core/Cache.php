<?php

declare(strict_types=1);

namespace Sabadgardan\Core;

/** File cache with per-entry TTL. Writes are atomic (temp file + rename). */
final class Cache
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|callable(): int $clock injectable for simulations (bin/demo.php replays a session) */
    public function __construct(private ?string $dir, ?callable $clock = null)
    {
        if ($dir !== null && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->clock = $clock ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    public static function disabled(): self
    {
        return new self(null);
    }

    public function get(string $key): mixed
    {
        if ($this->dir === null) {
            return null;
        }
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        $data = $raw === false ? null : @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || ($data['expires'] ?? 0) < ($this->clock)()) {
            return null;
        }
        return $data['value'];
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        if ($this->dir === null || $ttl <= 0) {
            return;
        }
        $file = $this->path($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, serialize(['expires' => ($this->clock)() + $ttl, 'value' => $value])) !== false) {
            @rename($tmp, $file);
        }
    }

    /** Deletes expired entries; returns how many files were removed. */
    public function prune(): int
    {
        if ($this->dir === null) {
            return 0;
        }
        $removed = 0;
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $data = $raw === false ? null : @unserialize($raw, ['allowed_classes' => false]);
            if (!is_array($data) || ($data['expires'] ?? 0) < ($this->clock)()) {
                @unlink($file) && $removed++;
            }
        }
        return $removed;
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.cache';
    }
}

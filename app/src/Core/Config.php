<?php

declare(strict_types=1);

namespace Sabadgardan\Core;

/**
 * Read-only configuration. ``config/config.php`` (written by the installer) returns an array
 * that is merged over the defaults below. Keys use dot notation: ``db.driver``.
 */
final class Config
{
    private const DEFAULTS = [
        'debug' => false,
        'db' => [
            'driver' => 'sqlite',                    // sqlite | mysql
            'sqlite_path' => 'storage/database.sqlite',
            'mysql' => ['host' => 'localhost', 'port' => 3306, 'database' => '', 'username' => '', 'password' => '',
                        'charset' => 'utf8mb4'],
        ],
        'market' => [
            'timeout' => 15,
            'max_retries' => 2,
            'transport' => 'curl',                   // curl | demo (synthetic data for development)
        ],
        'live' => [
            'interval' => 45,                        // seconds between monitoring cycles
        ],
        'analysis' => [
            'inflation_annual' => 0.35,
            'risk_free_annual' => 0.30,
            'max_position_weight' => 25.0,
        ],
        'cron_token' => '',                          // required by the HTTP cron endpoint
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values, public readonly string $path, private bool $inMemory = false)
    {
    }

    public static function load(string $root): self
    {
        $file = $root . '/config/config.php';
        $user = is_file($file) ? require $file : [];
        return new self(self::merge(self::DEFAULTS, is_array($user) ? $user : []), $file);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self(self::merge(self::DEFAULTS, $values), '', true);
    }

    public function exists(): bool
    {
        return $this->inMemory || ($this->path !== '' && is_file($this->path));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key])
                ? self::merge($base[$key], $value)
                : $value;
        }
        return $base;
    }
}

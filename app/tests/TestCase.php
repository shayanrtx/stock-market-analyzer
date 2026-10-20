<?php

declare(strict_types=1);

namespace Tests;

final class AssertionFailed extends \RuntimeException
{
}

abstract class TestCase
{
    /** Simulated "now" for apps built by makeApp(); tests move it. */
    protected int $now = 0;

    public function setUp(): void
    {
    }

    /**
     * Fully wired application on an in-memory database with the synthetic market (DemoTransport)
     * on a controllable clock and a private cache directory.
     */
    protected function makeApp(?\Sabadgardan\Core\Database $db = null): \Sabadgardan\App
    {
        $this->now = $this->now ?: strtotime('2026-10-20 10:30:00 UTC') - \Sabadgardan\Live\MarketClock::OFFSET;  // Tue 10:30 Tehran
        $clock = fn (): int => $this->now;
        $config = \Sabadgardan\Core\Config::fromArray(['market' => ['transport' => 'demo', 'timeout' => 2, 'max_retries' => 0],
                                                          'cron_token' => 'secret-token']);
        $app = new \Sabadgardan\App(dirname(__DIR__), $config);
        $app->withTransport(new \Sabadgardan\Market\Http\DemoTransport($clock))->withClock($clock);
        $app->setCache(new \Sabadgardan\Core\Cache(sys_get_temp_dir() . '/sbg-test-' . bin2hex(random_bytes(5)), $clock));
        if (!$db) {
            $db = \Sabadgardan\Core\Database::memory();
            (new \Sabadgardan\Core\Migrations($db))->migrate();
        }
        $app->setDatabase($db);
        return $app;
    }

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        if ($value !== true) {
            throw new AssertionFailed($message ?: 'expected true, got ' . var_export($value, true));
        }
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        if ($value !== false) {
            throw new AssertionFailed($message ?: 'expected false, got ' . var_export($value, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(($message ? "$message: " : '') . 'expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
    }

    protected function assertNear(float $expected, ?float $actual, float $tolerance = 1e-6, string $message = ''): void
    {
        if ($actual === null || abs($expected - $actual) > $tolerance * max(1.0, abs($expected))) {
            throw new AssertionFailed(($message ? "$message: " : '') . "expected ≈ $expected, got "
                . var_export($actual, true));
        }
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertSame(null, $value, $message);
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(($message ? "$message: " : '') . "«{$needle}» not found in output");
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed(($message ? "$message: " : '') . "«{$needle}» unexpectedly found");
        }
    }

    protected function expectException(string $class, callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new AssertionFailed("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        throw new AssertionFailed("expected $class, nothing thrown");
    }
}

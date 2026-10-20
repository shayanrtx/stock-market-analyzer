<?php

declare(strict_types=1);

namespace Tests;

final class AssertionFailed extends \RuntimeException
{
}

abstract class TestCase
{
    public function setUp(): void
    {
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

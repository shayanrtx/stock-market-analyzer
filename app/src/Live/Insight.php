<?php

declare(strict_types=1);

namespace Sabadgardan\Live;

final class Insight
{
    public const STRENGTH = 1;
    public const WEAKNESS = -1;
    public const NEUTRAL = 0;

    /** @param array<string, mixed> $metrics */
    public function __construct(
        public readonly int $at,
        public readonly string $insCode,
        public readonly string $symbol,
        public readonly string $rule,
        public readonly int $direction,  // +1 strength, -1 weakness, 0 neutral
        public readonly int $severity,   // 1 info, 2 notable, 3 act now
        public readonly string $title,
        public readonly string $detail,
        public readonly array $metrics = [],
    ) {
    }

    public static function from(Snapshot $s, string $rule, int $direction, int $severity, string $title, string $detail,
                                array $metrics = []): self
    {
        return new self($s->at, $s->insCode, $s->symbol, $rule, $direction, $severity, $title, $detail, $metrics);
    }

    public function key(): string
    {
        return "{$this->insCode}|{$this->rule}|{$this->direction}";
    }
}

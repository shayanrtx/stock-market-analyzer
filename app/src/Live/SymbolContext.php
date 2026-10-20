<?php

declare(strict_types=1);

namespace Sabadgardan\Live;

/** Slow-moving reference data for a symbol, rebuilt once per trading day. */
final class SymbolContext
{
    public function __construct(
        public readonly string $insCode,
        public readonly string $symbol,
        public readonly ?float $avgVolume = null,   // 20-session average
        public readonly ?float $avgValue = null,
        public readonly ?float $prevHigh = null,
        public readonly ?float $prevClose = null,
        public readonly ?float $high52w = null,
        public readonly ?float $stop = null,
        public readonly ?float $target = null,
        public readonly ?float $potentialScore = null,
        public readonly ?bool $aboveSma200 = null,
        public readonly ?float $rewardRisk = null,
        public readonly ?float $atr = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        return new self(...$a);
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Live;

/** Slow-moving reference data for a symbol, rebuilt once per trading day. */
final class SymbolContext
{
    public const MIN_DAILY_RANGE = 0.005;

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
        public readonly string $assetClass = '',     // AssetClass kind
        public readonly bool $cashLike = false,      // fixed-income fund or bond
    ) {
    }

    /**
     * Instruments whose intraday moves are not events: cash-like ones (fixed-income funds, bonds),
     * and anything whose daily range (ATR) is under 0.5% of price.
     */
    public function isQuiet(): bool
    {
        return $this->cashLike
            || ($this->atr !== null && $this->prevClose && $this->atr / $this->prevClose < self::MIN_DAILY_RANGE);
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

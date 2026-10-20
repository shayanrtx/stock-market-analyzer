<?php

declare(strict_types=1);

namespace Sabadgardan\Live;

use Sabadgardan\Market\Field;

/** One poll of one symbol. Prices in rial, volumes in shares. Stored as JSON in live_snapshots. */
final class Snapshot
{
    /**
     * @param list<array{bid_price: float, bid_volume: float, bid_count: float, ask_price: float, ask_volume: float, ask_count: float}> $book
     */
    public function __construct(
        public readonly string $insCode,
        public readonly string $symbol,
        public readonly int $at,
        public readonly ?float $last = null,
        public readonly ?float $close = null,
        public readonly ?float $yesterday = null,
        public readonly ?float $high = null,
        public readonly float $volume = 0.0,
        public readonly float $value = 0.0,
        public readonly float $trades = 0.0,
        public readonly array $book = [],
        public readonly float $buyIVolume = 0.0,
        public readonly float $buyICount = 0.0,
        public readonly float $sellIVolume = 0.0,
        public readonly float $sellICount = 0.0,
        public readonly ?float $upperLimit = null,
        public readonly ?float $lowerLimit = null,
    ) {
    }

    public static function fromApi(string $insCode, string $symbol, int $at, array $priceInfo, array $bestLimits,
                                   array $clientType, ?float $upper = null, ?float $lower = null): self
    {
        $f = static fn (array $r, string $k) => Field::float($r, $k, 0.0);
        $levels = $bestLimits;
        usort($levels, static fn ($a, $b) => Field::float($a, 'number', 0.0) <=> Field::float($b, 'number', 0.0));
        $book = array_map(static fn ($lv) => [
            'bid_price' => $f($lv, 'pMeDem'), 'bid_volume' => $f($lv, 'qTitMeDem'), 'bid_count' => $f($lv, 'zOrdMeDem'),
            'ask_price' => $f($lv, 'pMeOf'), 'ask_volume' => $f($lv, 'qTitMeOf'), 'ask_count' => $f($lv, 'zOrdMeOf'),
        ], $levels);
        return new self($insCode, $symbol, $at,
            $f($priceInfo, 'pDrCotVal') ?: null, $f($priceInfo, 'pClosing') ?: null, $f($priceInfo, 'priceYesterday') ?: null,
            $f($priceInfo, 'priceMax') ?: null, $f($priceInfo, 'qTotTran5J'), $f($priceInfo, 'qTotCap'),
            $f($priceInfo, 'zTotTran'), $book, $f($clientType, 'buy_I_Volume'), $f($clientType, 'buy_CountI'),
            $f($clientType, 'sell_I_Volume'), $f($clientType, 'sell_CountI'), $upper, $lower);
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

    public function price(): ?float
    {
        return $this->last ?: $this->close;
    }

    public function changePct(): ?float
    {
        $p = $this->price();
        return $p && $this->yesterday ? ($p / $this->yesterday - 1) * 100 : null;
    }

    public function closeChangePct(): ?float
    {
        return $this->close && $this->yesterday ? ($this->close / $this->yesterday - 1) * 100 : null;
    }

    /** @return array<string, float> first order-book level (zeros when empty) */
    public function best(): array
    {
        return $this->book[0] ?? ['bid_price' => 0.0, 'bid_volume' => 0.0, 'bid_count' => 0.0,
                                  'ask_price' => 0.0, 'ask_volume' => 0.0, 'ask_count' => 0.0];
    }

    public function bidTotal(): float
    {
        return array_sum(array_column($this->book, 'bid_volume'));
    }

    public function askTotal(): float
    {
        return array_sum(array_column($this->book, 'ask_volume'));
    }

    /** 'buy' / 'sell' when the first level is one-sided or at the daily price limit, else null. */
    public function queue(): ?string
    {
        $b = $this->best();
        if ($b['bid_price'] > 0 && ($b['ask_price'] <= 0 || ($this->upperLimit && $b['bid_price'] >= $this->upperLimit))) {
            return 'buy';
        }
        if ($b['ask_price'] > 0 && ($b['bid_price'] <= 0 || ($this->lowerLimit && $b['ask_price'] <= $this->lowerLimit))) {
            return 'sell';
        }
        return null;
    }

    public function queueVolume(): float
    {
        $b = $this->best();
        return match ($this->queue()) {
            'buy' => $b['bid_volume'],
            'sell' => $b['ask_volume'],
            default => 0.0,
        };
    }

    public function queueValue(): float
    {
        $b = $this->best();
        return $this->queueVolume() * ($this->queue() === 'buy' ? $b['bid_price'] : $b['ask_price']);
    }

    public function buyerPower(): ?float
    {
        if ($this->buyICount && $this->sellICount && $this->sellIVolume) {
            return ($this->buyIVolume / $this->buyICount) / ($this->sellIVolume / $this->sellICount);
        }
        return null;
    }

    public function individualNetVolume(): float
    {
        return $this->buyIVolume - $this->sellIVolume;
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

/**
 * Daily OHLCV series, oldest first. Columns are parallel float arrays indexed 0..n-1;
 * ``dates`` holds "Y-m-d" strings. Prices in rial.
 */
final class PriceHistory
{
    public const ADJUST_TOLERANCE = 0.002;  // ignore reference/close mismatches from tick rounding

    /** @var list<array{0: string, 1: float}> date and factor of each applied adjustment */
    public array $adjustments = [];

    /**
     * @param list<string> $dates
     * @param list<float> $open @param list<float> $high @param list<float> $low @param list<float> $close
     * @param list<float> $yesterday @param list<float> $volume @param list<float> $value
     */
    public function __construct(
        public array $dates = [],
        public array $open = [],
        public array $high = [],
        public array $low = [],
        public array $close = [],
        public array $yesterday = [],
        public array $volume = [],
        public array $value = [],
    ) {
    }

    /** @param list<array<string, mixed>> $rows ``closingPriceDaily`` records (any order) */
    public static function fromClosingPriceDaily(array $rows): self
    {
        $byDate = [];
        foreach ($rows as $r) {
            $day = (string) Field::get($r, 'dEven', '');
            $volume = Field::float($r, 'qTotTran5J', 0.0);
            // Days without trades report zero prices; they would distort every indicator.
            if (strlen($day) !== 8 || $volume <= 0) {
                continue;
            }
            $date = substr($day, 0, 4) . '-' . substr($day, 4, 2) . '-' . substr($day, 6, 2);
            $close = Field::float($r, 'pClosing', 0.0);
            $byDate[$date] = [
                Field::float($r, 'priceFirst', $close), Field::float($r, 'priceMax', $close),
                Field::float($r, 'priceMin', $close), $close, Field::float($r, 'priceYesterday', 0.0),
                $volume, Field::float($r, 'qTotCap', 0.0),
            ];
        }
        ksort($byDate);
        $h = new self();
        foreach ($byDate as $date => [$o, $hi, $lo, $c, $y, $v, $val]) {
            $h->dates[] = $date;
            $h->open[] = $o;
            $h->high[] = $hi;
            $h->low[] = $lo;
            $h->close[] = $c;
            $h->yesterday[] = $y;
            $h->volume[] = $v;
            $h->value[] = $val;
        }
        return $h;
    }

    public function count(): int
    {
        return count($this->close);
    }

    public function isEmpty(): bool
    {
        return $this->close === [];
    }

    public function last(): ?float
    {
        return $this->close ? $this->close[count($this->close) - 1] : null;
    }

    /** Last ``n`` sessions as a new series. */
    public function tail(int $n): self
    {
        $n = max(0, $n);
        $slice = static fn (array $a) => array_slice($a, -$n);
        $h = new self($slice($this->dates), $slice($this->open), $slice($this->high), $slice($this->low),
            $slice($this->close), $slice($this->yesterday), $slice($this->volume), $slice($this->value));
        $h->adjustments = $this->adjustments;
        return $h;
    }

    /**
     * Back-adjusts prices for capital increases and cash dividends.
     *
     * On an adjustment day TSETMC's reference price (priceYesterday) differs from the
     * previous close; the ratio is the factor applied to every earlier price.
     */
    public function adjusted(): self
    {
        $n = $this->count();
        $factors = array_fill(0, $n, 1.0);
        $events = [];
        for ($i = 1; $i < $n; $i++) {
            $prev = $this->close[$i - 1];
            $ref = $this->yesterday[$i];
            if ($prev <= 0 || $ref <= 0) {
                continue;
            }
            $ratio = $ref / $prev;
            if (abs($ratio - 1) > self::ADJUST_TOLERANCE && $ratio >= 0.05 && $ratio <= 1.5) {
                $factors[$i] = $ratio;
                $events[] = [$this->dates[$i], $ratio];
            }
        }
        // cum[i] = product of the factors of every event after day i.
        $cum = array_fill(0, $n, 1.0);
        for ($i = $n - 2; $i >= 0; $i--) {
            $cum[$i] = $cum[$i + 1] * $factors[$i + 1];
        }
        $scale = static fn (array $col) => array_map(static fn ($v, $c) => $v * $c, $col, $cum);
        $h = new self($this->dates, $scale($this->open), $scale($this->high), $scale($this->low),
            $scale($this->close), $scale($this->yesterday), $this->volume, $this->value);
        $h->adjustments = $events;
        return $h;
    }

    /** Reference (previous) price for the latest session. */
    public function previousClose(): ?float
    {
        $n = $this->count();
        if ($n === 0) {
            return null;
        }
        if ($this->yesterday[$n - 1] > 0) {
            return $this->yesterday[$n - 1];
        }
        return $n > 1 ? $this->close[$n - 2] : null;
    }
}

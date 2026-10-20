<?php

declare(strict_types=1);

namespace Sabadgardan\Signals;

use Sabadgardan\Market\PriceHistory;

/**
 * Track record of issued signals against what prices did afterwards.
 *
 * A buy signal is followed from the session after it was issued (the intraday order of the signal and
 * that day's high/low is unknown) for up to the signal horizon: target first = «به هدف رسید», stop first
 * = «حد ضرر», a day touching both counts as a stop (conservative), neither = open or expired with the
 * return so far. Prices are unadjusted, so capital increases after the signal are neutralised by
 * rescaling later prices back to the signal's terms.
 */
final class SignalTracker
{
    public const TARGET = 'target';
    public const STOP = 'stop';
    public const OPEN = 'open';
    public const EXPIRED = 'expired';
    public const NEW = 'new';
    public const LABELS = [self::TARGET => 'به هدف رسید', self::STOP => 'حد ضرر', self::OPEN => 'در جریان',
                           self::EXPIRED => 'پایان افق', self::NEW => 'امروز'];

    /**
     * @param array<string, mixed> $signal row of the signals table (day Ymd, side, entry, stop, target)
     * @return array{status: string, label: string, return_pct: ?float, days: int, price: ?float}
     */
    public static function outcome(array $signal, PriceHistory $raw, int $horizon = SignalEngine::HORIZON): array
    {
        $day = (string) $signal['day'];
        $day = substr($day, 0, 4) . '-' . substr($day, 4, 2) . '-' . substr($day, 6, 2);
        $entry = (float) $signal['entry'];
        $buy = $signal['side'] === Signal::BUY;
        $stop = $signal['stop'] !== null ? (float) $signal['stop'] : null;
        $target = $signal['target'] !== null ? (float) $signal['target'] : null;

        $start = null;
        foreach ($raw->dates as $i => $d) {
            if ($d > $day) {
                $start = $i;
                break;
            }
        }
        if ($start === null || $entry <= 0) {
            return self::result(self::NEW, null, 0, null);
        }
        $scale = 1.0;
        $price = null;
        $days = 0;
        $end = min($raw->count() - 1, $start + $horizon - 1);
        for ($j = $start; $j <= $end; $j++) {
            $prev = $raw->close[$j - 1] ?? 0.0;
            $ref = $raw->yesterday[$j] ?? 0.0;
            if ($j > $start && $prev > 0 && $ref > 0 && $ref / $prev < 0.995) {
                $scale *= $prev / $ref;  // capital increase or dividend: keep prices in the signal's terms
            }
            $days++;
            $price = $raw->close[$j] * $scale;
            if ($buy) {
                if ($stop && $raw->low[$j] * $scale <= $stop) {
                    return self::result(self::STOP, ($stop / $entry - 1) * 100, $days, $stop);
                }
                if ($target && $raw->high[$j] * $scale >= $target) {
                    return self::result(self::TARGET, ($target / $entry - 1) * 100, $days, $target);
                }
            }
        }
        $status = $days >= $horizon ? self::EXPIRED : self::OPEN;
        return self::result($status, ($price / $entry - 1) * 100, $days, $price);
    }

    /**
     * Hit rate and average result of buy signals that are closed (target, stop or expired), and the
     * average move after sell signals (negative = the warning was right).
     *
     * @param list<array{side: string, outcome: array}> $rows
     * @return array<string, ?float>
     */
    public static function summary(array $rows): array
    {
        $closedBuys = array_filter($rows, static fn ($r) => $r['side'] === Signal::BUY
            && in_array($r['outcome']['status'], [self::TARGET, self::STOP, self::EXPIRED], true));
        $wins = array_filter($closedBuys, static fn ($r) => $r['outcome']['return_pct'] > 0);
        $buyReturns = array_map(static fn ($r) => $r['outcome']['return_pct'], $closedBuys);
        $sells = array_filter($rows, static fn ($r) => $r['side'] === Signal::SELL && $r['outcome']['return_pct'] !== null);
        $sellMoves = array_map(static fn ($r) => $r['outcome']['return_pct'], $sells);
        return [
            'buys' => (float) count(array_filter($rows, static fn ($r) => $r['side'] === Signal::BUY)),
            'closed_buys' => (float) count($closedBuys),
            'win_rate' => $closedBuys ? count($wins) / count($closedBuys) : null,
            'avg_buy_return_pct' => $buyReturns ? array_sum($buyReturns) / count($buyReturns) : null,
            'sells' => (float) count(array_filter($rows, static fn ($r) => $r['side'] === Signal::SELL)),
            'avg_sell_move_pct' => $sellMoves ? array_sum($sellMoves) / count($sellMoves) : null,
        ];
    }

    /** @return array{status: string, label: string, return_pct: ?float, days: int, price: ?float} */
    private static function result(string $status, ?float $ret, int $days, ?float $price): array
    {
        return ['status' => $status, 'label' => self::LABELS[$status], 'return_pct' => $ret, 'days' => $days, 'price' => $price];
    }
}

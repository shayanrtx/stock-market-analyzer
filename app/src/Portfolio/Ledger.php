<?php

declare(strict_types=1);

namespace Sabadgardan\Portfolio;

use Sabadgardan\Market\Jalali;

/**
 * Replays transactions in date order into positions using the weighted-average cost method
 * (میانگین موزون), as Iranian brokers report it:
 *
 * - buy:   cost basis += quantity × price + fee
 * - sell:  realized P/L += (quantity × price − fee) − average cost × quantity
 * - bonus: quantity grows, cost basis unchanged (average cost falls)
 * - dividend / deposit / withdraw: cash only (dividends are also tracked per symbol)
 */
final class Ledger
{
    /** @var array<string, array<string, mixed>> keyed by ins_code */
    private array $positions = [];
    private float $cash = 0.0;
    private bool $tracksCash = false;
    private float $realized = 0.0;
    private float $dividends = 0.0;
    private float $fees = 0.0;
    private float $invested = 0.0;  // net external cash in (deposits − withdrawals)

    /** @param list<Transaction> $transactions */
    public function __construct(array $transactions)
    {
        usort($transactions, static fn (Transaction $a, Transaction $b) => [$a->date, $a->id ?? PHP_INT_MAX]
            <=> [$b->date, $b->id ?? PHP_INT_MAX]);
        foreach ($transactions as $t) {
            $this->add($t);
        }
    }

    /**
     * Throws \DomainException if saving ``$t`` (new, or an edit of an existing id) would make a
     * holding negative at any point, e.g. selling more than was held on that date.
     *
     * @param list<Transaction> $existing
     */
    public static function validateSave(array $existing, Transaction $t): void
    {
        $others = $t->id === null ? $existing : array_filter($existing, static fn (Transaction $x) => $x->id !== $t->id);
        new self([...array_values($others), $t]);
    }

    /**
     * Throws \DomainException if deleting transaction ``$id`` would invalidate a later sale.
     *
     * @param list<Transaction> $existing
     */
    public static function validateDelete(array $existing, int $id): void
    {
        new self(array_values(array_filter($existing, static fn (Transaction $x) => $x->id !== $id)));
    }

    /** Applies one transaction; callers replaying incrementally must add in date order. */
    public function add(Transaction $t): void
    {
        $this->cash += $t->cashFlow();
        $this->fees += $t->fee;
        if (in_array($t->type, [Transaction::DEPOSIT, Transaction::WITHDRAW], true)) {
            $this->tracksCash = true;
            $this->invested += $t->type === Transaction::DEPOSIT ? $t->amount : -$t->amount;
        }
        if ($t->insCode === null || $t->insCode === '') {
            return;
        }
        $p = &$this->positions[$t->insCode];
        $p ??= ['ins_code' => $t->insCode, 'symbol' => $t->symbol, 'quantity' => 0.0, 'cost' => 0.0, 'realized' => 0.0,
                'dividends' => 0.0, 'fees' => 0.0, 'first_date' => $t->date, 'last_date' => $t->date, 'trades' => 0];
        $p['symbol'] = $t->symbol ?: $p['symbol'];
        $p['last_date'] = $t->date;
        switch ($t->type) {
            case Transaction::BUY:
                if ($p['quantity'] <= 0) {
                    $p['first_date'] = $t->date;  // a new holding period starts
                }
                $p['quantity'] += $t->quantity;
                $p['cost'] += $t->quantity * $t->price + $t->fee;
                $p['fees'] += $t->fee;
                $p['trades']++;
                break;
            case Transaction::SELL:
                if ($t->quantity > $p['quantity'] + 1e-9) {
                    throw new \DomainException(sprintf('فروش %s سهم «%s» در تاریخ %s بیشتر از مانده‌ی %s سهم است.',
                        number_format($t->quantity), $p['symbol'], Jalali::fromGregorianString($t->date),
                        number_format($p['quantity'])));
                }
                $avg = $p['quantity'] > 0 ? $p['cost'] / $p['quantity'] : 0.0;
                $costOut = $avg * $t->quantity;
                $pnl = $t->quantity * $t->price - $t->fee - $costOut;
                $p['realized'] += $pnl;
                $this->realized += $pnl;
                $p['cost'] -= $costOut;
                $p['quantity'] -= $t->quantity;
                $p['fees'] += $t->fee;
                $p['trades']++;
                if ($p['quantity'] < 1e-9) {
                    $p['quantity'] = 0.0;
                    $p['cost'] = 0.0;
                }
                break;
            case Transaction::BONUS:
                $p['quantity'] += $t->quantity;
                break;
            case Transaction::DIVIDEND:
                $p['dividends'] += $t->amount;
                $this->dividends += $t->amount;
                break;
        }
        unset($p);
    }

    /** @return list<array<string, mixed>> open positions with average cost */
    public function openPositions(): array
    {
        $out = [];
        foreach ($this->positions as $p) {
            if ($p['quantity'] > 0) {
                $p['avg_cost'] = $p['cost'] / $p['quantity'];
                $out[] = $p;
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> fully closed positions with their realized result */
    public function closedPositions(): array
    {
        return array_values(array_filter($this->positions, static fn ($p) => $p['quantity'] <= 0 && $p['trades'] > 0));
    }

    /** @return array<string, float|bool> */
    public function totals(): array
    {
        return [
            'cash' => $this->cash,
            'tracks_cash' => $this->tracksCash,
            'realized' => $this->realized,
            'dividends' => $this->dividends,
            'fees' => $this->fees,
            'invested' => $this->invested,
            'cost_basis' => array_sum(array_map(static fn ($p) => $p['cost'], $this->openPositions())),
        ];
    }

    /** @return array<string, mixed>|null */
    public function position(string $insCode): ?array
    {
        $p = $this->positions[$insCode] ?? null;
        if ($p && $p['quantity'] > 0) {
            $p['avg_cost'] = $p['cost'] / $p['quantity'];
        }
        return $p;
    }
}

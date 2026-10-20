<?php

declare(strict_types=1);

namespace Sabadgardan\Portfolio;

final class Transaction
{
    public const BUY = 'buy';
    public const SELL = 'sell';
    public const DEPOSIT = 'deposit';
    public const WITHDRAW = 'withdraw';
    public const DIVIDEND = 'dividend';
    public const BONUS = 'bonus';  // bonus shares from a capital increase: quantity without cash

    public const LABELS = [
        self::BUY => 'خرید', self::SELL => 'فروش', self::DEPOSIT => 'واریز وجه', self::WITHDRAW => 'برداشت وجه',
        self::DIVIDEND => 'سود نقدی', self::BONUS => 'سهام جایزه',
    ];
    public const TRADES = [self::BUY, self::SELL, self::BONUS];

    public function __construct(
        public readonly ?int $id,
        public readonly string $type,
        public readonly string $date,          // Y-m-d (Gregorian)
        public readonly ?string $insCode = null,
        public readonly ?string $symbol = null,
        public readonly float $quantity = 0.0,
        public readonly float $price = 0.0,
        public readonly float $fee = 0.0,
        public readonly float $amount = 0.0,   // cash amount for deposit/withdraw/dividend
        public readonly string $note = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self((int) $row['id'], (string) $row['type'], (string) $row['trade_date'],
            $row['ins_code'] !== null ? (string) $row['ins_code'] : null, $row['symbol'] !== null ? (string) $row['symbol'] : null,
            (float) $row['quantity'], (float) $row['price'], (float) $row['fee'], (float) $row['amount'],
            (string) $row['note']);
    }

    public function isTrade(): bool
    {
        return in_array($this->type, self::TRADES, true);
    }

    /** Signed effect on cash (rial). */
    public function cashFlow(): float
    {
        return match ($this->type) {
            self::BUY => -($this->quantity * $this->price + $this->fee),
            self::SELL => $this->quantity * $this->price - $this->fee,
            self::DEPOSIT, self::DIVIDEND => $this->amount,
            self::WITHDRAW => -$this->amount,
            default => 0.0,
        };
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Portfolio;

/**
 * Broker fee rates by fee class (fractions of trade value), see AssetClass::feeClass(). Defaults
 * follow the common Iranian schedule (stock and rights sales include the 0.5% transfer tax;
 * funds, bonds and options pay none); every rate is editable in Settings, and the fee of each
 * transaction can be overridden with the broker's exact figure.
 */
final class FeeSchedule
{
    public const CLASSES = ['stock', 'fund', 'bond', 'option'];
    public const DEFAULTS = [
        'fee_stock_buy' => 0.003712,
        'fee_stock_sell' => 0.0088,
        'fee_fund_buy' => 0.00116,
        'fee_fund_sell' => 0.001875,
        'fee_bond_buy' => 0.000725,
        'fee_bond_sell' => 0.000725,
        'fee_option_buy' => 0.00103,
        'fee_option_sell' => 0.00103,
    ];

    /** @param array<string, float> $rates */
    public function __construct(private array $rates = self::DEFAULTS)
    {
        $this->rates += self::DEFAULTS;
    }

    /** @param string $feeClass stock | fund | bond | option (unknown classes pay the stock rate) */
    public function rate(string $side, string $feeClass): float
    {
        $class = in_array($feeClass, self::CLASSES, true) ? $feeClass : 'stock';
        return (float) $this->rates["fee_{$class}_{$side}"];
    }

    public function fee(string $side, string $feeClass, float $quantity, float $price): float
    {
        return round($quantity * $price * $this->rate($side, $feeClass));
    }

    /** @return array<string, float> */
    public function all(): array
    {
        return $this->rates;
    }
}

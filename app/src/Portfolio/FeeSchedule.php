<?php

declare(strict_types=1);

namespace Sabadgardan\Portfolio;

/**
 * Broker fee rates by asset class (fractions of trade value). Defaults follow the common
 * Iranian schedule (stock sell includes the 0.5% transfer tax); every rate is editable in
 * Settings, and the fee of each transaction can be overridden with the broker's exact figure.
 */
final class FeeSchedule
{
    public const DEFAULTS = [
        'fee_stock_buy' => 0.003712,
        'fee_stock_sell' => 0.0088,
        'fee_fund_buy' => 0.00116,
        'fee_fund_sell' => 0.001875,
    ];

    /** @param array<string, float> $rates */
    public function __construct(private array $rates = self::DEFAULTS)
    {
        $this->rates += self::DEFAULTS;
    }

    public function rate(string $side, string $assetType): float
    {
        $class = $assetType === 'صندوق' ? 'fund' : 'stock';
        return (float) $this->rates["fee_{$class}_{$side}"];
    }

    public function fee(string $side, string $assetType, float $quantity, float $price): float
    {
        return round($quantity * $price * $this->rate($side, $assetType));
    }

    /** @return array<string, float> */
    public function all(): array
    {
        return $this->rates;
    }
}

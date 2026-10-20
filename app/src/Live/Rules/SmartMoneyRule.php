<?php

declare(strict_types=1);

namespace Sabadgardan\Live\Rules;

use Sabadgardan\Live\Insight;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Live\SymbolContext;

/** Intraday individual buyer power: per-capita buy volume over per-capita sell volume. */
final class SmartMoneyRule implements Rule
{
    public const STRONG = 2.0;
    public const WEAK = 0.5;
    public const MIN_TRADERS = 20;

    public function id(): string { return 'smart_money'; }
    public function phases(): array { return [MarketClock::CONTINUOUS]; }
    public function description(): string { return 'ورود یا خروج پول هوشمند (قدرت خریدار حقیقی در طول روز)'; }

    public function evaluate(array $history, SymbolContext $ctx, int $now): array
    {
        $s = $history[count($history) - 1];
        $power = $s->buyerPower();
        $price = $s->price();
        if ($power === null || !$price || $s->buyICount < self::MIN_TRADERS || $s->sellICount < self::MIN_TRADERS) {
            return [];
        }
        $detail = sprintf('قدرت خریدار حقیقی %.2f (سرانه‌ی خرید %s، سرانه‌ی فروش %s)؛ خالص ورود پول حقیقی %s.', $power,
            Support::money($s->buyIVolume / $s->buyICount * $price), Support::money($s->sellIVolume / $s->sellICount * $price),
            Support::money($s->individualNetVolume() * $price));
        if ($power >= self::STRONG) {
            return [Insight::from($s, 'smart_money', 1, $power >= 3 ? 3 : 2, 'ورود پول هوشمند', $detail, ['power' => $power])];
        }
        if ($power <= self::WEAK) {
            return [Insight::from($s, 'smart_money', -1, $power <= 0.33 ? 3 : 2, 'خروج پول هوشمند', $detail, ['power' => $power])];
        }
        return [];
    }
}

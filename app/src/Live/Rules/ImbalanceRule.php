<?php

declare(strict_types=1);

namespace Sabadgardan\Live\Rules;

use Sabadgardan\Live\Insight;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Live\SymbolContext;

/** Bid/ask volume imbalance over the first five order-book levels (when not queued). */
final class ImbalanceRule implements Rule
{
    public const RATIO = 3.0;
    public const STRONG = 5.0;

    public function id(): string { return 'imbalance'; }
    public function phases(): array { return [MarketClock::PRE_OPEN, MarketClock::CONTINUOUS]; }
    public function description(): string { return 'عدم توازن حجم سفارش‌های خرید و فروش در ۵ سطح اول تابلو'; }

    public function evaluate(array $history, SymbolContext $ctx, int $now): array
    {
        $s = $history[count($history) - 1];
        $bid = $s->bidTotal();
        $ask = $s->askTotal();
        if ($s->queue() || !$bid || !$ask) {
            return [];
        }
        $ratio = $bid / $ask;
        if ($ratio >= self::RATIO) {
            return [Insight::from($s, 'imbalance', 1, $ratio >= self::STRONG ? 2 : 1, 'برتری سمت خرید در تابلو',
                sprintf('حجم سفارش‌های خرید ۵ سطح اول %.1f برابر سفارش‌های فروش است.', $ratio), ['ratio' => $ratio])];
        }
        if ($ratio <= 1 / self::RATIO) {
            return [Insight::from($s, 'imbalance', -1, $ratio <= 1 / self::STRONG ? 2 : 1, 'برتری سمت فروش در تابلو',
                sprintf('حجم سفارش‌های فروش ۵ سطح اول %.1f برابر سفارش‌های خرید است.', 1 / $ratio), ['ratio' => $ratio])];
        }
        return [];
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Live\Rules;

use Sabadgardan\Live\Insight;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Live\SymbolContext;

/** Buy/sell queue and how heavy it is relative to the average daily traded value. */
final class QueueRule implements Rule
{
    public const HEAVY = 1.0;
    public const NOTABLE = 0.3;

    public function id(): string { return 'queue'; }
    public function phases(): array { return [MarketClock::PRE_OPEN, MarketClock::CONTINUOUS]; }
    public function description(): string { return 'شکل‌گیری صف خرید/فروش و سنگینی آن نسبت به ارزش معاملات روزانه'; }

    public function evaluate(array $history, SymbolContext $ctx, int $now): array
    {
        $s = $history[count($history) - 1];
        $q = $s->queue();
        if (!$q) {
            return [];
        }
        $value = $s->queueValue();
        $ratio = $ctx->avgValue ? $value / $ctx->avgValue : null;
        $severity = $ratio !== null && $ratio >= self::HEAVY ? 3 : ($ratio !== null && $ratio >= self::NOTABLE ? 2 : 1);
        $side = $q === 'buy' ? 'خرید' : 'فروش';
        $detail = sprintf('حجم صف %s سهم به ارزش %s', number_format($s->queueVolume()), Support::mt($value));
        if ($ratio !== null) {
            $detail .= sprintf('، معادل %.1f برابر میانگین ارزش معاملات روزانه', $ratio);
        }
        $count = $s->best()[$q === 'buy' ? 'bid_count' : 'ask_count'];
        if ($count) {
            $detail .= sprintf('؛ %s سفارش در صف', number_format($count));
        }
        return [Insight::from($s, 'queue', $q === 'buy' ? 1 : -1, $severity,
            'صف ' . ($severity === 3 ? 'سنگین ' : '') . $side, $detail . '.', ['queue_value' => $value, 'ratio' => $ratio])];
    }
}

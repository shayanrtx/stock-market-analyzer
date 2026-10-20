<?php

declare(strict_types=1);

namespace Sabadgardan\Live\Rules;

use Sabadgardan\Live\Insight;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Live\SymbolContext;

/** A buy queue breaking or draining, a sell queue clearing, or a queue strengthening. */
final class QueueChangeRule implements Rule
{
    public const DRAIN = 0.30;
    public const LOOKBACK = 600;

    public function id(): string { return 'queue_change'; }
    public function phases(): array { return [MarketClock::CONTINUOUS]; }
    public function description(): string { return 'ریزش یا شکسته شدن صف خرید، جمع شدن صف فروش، تقویت صف'; }

    public function evaluate(array $history, SymbolContext $ctx, int $now): array
    {
        $s = $history[count($history) - 1];
        $prev = Support::before($history, $now, self::LOOKBACK);
        if (!$prev || !$prev->queue()) {
            return [];
        }
        if ($prev->queue() === 'buy' && $s->queue() !== 'buy') {
            return [Insight::from($s, 'queue_change', -1, 3, 'صف خرید شکسته شد',
                sprintf('صف خرید %s سهمی از بین رفت؛ عرضه وارد شده و قیمت از سقف فاصله گرفته.', number_format($prev->queueVolume())))];
        }
        if ($prev->queue() === 'sell' && $s->queue() !== 'sell') {
            return [Insight::from($s, 'queue_change', 1, 3, 'صف فروش جمع شد',
                sprintf('صف فروش %s سهمی جمع شد؛ تقاضا عرضه را جذب کرده است.', number_format($prev->queueVolume())))];
        }
        if ($s->queue() !== $prev->queue() || !$prev->queueVolume()) {
            return [];
        }
        $change = $s->queueVolume() / $prev->queueVolume() - 1;
        $minutes = round(($s->at - $prev->at) / 60);
        $side = $s->queue() === 'buy' ? 'خرید' : 'فروش';
        if ($change <= -self::DRAIN) {
            return [Insight::from($s, 'queue_change', $s->queue() === 'buy' ? -1 : 1, 2,
                $s->queue() === 'buy' ? 'صف خرید در حال ریختن' : 'صف فروش در حال جمع شدن',
                sprintf('حجم صف %s در %d دقیقه %d٪ کم شده است.', $side, $minutes, round(abs($change) * 100)),
                ['change' => $change])];
        }
        if ($change >= 1.0) {
            return [Insight::from($s, 'queue_change', $s->queue() === 'buy' ? 1 : -1, 2, "تقویت صف $side",
                sprintf('حجم صف %s در %d دقیقه %d٪ بیشتر شده است.', $side, $minutes, round($change * 100)),
                ['change' => $change])];
        }
        return [];
    }
}

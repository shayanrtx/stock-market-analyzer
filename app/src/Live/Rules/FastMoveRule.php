<?php

declare(strict_types=1);

namespace Sabadgardan\Live\Rules;

use Sabadgardan\Live\Insight;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Live\SymbolContext;

/** A sharp price move within the last few minutes. */
final class FastMoveRule implements Rule
{
    public const PCT = 2.0;
    public const WINDOW = 600;

    public function id(): string { return 'fast_move'; }
    public function phases(): array { return [MarketClock::CONTINUOUS]; }
    public function description(): string { return 'جهش یا افت سریع قیمت در ۱۰ دقیقه‌ی اخیر'; }

    public function evaluate(array $history, SymbolContext $ctx, int $now): array
    {
        $s = $history[count($history) - 1];
        $prev = Support::before($history, $now, self::WINDOW);
        if (!$prev || !$prev->price() || !$s->price()) {
            return [];
        }
        $change = ($s->price() / $prev->price() - 1) * 100;
        if (abs($change) < self::PCT) {
            return [];
        }
        return [Insight::from($s, 'fast_move', $change > 0 ? 1 : -1, 2, $change > 0 ? 'جهش قیمت' : 'افت سریع قیمت',
            sprintf('قیمت در %d دقیقه %s٪ تغییر کرده است.', round(($s->at - $prev->at) / 60), Support::signed($change)),
            ['change' => $change])];
    }
}

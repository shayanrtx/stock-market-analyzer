<?php

declare(strict_types=1);

namespace Sabadgardan\Live\Rules;

use Sabadgardan\Live\Insight;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Live\SymbolContext;

/** Breakout above yesterday's / the 52-week high, target reached, stop-loss crossed. */
final class LevelsRule implements Rule
{
    public function id(): string { return 'levels'; }
    public function phases(): array { return [MarketClock::CONTINUOUS]; }
    public function description(): string { return 'شکست سقف دیروز یا سقف ۵۲ هفته، رسیدن به هدف، عبور از حد ضرر'; }

    public function evaluate(array $history, SymbolContext $ctx, int $now): array
    {
        $s = $history[count($history) - 1];
        $p = $s->price();
        if (!$p) {
            return [];
        }
        $out = [];
        if ($ctx->stop && $p < $ctx->stop) {
            $out[] = Insight::from($s, 'stop', -1, 3, 'عبور از حد ضرر',
                sprintf('قیمت %s زیر حد ضرر (%s) است.', number_format($p), number_format($ctx->stop)));
        }
        if ($ctx->target && $p >= $ctx->target) {
            $out[] = Insight::from($s, 'target', 1, 2, 'رسیدن به هدف قیمتی',
                sprintf('قیمت %s به مقاومت/هدف %s رسیده است؛ برای سیو سود یا جابه‌جایی حد ضرر تصمیم بگیرید.',
                    number_format($p), number_format($ctx->target)));
        }
        if ($ctx->high52w && $p > $ctx->high52w) {
            $out[] = Insight::from($s, 'breakout', 1, 3, 'شکست سقف ۵۲ هفته',
                sprintf('قیمت %s بالای سقف ۵۲ هفته (%s) معامله می‌شود.', number_format($p), number_format($ctx->high52w)));
        } elseif ($ctx->prevHigh && $p > $ctx->prevHigh && ($s->changePct() ?? 0) > 0) {
            $out[] = Insight::from($s, 'breakout', 1, 1, 'عبور از سقف دیروز',
                sprintf('قیمت %s بالای بیشترین قیمت دیروز (%s) است.', number_format($p), number_format($ctx->prevHigh)));
        }
        return $out;
    }
}

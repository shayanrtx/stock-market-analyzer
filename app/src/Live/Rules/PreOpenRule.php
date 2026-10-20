<?php

declare(strict_types=1);

namespace Sabadgardan\Live\Rules;

use Sabadgardan\Live\Insight;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Live\SymbolContext;

/** Opening outlook from orders entered during the pre-opening session (08:45-09:00). */
final class PreOpenRule implements Rule
{
    public function id(): string { return 'pre_open'; }
    public function phases(): array { return [MarketClock::PRE_OPEN]; }
    public function description(): string { return 'برآورد وضعیت بازگشایی از سفارش‌های ثبت‌شده در پیش‌گشایش'; }

    public function evaluate(array $history, SymbolContext $ctx, int $now): array
    {
        $s = $history[count($history) - 1];
        $b = $s->best();
        $ref = $s->yesterday ?: $ctx->prevClose;
        if (!$ref || (!$b['bid_price'] && !$b['ask_price'])) {
            return [];
        }
        if ($s->upperLimit && $b['bid_price'] >= $s->upperLimit) {
            return [Insight::from($s, 'pre_open', 1, 3, 'احتمال بازگشایی با صف خرید',
                sprintf('بهترین سفارش خرید در سقف دامنه (%s) ثبت شده است با حجم %s.', number_format($b['bid_price']),
                    number_format($b['bid_volume'])))];
        }
        if ($s->lowerLimit && $b['ask_price'] && $b['ask_price'] <= $s->lowerLimit) {
            return [Insight::from($s, 'pre_open', -1, 3, 'احتمال بازگشایی با صف فروش',
                sprintf('بهترین سفارش فروش در کف دامنه (%s) ثبت شده است با حجم %s.', number_format($b['ask_price']),
                    number_format($b['ask_volume'])))];
        }
        if ($b['bid_price'] && $b['ask_price'] && $b['bid_price'] >= $b['ask_price']) {
            $indicative = ($b['bid_price'] + $b['ask_price']) / 2;
            $change = ($indicative / $ref - 1) * 100;
            if (abs($change) >= 1) {
                return [Insight::from($s, 'pre_open', $change > 0 ? 1 : -1, 2,
                    sprintf('قیمت تقریبی بازگشایی %s٪', Support::signed($change)),
                    sprintf('تابلوی پیش‌گشایش هم‌پوشان است؛ قیمت تعادلی تقریبی %s ریال نسبت به قیمت پایانی دیروز %s٪.',
                        number_format($indicative), Support::signed($change)), ['change' => $change])];
            }
        }
        return [];
    }
}

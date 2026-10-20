<?php

declare(strict_types=1);

namespace Sabadgardan\Live\Rules;

use Sabadgardan\Live\Insight;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Live\SymbolContext;

/** Volume so far against the volume normally traded by this time of day. */
final class VolumePaceRule implements Rule
{
    public const UNUSUAL = 2.5;
    public const EXTREME = 4.0;
    public const MIN_PROGRESS = 0.07;  // ~15 minutes into the session

    public function id(): string { return 'volume_pace'; }
    public function phases(): array { return [MarketClock::CONTINUOUS]; }
    public function description(): string { return 'حجم مشکوک: حجم تا این ساعت در برابر حجم عادی در همین ساعت'; }

    public function evaluate(array $history, SymbolContext $ctx, int $now): array
    {
        $s = $history[count($history) - 1];
        $progress = MarketClock::sessionProgress($now);
        if ($progress < self::MIN_PROGRESS || !$ctx->avgVolume || !$s->volume) {
            return [];
        }
        $pace = $s->volume / ($ctx->avgVolume * $progress);
        if ($pace < self::UNUSUAL) {
            return [];
        }
        $change = $s->changePct() ?? 0.0;
        $direction = $change > 0.5 ? 1 : ($change < -0.5 ? -1 : 0);
        return [Insight::from($s, 'volume_pace', $direction, $pace >= self::EXTREME ? 3 : 2, 'حجم مشکوک',
            sprintf('حجم تا این ساعت %.1f برابر حجم عادی در همین زمان است (تغییر قیمت %s٪)؛ احتمال ورود اطلاعات یا معامله‌گر بزرگ.',
                $pace, Support::signed($change)), ['pace' => $pace])];
    }
}

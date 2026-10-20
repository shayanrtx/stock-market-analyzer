<?php

declare(strict_types=1);

namespace Sabadgardan\Live\Rules;

use Sabadgardan\Live\Snapshot;

/** Helpers shared by the rules. */
final class Support
{
    /** Rial -> «x م.ت» (million toman). */
    public static function mt(float $rial): string
    {
        return number_format($rial / 10_000_000, 1) . ' م.ت';
    }

    /**
     * Latest snapshot at least ``$seconds`` older than now (or the oldest available one).
     *
     * @param list<Snapshot> $history
     */
    public static function before(array $history, int $now, int $seconds): ?Snapshot
    {
        $candidates = array_slice($history, 0, -1);
        $older = array_values(array_filter($candidates, static fn (Snapshot $s) => $now - $s->at >= $seconds));
        if ($older) {
            return $older[count($older) - 1];
        }
        return count($history) > 1 ? $history[0] : null;
    }

    public static function signed(float $v, int $digits = 1): string
    {
        return ($v > 0 ? '+' : '') . number_format($v, $digits);
    }
}

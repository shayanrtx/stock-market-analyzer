<?php

declare(strict_types=1);

namespace Sabadgardan\Image;

use Sabadgardan\Analysis\Indicators;
use Sabadgardan\Market\Jalali;
use Sabadgardan\Market\PriceHistory;
use Sabadgardan\Signals\Signal;

/**
 * PNG chart attached to signal messages: recent candles, SMA20/50, and the plan's entry, stop and
 * target lines, with Persian labels. Requires GD with FreeType; callers check ``available()``.
 */
final class SignalChart
{
    private const W = 1000;
    private const H = 600;
    private const PAD_L = 24;
    private const PAD_R = 118;
    private const TOP = 92;
    private const BOTTOM = 70;

    public function __construct(private string $fontRegular, private string $fontBold)
    {
    }

    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagettftext');
    }

    public function render(Signal $signal, PriceHistory $h, int $days = 90): string
    {
        $img = imagecreatetruecolor(self::W, self::H);
        imageantialias($img, true);
        $c = [
            'bg' => imagecolorallocate($img, 255, 255, 255), 'grid' => imagecolorallocate($img, 238, 240, 243),
            'text' => imagecolorallocate($img, 17, 24, 39), 'muted' => imagecolorallocate($img, 107, 114, 128),
            'up' => imagecolorallocate($img, 5, 150, 105), 'down' => imagecolorallocate($img, 220, 38, 38),
            'accent' => imagecolorallocate($img, 37, 99, 235), 'sma50' => imagecolorallocate($img, 139, 92, 246),
            'sma20' => imagecolorallocate($img, 245, 158, 11), 'band' => imagecolorallocate($img, 248, 250, 252),
        ];
        imagefilledrectangle($img, 0, 0, self::W, self::H, $c['bg']);

        $n = $h->count();
        $from = max(0, $n - $days);
        $sma20 = Indicators::sma($h->close, 20);
        $sma50 = Indicators::sma($h->close, 50);
        $levels = array_filter([$signal->entry, $signal->stop, $signal->target]);
        $lo = min([...array_slice($h->low, $from), ...$levels]);
        $hi = max([...array_slice($h->high, $from), ...$levels]);
        $span = ($hi - $lo) ?: 1.0;
        $lo -= $span * 0.04;
        $hi += $span * 0.04;
        $plotW = self::W - self::PAD_L - self::PAD_R;
        $plotH = self::H - self::TOP - self::BOTTOM;
        $y = static fn (float $v) => (int) round(self::TOP + ($hi - $v) / ($hi - $lo) * $plotH);
        $count = $n - $from;
        $step = $plotW / max($count, 1);
        $x = static fn (int $i) => (int) round(self::PAD_L + ($i - $from + 0.5) * $step);

        // Grid and price axis; axis labels that would collide with a level box are skipped.
        $levelYs = array_map($y, $levels);
        for ($k = 0; $k <= 5; $k++) {
            $v = $lo + ($hi - $lo) * $k / 5;
            imageline($img, self::PAD_L, $y($v), self::W - self::PAD_R, $y($v), $c['grid']);
            $clear = !array_filter($levelYs, static fn ($ly) => abs($ly - $y($v)) < 16);
            if ($clear) {
                $this->text($img, number_format($v), 11, self::W - self::PAD_R + 10, $y($v) + 5, $c['muted']);
            }
        }

        // Candles.
        $body = max(2, (int) floor($step * 0.6));
        for ($i = $from; $i < $n; $i++) {
            $up = $h->close[$i] >= $h->open[$i];
            $col = $up ? $c['up'] : $c['down'];
            imageline($img, $x($i), $y($h->high[$i]), $x($i), $y($h->low[$i]), $col);
            $top = $y(max($h->open[$i], $h->close[$i]));
            $bot = $y(min($h->open[$i], $h->close[$i]));
            imagefilledrectangle($img, $x($i) - intdiv($body, 2), $top, $x($i) + intdiv($body, 2), max($bot, $top + 1), $col);
        }
        $this->polyline($img, $sma20, $from, $n, $x, $y, $c['sma20']);
        $this->polyline($img, $sma50, $from, $n, $x, $y, $c['sma50']);

        // Plan levels.
        foreach ([['هدف', $signal->target, $c['up']], ['حد ضرر', $signal->stop, $c['down']], ['ورود', $signal->entry, $c['accent']]] as [$label, $level, $col]) {
            if (!$level) {
                continue;
            }
            imagesetthickness($img, 2);
            $this->dashed($img, self::PAD_L, $y($level), self::W - self::PAD_R, $col);
            imagesetthickness($img, 1);
            imagefilledrectangle($img, self::W - self::PAD_R + 2, $y($level) - 12, self::W - 4, $y($level) + 12, $col);
            $this->text($img, number_format($level), 11, self::W - self::PAD_R + 10, $y($level) + 5, $c['bg'], true);
            $this->rtl($img, $label, 12, self::PAD_L + 8, $y($level) - 8, $col, true, false);
        }

        // Header.
        $sideCol = $signal->side === Signal::BUY ? $c['up'] : $c['down'];
        imagefilledrectangle($img, 0, 0, self::W, 62, $c['band']);
        $this->rtl($img, $signal->label() . ' — ' . $signal->symbol, 22, self::W - 24, 42, $sideCol, true);
        if ($signal->probability !== null) {
            $prob = $signal->side === Signal::BUY ? 'احتمال رسیدن به هدف' : 'احتمال ادامه‌ی افت';
            $this->rtl($img, $prob . ' ' . round($signal->probability * 100) . '٪', 15, 24, 40, $c['text'], true, false);
        }

        // Footer: dates and legend.
        $this->text($img, Jalali::fromGregorianString($h->dates[$from]), 11, self::PAD_L, self::H - 44, $c['muted']);
        $this->text($img, Jalali::fromGregorianString($h->dates[$n - 1]), 11, self::W - self::PAD_R - 80, self::H - 44, $c['muted']);
        $this->rtl($img, 'میانگین ۲۰ روزه', 11, self::W - 24, self::H - 16, $c['sma20']);
        $this->rtl($img, 'میانگین ۵۰ روزه', 11, self::W - 160, self::H - 16, $c['sma50']);
        $this->rtl($img, 'تحلیل خودکار؛ توصیه‌ی قطعی نیست', 10, 24, self::H - 16, $c['muted'], false, false);

        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    private function polyline(\GdImage $img, array $series, int $from, int $n, callable $x, callable $y, int $col): void
    {
        imagesetthickness($img, 2);
        $prev = null;
        for ($i = $from; $i < $n; $i++) {
            if ($series[$i] === null) {
                $prev = null;
                continue;
            }
            $pt = [$x($i), $y($series[$i])];
            if ($prev) {
                imageline($img, $prev[0], $prev[1], $pt[0], $pt[1], $col);
            }
            $prev = $pt;
        }
        imagesetthickness($img, 1);
    }

    private function dashed(\GdImage $img, int $x1, int $yy, int $x2, int $col): void
    {
        for ($xx = $x1; $xx < $x2; $xx += 12) {
            imageline($img, $xx, $yy, min($xx + 6, $x2), $yy, $col);
        }
    }

    private function text(\GdImage $img, string $s, int $size, int $x, int $y, int $col, bool $bold = false): void
    {
        imagettftext($img, $size, 0, $x, $y, $col, $bold ? $this->fontBold : $this->fontRegular, $s);
    }

    /** Persian text anchored at its right edge (``$alignRight``) or left edge. */
    private function rtl(\GdImage $img, string $s, int $size, int $x, int $y, int $col, bool $bold = false,
                         bool $alignRight = true): void
    {
        $font = $bold ? $this->fontBold : $this->fontRegular;
        $visual = PersianShaper::visual($s);
        if ($alignRight) {
            $box = imagettfbbox($size, 0, $font, $visual);
            $x -= abs($box[2] - $box[0]);
        }
        imagettftext($img, $size, 0, $x, $y, $col, $font, $visual);
    }
}

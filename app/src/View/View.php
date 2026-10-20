<?php

declare(strict_types=1);

namespace Sabadgardan\View;

use Sabadgardan\Market\Jalali;

/**
 * Plain-PHP templates in ``views/``. Templates receive their data as variables plus ``$v``
 * (this object) for escaping, URLs and number formatting. All output must go through
 * ``$v->e()`` or a formatter, which escape.
 */
final class View
{
    private int $chartSeq = 0;

    /** @param array<string, mixed> $shared */
    public function __construct(private string $dir, private string $basePath, public array $shared = [])
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->partial($template, $data);
        return $layout === null ? $content : $this->partial($layout, ['content' => $content] + $data);
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        if (array_key_exists('v', $data)) {
            throw new \LogicException('"v" is reserved for the View in templates');
        }
        $v = $this;
        extract($this->shared + $data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------ escaping/urls

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function url(string $path = '/', array $query = []): string
    {
        $url = $this->basePath . '/' . ltrim($path, '/');
        return $query ? $url . '?' . http_build_query($query) : $url;
    }

    public function asset(string $path): string
    {
        $file = dirname($this->dir) . '/assets/' . $path;
        $version = is_file($file) ? substr((string) filemtime($file), -6) : '1';
        return $this->url('assets/' . $path) . '?v=' . $version;
    }

    public function stockUrl(string $symbol): string
    {
        return $this->url('stock', ['s' => $symbol]);
    }

    // ------------------------------------------------------------- numbers

    public function num(?float $value, int $digits = 0, bool $signed = false): string
    {
        if ($value === null || !is_finite($value)) {
            return '<span class="faint">-</span>';
        }
        $text = number_format(abs($value), $digits);
        $sign = $value < 0 && round($value, $digits) != 0 ? '-' : ($signed && round($value, $digits) > 0 ? '+' : '');
        return '<span class="num">' . $sign . $text . '</span>';
    }

    public function pct(?float $value, int $digits = 1, bool $colored = true, bool $signed = true): string
    {
        if ($value === null || !is_finite($value)) {
            return '<span class="faint">-</span>';
        }
        if (round($value, $digits) == 0) {
            $value = 0.0;
        }
        $cls = $colored ? ($value > 0 ? 'up' : ($value < 0 ? 'down' : '')) : '';
        $sign = $value < 0 ? '-' : ($signed && $value > 0 ? '+' : '');
        return '<span class="num ' . $cls . '">' . $sign . number_format(abs($value), $digits) . '٪</span>';
    }

    /** @return array{0: string, 1: string} formatted number and unit (toman) */
    public static function moneyParts(?float $rial): array
    {
        if ($rial === null) {
            return ['-', ''];
        }
        $toman = $rial / 10;
        $abs = abs($toman);
        if ($abs >= 1e9) {
            return [number_format($toman / 1e9, 2), 'میلیارد تومان'];
        }
        if ($abs >= 1e6) {
            return [number_format($toman / 1e6, 1), 'میلیون تومان'];
        }
        return [number_format($toman, 0), 'تومان'];
    }

    public function money(?float $rial, bool $signed = false, bool $colored = false): string
    {
        if ($rial === null || !is_finite($rial)) {
            return '<span class="faint">-</span>';
        }
        [$n, $unit] = self::moneyParts($rial);
        if ($signed && $rial > 0) {
            $n = '+' . $n;
        }
        $cls = $colored ? ($rial > 0 ? 'up' : ($rial < 0 ? 'down' : '')) : '';
        return '<span class="num ' . $cls . '">' . $n . '</span> <small class="muted">' . $unit . '</small>';
    }

    public function tone(?float $score): string
    {
        if ($score === null) {
            return '';
        }
        return $score >= 55 ? 'up' : ($score <= 45 ? 'down' : '');
    }

    public function jdate(string $gregorian): string
    {
        return $gregorian === '' ? '' : Jalali::fromGregorianString($gregorian);
    }

    public function now(): string
    {
        return Jalali::fromGregorianString(date('Y-m-d')) . ' · ' . date('H:i');
    }

    // ------------------------------------------------------------- widgets

    /** Container + JSON payload; ``assets/app.js`` turns it into an ECharts instance. */
    public function chart(array $option, string $size = '', ?int $height = null): string
    {
        if (!$option) {
            return '<div class="empty">داده‌ای برای نمودار نیست.</div>';
        }
        $id = 'c' . (++$this->chartSeq);
        $style = $height ? ' style="height:' . $height . 'px"' : '';
        $json = json_encode($option, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        return '<div class="chart ' . $size . '" data-chart="' . $id . '"' . $style . '></div>'
            . '<script type="application/json" id="' . $id . '">' . $json . '</script>';
    }

    public function icon(string $name, int $size = 18): string
    {
        return Icons::svg($name, $size);
    }

    /** "?" button that opens the glossary entry ``$key`` in a popover. */
    public function help(string $key): string
    {
        $entry = Glossary::get($key);
        if (!$entry) {
            return '';
        }
        return '<button type="button" class="help-btn" data-help-key="' . $this->e($key) . '" aria-label="راهنما: '
            . $this->e($entry['title']) . '">?</button>';
    }

    /**
     * Card wrapper. ``$help`` adds the "?" button and makes the card a stop of the guided tour.
     */
    public function card(string $title, string $body, int $span = 12, string $help = '', string $hint = '',
                         string $actions = ''): string
    {
        $attr = $help !== '' ? ' data-help="' . $this->e($help) . '"' : '';
        return '<section class="card s' . $span . '"' . $attr . '><header><div class="card-title"><h2>' . $this->e($title)
            . '</h2>' . ($help !== '' ? $this->help($help) : '') . '</div>'
            . ($hint !== '' ? '<span class="hint">' . $this->e($hint) . '</span>' : '') . $actions . '</header>' . $body . '</section>';
    }

    public function csrf(): string
    {
        return '<input type="hidden" name="_token" value="' . $this->e(\Sabadgardan\Http\Session::csrfToken()) . '">';
    }

    public function weightBar(float $weight, float $scale = 30.0): string
    {
        $w = max(0.0, min(100.0, $weight / $scale * 100));
        return $this->pct($weight, 1, false, false)
            . '<span class="weightbar"><i style="width:' . round($w) . '%"></i></span>';
    }

    /** Number of KPI tiles per desktop row (4-6) leaving the last row as full as possible. */
    public static function kpiColumns(int $n): int
    {
        if ($n <= 6) {
            return max($n, 1);
        }
        $best = 4;
        $bestFill = -1.0;
        foreach ([4, 5, 6] as $c) {
            $fill = (($n % $c) ?: $c) / $c;
            if ($fill > $bestFill) {
                [$best, $bestFill] = [$c, $fill];
            }
        }
        return $best;
    }
}

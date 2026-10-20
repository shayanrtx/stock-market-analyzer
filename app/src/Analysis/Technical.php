<?php

declare(strict_types=1);

namespace Sabadgardan\Analysis;

use Sabadgardan\Market\PriceHistory;

/** Short-term technical snapshot and signal list for one symbol. */
final class Technical
{
    public const RETURN_PERIODS = ['1w' => 5, '1m' => 21, '3m' => 63, '6m' => 126, '1y' => 250];

    /** @return array<string, ?float> */
    public static function snapshot(PriceHistory $h): array
    {
        $c = $h->close;
        $m = Indicators::macd($c);
        $bb = Indicators::bollinger($c);
        $hist = $m['hist'];
        $out = [
            'sma20' => Stats::last(Indicators::sma($c, 20)),
            'sma50' => Stats::last(Indicators::sma($c, 50)),
            'sma200' => Stats::last(Indicators::sma($c, 200)),
            'rsi14' => Stats::last(Indicators::rsi($c, 14)),
            'macd' => Stats::last($m['macd']),
            'macd_signal' => Stats::last($m['signal']),
            'macd_hist' => $hist ? $hist[count($hist) - 1] : null,
            'macd_hist_prev' => count($hist) > 1 ? $hist[count($hist) - 2] : null,
            'bb_upper' => Stats::last($bb['upper']),
            'bb_lower' => Stats::last($bb['lower']),
            'atr14' => Stats::last(Indicators::atr($h->high, $h->low, $c, 14)),
            'volume_ratio' => null,
        ];
        $n = $h->count();
        if ($n > 20) {
            $avg = Stats::mean(array_slice($h->volume, -21, 20));
            $out['volume_ratio'] = $avg ? $h->volume[$n - 1] / $avg : null;
        }
        return $out;
    }

    /** @return array<string, ?float> */
    public static function returns(PriceHistory $h): array
    {
        $out = [];
        foreach (self::RETURN_PERIODS as $key => $periods) {
            $out[$key] = Stats::pctReturn($h->close, $periods);
        }
        return $out;
    }

    /**
     * @param array<string, ?float> $t snapshot()
     * @param array<string, ?float> $flow MoneyFlow::fromRecord()
     * @return list<array{score: int, text: string}>
     */
    public static function signals(float $close, array $t, array $flow): array
    {
        $s = [];
        foreach (['sma20' => 'SMA20', 'sma50' => 'SMA50', 'sma200' => 'SMA200'] as $key => $label) {
            if ($t[$key]) {
                $above = $close > $t[$key];
                $s[] = ['score' => $above ? 1 : -1, 'text' => sprintf('قیمت %s میانگین %s (%s)',
                    $above ? 'بالای' : 'زیر', $label, number_format($t[$key]))];
            }
        }
        if ($t['sma50'] && $t['sma200']) {
            $golden = $t['sma50'] > $t['sma200'];
            $s[] = ['score' => $golden ? 1 : -1,
                    'text' => $golden ? 'SMA50 بالای SMA200 (روند صعودی)' : 'SMA50 زیر SMA200 (روند نزولی)'];
        }
        if (($rsi = $t['rsi14']) !== null) {
            if ($rsi >= 70) {
                $s[] = ['score' => -1, 'text' => sprintf('RSI=%.1f اشباع خرید', $rsi)];
            } elseif ($rsi <= 30) {
                $s[] = ['score' => 1, 'text' => sprintf('RSI=%.1f اشباع فروش (احتمال برگشت)', $rsi)];
            } else {
                $s[] = ['score' => $rsi >= 50 ? 1 : -1, 'text' => sprintf('RSI=%.1f', $rsi)];
            }
        }
        if (($hist = $t['macd_hist']) !== null) {
            $text = $hist > 0 ? 'MACD بالای خط سیگنال' : 'MACD زیر خط سیگنال';
            if ($t['macd_hist_prev'] !== null && ($hist > 0) !== ($t['macd_hist_prev'] > 0)) {
                $text .= ' (تقاطع تازه)';
            }
            $s[] = ['score' => $hist > 0 ? 1 : -1, 'text' => $text];
        }
        if ($t['bb_upper'] && $t['bb_lower']) {
            if ($close > $t['bb_upper']) {
                $s[] = ['score' => -1, 'text' => 'قیمت بالای باند بالایی بولینگر'];
            } elseif ($close < $t['bb_lower']) {
                $s[] = ['score' => 1, 'text' => 'قیمت زیر باند پایینی بولینگر'];
            }
        }
        if (($power = $flow['buyer_power'] ?? null) !== null) {
            $score = $power >= 1.2 ? 1 : ($power <= 0.8 ? -1 : 0);
            $s[] = ['score' => $score, 'text' => sprintf('قدرت خریدار حقیقی %.2f%s', $power,
                $score > 0 ? ' (ورود پول هوشمند)' : ($score < 0 ? ' (خروج پول)' : ''))];
        }
        if (($vr = $t['volume_ratio']) !== null && $vr >= 2) {
            $s[] = ['score' => 0, 'text' => sprintf('حجم امروز %.1f برابر میانگین ۲۰ روزه', $vr)];
        }
        return $s;
    }

    /** @param list<array{score: int, text: string}> $signals */
    public static function verdict(array $signals): string
    {
        if (!$signals) {
            return 'داده‌ی کافی نیست';
        }
        $ratio = array_sum(array_column($signals, 'score')) / count($signals);
        return match (true) {
            $ratio >= 0.5 => 'صعودی قوی',
            $ratio >= 0.15 => 'صعودی',
            $ratio > -0.15 => 'خنثی',
            $ratio > -0.5 => 'نزولی',
            default => 'نزولی قوی',
        };
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Signals;

final class Signal
{
    public const BUY = 'buy';
    public const SELL = 'sell';

    public ?int $id = null;

    /**
     * @param list<string> $reasons
     * @param array<string, mixed> $odds HistoricalStudy::tradeOdds()
     */
    public function __construct(
        public readonly string $insCode,
        public readonly string $symbol,
        public readonly string $side,
        public readonly int $at,
        public readonly float $entry,
        public readonly ?float $stop,
        public readonly ?float $target,
        public readonly ?float $probability,
        public readonly array $odds,
        public readonly ?float $score,
        public readonly array $reasons,
    ) {
    }

    public function label(): string
    {
        return $this->side === self::BUY ? 'سیگنال خرید' : 'سیگنال فروش';
    }

    public function rewardRisk(): ?float
    {
        if (!$this->stop || !$this->target || $this->side !== self::BUY || $this->entry <= $this->stop) {
            return null;
        }
        return ($this->target - $this->entry) / ($this->entry - $this->stop);
    }

    /** Plain-text message for messengers (Bale/Telegram). */
    public function message(string $siteName): string
    {
        $pct = static fn (?float $level) => $level ? sprintf(' (%s%%)', number_format(($level / $this->entry - 1) * 100, 1)) : '';
        $lines = [
            ($this->side === self::BUY ? '🟢 ' : '🔴 ') . $this->label() . ' — ' . $this->symbol,
            '',
            'قیمت فعلی: ' . number_format($this->entry) . ' ریال',
        ];
        if ($this->side === self::BUY) {
            $lines[] = 'هدف: ' . ($this->target ? number_format($this->target) . $pct($this->target) : '-');
            $lines[] = 'حد ضرر: ' . ($this->stop ? number_format($this->stop) . $pct($this->stop) : '-');
            if ($rr = $this->rewardRisk()) {
                $lines[] = sprintf('نسبت پاداش به ریسک: %.1f', $rr);
            }
        }
        if ($this->probability !== null) {
            $lines[] = '';
            $lines[] = $this->side === self::BUY
                ? sprintf('احتمال رسیدن به هدف پیش از حد ضرر: %d٪', round($this->probability * 100))
                : sprintf('احتمال ادامه‌ی افت: %d٪', round($this->probability * 100));
            $lines[] = sprintf('(بر پایه‌ی %d موقعیت %sتاریخی، افق %d جلسه)', $this->odds['samples'] ?? 0,
                !empty($this->odds['conditioned']) ? 'مشابه ' : '', $this->odds['horizon_days'] ?? 0);
        }
        if ($this->score !== null) {
            $lines[] = sprintf('امتیاز پتانسیل رشد: %d از ۱۰۰', round($this->score));
        }
        $lines[] = '';
        $lines[] = 'دلایل:';
        foreach ($this->reasons as $r) {
            $lines[] = '• ' . $r;
        }
        $lines[] = '';
        $lines[] = 'این پیام خروجی خودکار تحلیل است و توصیه‌ی قطعی خرید یا فروش نیست. — ' . $siteName;
        return implode("\n", $lines);
    }
}

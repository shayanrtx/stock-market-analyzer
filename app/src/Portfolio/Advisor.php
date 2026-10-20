<?php

declare(strict_types=1);

namespace Sabadgardan\Portfolio;

/**
 * Rule-based position actions (افزایش / نگهداری / کاهش / خروج تدریجی) with target weights.
 * Conviction comes from the growth-potential score, the primary trend, the historical odds and
 * reward/risk; size is limited by concentration, by each holding's share of portfolio risk and
 * by liquidity. Decision aid, not an order.
 */
final class Advisor
{
    public const MAX_DAYS_TO_EXIT = 5.0;
    public const RISK_OVERWEIGHT = 1.5;
    public const ACTIONS = ['add' => 'افزایش', 'hold' => 'نگهداری', 'trim' => 'کاهش', 'exit' => 'خروج تدریجی',
                            'halted' => 'متوقف'];
    public const ORDER = ['exit', 'trim', 'add', 'hold', 'halted'];

    public function __construct(private float $maxPositionWeight = 25.0)
    {
    }

    /** @return array{action: string, label: string, target_weight: float, reasons: list<string>} */
    public function advise(float $weight, ?float $score, ?float $probabilityUp, ?bool $aboveSma200, ?float $riskShare,
                           ?float $daysToExit, ?string $status, ?float $rewardRisk = null): array
    {
        $reasons = [];
        if ($status !== null && $status !== '' && $status !== 'مجاز') {
            return $this->result('halted', $weight, ["نماد «{$status}» است؛ تا بازگشایی امکان معامله نیست."]);
        }
        if ($score === null) {
            return $this->result('hold', $weight, ['داده‌ی کافی برای ارزیابی نیست.']);
        }
        $s = (int) round($score);
        if ($score >= 65 && $aboveSma200 !== false) {
            $action = 'add';
            $reasons[] = "امتیاز پتانسیل رشد $s و روند اولیه صعودی";
        } elseif ($score >= 50) {
            $action = 'hold';
            $reasons[] = "امتیاز پتانسیل رشد $s؛ دلیل قاطعی برای تغییر وزن نیست";
        } elseif ($score >= 38) {
            $action = 'trim';
            $reasons[] = "امتیاز پتانسیل رشد $s؛ کیفیت روند و جریان پول ضعیف است";
        } else {
            $action = 'exit';
            $reasons[] = "امتیاز پتانسیل رشد $s؛ ریسک افت بر پتانسیل رشد می‌چربد";
        }

        if ($probabilityUp !== null) {
            $p = (int) round($probabilityUp * 100);
            if ($probabilityUp < 0.4 && in_array($action, ['add', 'hold'], true)) {
                $action = $action === 'add' ? 'hold' : 'trim';
                $reasons[] = "احتمال تاریخی رشد ۳ ماهه فقط $p٪ است";
            } elseif ($probabilityUp >= 0.65 && $action === 'trim') {
                $action = 'hold';
                $reasons[] = "احتمال تاریخی رشد ۳ ماهه $p٪ است؛ فروش عجولانه توصیه نمی‌شود";
            }
        }
        if ($action === 'add' && $rewardRisk !== null && $rewardRisk < 1) {
            $action = 'hold';
            $reasons[] = sprintf('نسبت پاداش به ریسک %.1f برای خرید جدید کافی نیست؛ منتظر پولبک بمانید', $rewardRisk);
        }

        $tilt = ['add' => 1.3, 'hold' => 1.0, 'trim' => 0.6, 'exit' => 0.0][$action];
        $target = $weight ? $weight * $tilt : ($action === 'add' ? 3.0 : 0.0);
        if ($target > $this->maxPositionWeight) {
            $target = $this->maxPositionWeight;
            $reasons[] = sprintf('سقف وزن هر نماد %d٪ است (کنترل تمرکز)', $this->maxPositionWeight);
            if ($action === 'add') {
                $action = 'hold';
            }
            if ($weight > $this->maxPositionWeight) {
                $action = 'trim';
            }
        }
        if ($riskShare !== null && $weight > 0 && $riskShare / $weight > self::RISK_OVERWEIGHT && $action !== 'exit') {
            $target = min($target, $weight * 0.85);
            $reasons[] = sprintf('سهم این نماد از ریسک سبد %d٪ است، %.1f برابر وزنش', round($riskShare), $riskShare / $weight);
            if (in_array($action, ['add', 'hold'], true)) {
                $action = 'trim';
            }
        }
        if ($daysToExit !== null && $daysToExit > self::MAX_DAYS_TO_EXIT) {
            $reasons[] = sprintf('خروج کامل حدود %d جلسه طول می‌کشد (ریسک نقدشوندگی)', round($daysToExit));
            if ($action === 'add') {
                $target = min($target, $weight * 1.1);
            }
        }
        return $this->result($action, round($target, 2), $reasons);
    }

    /** @return array{action: string, label: string, target_weight: float, reasons: list<string>} */
    private function result(string $action, float $target, array $reasons): array
    {
        return ['action' => $action, 'label' => self::ACTIONS[$action], 'target_weight' => $target, 'reasons' => $reasons];
    }
}

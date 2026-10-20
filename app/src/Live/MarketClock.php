<?php

declare(strict_types=1);

namespace Sabadgardan\Live;

/**
 * Tehran Stock Exchange calendar and session phases.
 *
 * Tehran time is computed as UTC+3:30 arithmetically rather than through the host's tz database:
 * Iran dropped daylight saving in 2022 and shared hosts with stale tzdata would be an hour off.
 */
final class MarketClock
{
    public const OFFSET = 12600;  // +03:30
    /** Saturday..Wednesday (ISO-8601 N: Monday=1 .. Sunday=7). */
    public const TRADING_DAYS = [6, 7, 1, 2, 3];
    public const CONTINUOUS_MINUTES = 210;

    public const PRE_OPEN = 'pre_open';
    public const CONTINUOUS = 'continuous';
    public const CLOSING_GAP = 'closing_gap';
    public const TAL = 'tal';
    public const CLOSED = 'closed';

    public const LABELS = [
        self::PRE_OPEN => 'پیش‌گشایش (ثبت سفارش)',
        self::CONTINUOUS => 'معاملات پیوسته',
        self::CLOSING_GAP => 'محاسبه‌ی قیمت پایانی',
        self::TAL => 'معاملات در قیمت پایانی',
        self::CLOSED => 'بازار بسته',
    ];

    public static function local(int $ts, string $format): string
    {
        return gmdate($format, $ts + self::OFFSET);
    }

    /** Minutes since local midnight. */
    public static function minuteOfDay(int $ts): int
    {
        return intdiv(($ts + self::OFFSET) % 86400, 60);
    }

    public static function isTradingDay(int $ts): bool
    {
        return in_array((int) self::local($ts, 'N'), self::TRADING_DAYS, true);
    }

    /** ``$marketOpen = false`` (a holiday per tse.ir) forces CLOSED. */
    public static function phase(int $ts, ?bool $marketOpen = null): string
    {
        if ($marketOpen === false || !self::isTradingDay($ts)) {
            return self::CLOSED;
        }
        $m = self::minuteOfDay($ts);
        return match (true) {
            $m >= 8 * 60 + 45 && $m < 9 * 60 => self::PRE_OPEN,
            $m >= 9 * 60 && $m < 12 * 60 + 30 => self::CONTINUOUS,
            $m >= 12 * 60 + 30 && $m < 12 * 60 + 45 => self::CLOSING_GAP,
            $m >= 12 * 60 + 45 && $m < 13 * 60 => self::TAL,
            default => self::CLOSED,
        };
    }

    public static function isLive(string $phase): bool
    {
        return in_array($phase, [self::PRE_OPEN, self::CONTINUOUS, self::TAL], true);
    }

    /** Fraction (0..1) of the continuous session elapsed. */
    public static function sessionProgress(int $ts): float
    {
        return max(0.0, min(1.0, (self::minuteOfDay($ts) - 540) / self::CONTINUOUS_MINUTES));
    }

    /** Trading day key "Ymd" in Tehran time. */
    public static function day(int $ts): string
    {
        return self::local($ts, 'Ymd');
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

/** Persian (Jalali) <-> Gregorian conversion (arithmetic algorithm, valid for 1300-1500 AP). */
final class Jalali
{
    /** @return array{0: int, 1: int, 2: int} */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $gDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400)
            + $gd + $gDays[$gm - 1];
        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            return [$jy, 1 + intdiv($days, 31), 1 + $days % 31];
        }
        return [$jy, 7 + intdiv($days - 186, 30), 1 + ($days - 186) % 30];
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + 365 * $jy + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4) + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0;
        $months = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 1; $gm <= 12 && $gd > $months[$gm]; $gm++) {
            $gd -= $months[$gm];
        }
        return [$gy, $gm, $gd];
    }

    /** "2026-10-20" -> "1405/07/28" */
    public static function fromGregorianString(string $date): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($date, 0, 10)));
        [$jy, $jm, $jd] = self::fromGregorian($y, $m, $d);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /**
     * Parses a Jalali date typed by a user ("1405/7/28", "۱۴۰۵-۰۷-۲۸") into "Y-m-d" Gregorian.
     * Returns null for anything that isn't a real calendar date.
     */
    public static function parse(string $input): ?string
    {
        $input = Text::normalizeDigits(trim($input));
        if (!preg_match('#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})$#', $input, $m)) {
            return null;
        }
        [$jy, $jm, $jd] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > ($jm <= 6 ? 31 : 30)) {
            return null;
        }
        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $jd);
        // Round-trip check rejects 30 Esfand in non-leap years.
        if (self::fromGregorian($gy, $gm, $gd) !== [$jy, $jm, $jd]) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    public static function year(string $gregorian): int
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($gregorian, 0, 10)));
        return self::fromGregorian($y, $m, $d)[0];
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

final class Text
{
    /** Persian/Arabic digits and separators -> ASCII. */
    public static function normalizeDigits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7',
            '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5',
            '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٬' => ',', '٫' => '.',
        ]);
    }

    /** Unify Arabic/Persian letter variants (ي/ی، ك/ک) and drop ZWNJ/spaces, for symbol comparison. */
    public static function normalizeSymbol(string $s): string
    {
        return str_replace(['ي', 'ك', "\u{200C}", ' '], ['ی', 'ک', '', ''], trim($s));
    }

    /** "1,240" / "۱٬۲۴۰" / "1240.5" -> float, or null. */
    public static function parseNumber(string $s): ?float
    {
        $s = str_replace([',', ' '], '', self::normalizeDigits(trim($s)));
        return is_numeric($s) ? (float) $s : null;
    }
}

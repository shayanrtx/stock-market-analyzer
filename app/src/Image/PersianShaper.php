<?php

declare(strict_types=1);

namespace Sabadgardan\Image;

/**
 * Prepares Persian text for renderers without complex-script support (GD/FreeType):
 * 1) shaping: each letter becomes its contextual presentation form (isolated/final/initial/medial),
 *    including the lam-alef ligature; ZWNJ breaks the join,
 * 2) ordering: words are laid out right to left; runs of digits/latin stay left to right.
 */
final class PersianShaper
{
    /** letter => [isolated, final, initial, medial]; two forms = joins only to the previous letter. */
    private const FORMS = [
        'ء' => ['ﺀ'], 'آ' => ['ﺁ', 'ﺂ'], 'أ' => ['ﺃ', 'ﺄ'], 'ؤ' => ['ﺅ', 'ﺆ'], 'إ' => ['ﺇ', 'ﺈ'],
        'ئ' => ['ﺉ', 'ﺊ', 'ﺋ', 'ﺌ'], 'ا' => ['ﺍ', 'ﺎ'], 'ب' => ['ﺏ', 'ﺐ', 'ﺑ', 'ﺒ'], 'ة' => ['ﺓ', 'ﺔ'],
        'ت' => ['ﺕ', 'ﺖ', 'ﺗ', 'ﺘ'], 'ث' => ['ﺙ', 'ﺚ', 'ﺛ', 'ﺜ'], 'ج' => ['ﺝ', 'ﺞ', 'ﺟ', 'ﺠ'],
        'ح' => ['ﺡ', 'ﺢ', 'ﺣ', 'ﺤ'], 'خ' => ['ﺥ', 'ﺦ', 'ﺧ', 'ﺨ'], 'د' => ['ﺩ', 'ﺪ'], 'ذ' => ['ﺫ', 'ﺬ'],
        'ر' => ['ﺭ', 'ﺮ'], 'ز' => ['ﺯ', 'ﺰ'], 'س' => ['ﺱ', 'ﺲ', 'ﺳ', 'ﺴ'], 'ش' => ['ﺵ', 'ﺶ', 'ﺷ', 'ﺸ'],
        'ص' => ['ﺹ', 'ﺺ', 'ﺻ', 'ﺼ'], 'ض' => ['ﺽ', 'ﺾ', 'ﺿ', 'ﻀ'], 'ط' => ['ﻁ', 'ﻂ', 'ﻃ', 'ﻄ'],
        'ظ' => ['ﻅ', 'ﻆ', 'ﻇ', 'ﻈ'], 'ع' => ['ﻉ', 'ﻊ', 'ﻋ', 'ﻌ'], 'غ' => ['ﻍ', 'ﻎ', 'ﻏ', 'ﻐ'],
        'ف' => ['ﻑ', 'ﻒ', 'ﻓ', 'ﻔ'], 'ق' => ['ﻕ', 'ﻖ', 'ﻗ', 'ﻘ'], 'ك' => ['ﻙ', 'ﻚ', 'ﻛ', 'ﻜ'],
        'ل' => ['ﻝ', 'ﻞ', 'ﻟ', 'ﻠ'], 'م' => ['ﻡ', 'ﻢ', 'ﻣ', 'ﻤ'], 'ن' => ['ﻥ', 'ﻦ', 'ﻧ', 'ﻨ'],
        'ه' => ['ﻩ', 'ﻪ', 'ﻫ', 'ﻬ'], 'و' => ['ﻭ', 'ﻮ'], 'ي' => ['ﻱ', 'ﻲ', 'ﻳ', 'ﻴ'], 'پ' => ['ﭖ', 'ﭗ', 'ﭘ', 'ﭙ'],
        'چ' => ['ﭺ', 'ﭻ', 'ﭼ', 'ﭽ'], 'ژ' => ['ﮊ', 'ﮋ'], 'ک' => ['ﮎ', 'ﮏ', 'ﮐ', 'ﮑ'], 'گ' => ['ﮒ', 'ﮓ', 'ﮔ', 'ﮕ'],
        'ی' => ['ﯼ', 'ﯽ', 'ﯾ', 'ﯿ'],
    ];
    private const LAM_ALEF = ['ا' => ['ﻻ', 'ﻼ'], 'آ' => ['ﻵ', 'ﻶ'], 'أ' => ['ﻷ', 'ﻸ'], 'إ' => ['ﻹ', 'ﻺ']];
    private const ZWNJ = "\u{200C}";

    /** Shaping + right-to-left ordering, ready for imagettftext. */
    public static function visual(string $text): string
    {
        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            $out[] = self::isRtl($token) ? self::reverse(self::shape($token)) : $token;
        }
        // Keep adjacent left-to-right tokens (e.g. "12,000 ریال" keeps "12,000" intact) in their order.
        return implode('', array_reverse($out));
    }

    /** Contextual forms in logical order. */
    public static function shape(string $word): string
    {
        $chars = mb_str_split($word);
        $n = count($chars);
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            if (!isset(self::FORMS[$c])) {
                $out .= $c === self::ZWNJ ? '' : $c;
                continue;
            }
            $prevJoins = $i > 0 && self::joinsForward($chars[$i - 1]);
            if ($c === 'ل' && isset($chars[$i + 1], self::LAM_ALEF[$chars[$i + 1]])) {
                $out .= self::LAM_ALEF[$chars[$i + 1]][$prevJoins ? 1 : 0];
                $i++;
                continue;
            }
            $forms = self::FORMS[$c];
            $nextJoins = $i + 1 < $n && isset(self::FORMS[$chars[$i + 1]]) && count($forms) === 4;
            $out .= match (true) {
                $prevJoins && $nextJoins => $forms[3],
                $prevJoins => $forms[1] ?? $forms[0],
                $nextJoins => $forms[2],
                default => $forms[0],
            };
        }
        return $out;
    }

    private static function joinsForward(string $c): bool
    {
        return isset(self::FORMS[$c]) && count(self::FORMS[$c]) === 4;
    }

    private static function isRtl(string $token): bool
    {
        // Letters only: Persian/Arabic digits (U+06F0-06F9, U+0660-0669) are left-to-right runs.
        return (bool) preg_match('/[\x{0621}-\x{064A}\x{0671}-\x{06D3}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $token);
    }

    private static function reverse(string $s): string
    {
        return implode('', array_reverse(mb_str_split($s)));
    }
}

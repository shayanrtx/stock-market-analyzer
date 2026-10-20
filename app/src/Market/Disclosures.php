<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

/**
 * Supervisor messages (پیام ناظر) and Codal announcements merged into one timeline, newest first,
 * each tagged with what it means for a holder: a halt or reopening, a general meeting, a capital
 * increase, financial statements, the monthly activity report, or other.
 */
final class Disclosures
{
    public const KINDS = [
        'halt' => 'توقف / بازگشایی', 'agm' => 'مجمع', 'capital' => 'افزایش سرمایه', 'monthly' => 'گزارش فعالیت ماهانه',
        'financial' => 'صورت‌های مالی', 'dividend' => 'سود نقدی', 'other' => 'سایر',
    ];
    /** Keyword => kind, checked in order on the normalised title. */
    private const RULES = [
        'توقف' => 'halt', 'تعلیق' => 'halt', 'بازگشایی' => 'halt', 'ممنوع' => 'halt',
        'فعالیتماهانه' => 'monthly', 'افزایشسرمایه' => 'capital', 'مجمع' => 'agm',
        'سودنقدی' => 'dividend', 'تقسیمسود' => 'dividend', 'صورتهایمالی' => 'financial', 'صورتمالی' => 'financial',
        'اطلاعاتوصورت' => 'financial',
    ];

    /**
     * @param list<array<string, mixed>> $messages Msg/GetMsgByInsCode
     * @param list<array<string, mixed>> $announcements Codal/GetPreparedDataByInsCode
     * @return list<array{source: string, at: string, title: string, body: string, url: string, kind: string, important: bool}>
     */
    public static function merge(array $messages, array $announcements): array
    {
        $out = [];
        foreach ($messages as $m) {
            $at = self::fromDEven((string) Field::get($m, 'dEven', ''), (string) Field::get($m, 'hEven', ''));
            $title = trim((string) Field::get($m, 'tseTitle', ''));
            if ($at === '' || $title === '') {
                continue;
            }
            $out[] = self::item('ناظر', $at, $title, trim((string) Field::get($m, 'tseDesc', '')), '');
        }
        foreach ($announcements as $a) {
            $title = trim((string) Field::get($a, ['title', 'letterTitle'], ''));
            $at = self::parseDateTime((string) Field::get($a, ['publishDateTime', 'sentDateTime'], ''));
            if ($at === '' || $title === '') {
                continue;
            }
            $url = trim((string) Field::get($a, ['url', 'htmlUrl'], ''));
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url)) {
                $url = '';   // javascript:, data: and the like are never a link to follow
            } elseif ($url !== '' && !preg_match('#^https?://#i', $url)) {
                $url = 'https://www.codal.ir' . (str_starts_with($url, '/') ? '' : '/') . $url;
            }
            $out[] = self::item('کدال', $at, $title, '', $url);
        }
        usort($out, static fn ($a, $b) => strcmp($b['at'], $a['at']));
        return $out;
    }

    /** @return array{source: string, at: string, title: string, body: string, url: string, kind: string, important: bool} */
    private static function item(string $source, string $at, string $title, string $body, string $url): array
    {
        $kind = self::kind($title . ' ' . mb_substr($body, 0, 200));
        return ['source' => $source, 'at' => $at, 'title' => $title, 'body' => $body, 'url' => $url, 'kind' => $kind,
                'important' => in_array($kind, ['halt', 'capital', 'agm', 'dividend'], true)];
    }

    public static function kind(string $text): string
    {
        $key = str_replace(['ي', 'ك', "\u{200C}", ' ', '‌'], ['ی', 'ک', '', '', ''], $text);
        foreach (self::RULES as $word => $kind) {
            if (str_contains($key, $word)) {
                return $kind;
            }
        }
        return 'other';
    }

    /** YYYYMMDD + HHMMSS (Gregorian, Tehran time) → "Y-m-d H:i". */
    public static function fromDEven(string $day, string $time): string
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', trim($day), $d)) {
            return '';
        }
        $t = str_pad(preg_replace('/\D/', '', $time) ?? '', 6, '0', STR_PAD_LEFT);
        return sprintf('%s-%s-%s %s:%s', $d[1], $d[2], $d[3], substr($t, 0, 2), substr($t, 2, 2));
    }

    /** Accepts Jalali ("1405/07/20 14:22:10", Persian digits) or Gregorian/ISO date-times. */
    public static function parseDateTime(string $value): string
    {
        $v = trim(Text::normalizeDigits($value));
        if (!preg_match('#^(\d{4})[/-](\d{1,2})[/-](\d{1,2})(?:[ T](\d{1,2}):(\d{2}))?#', $v, $m)) {
            return '';
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($y < 1700) {
            [$y, $mo, $d] = Jalali::toGregorian($y, $mo, $d);
        }
        return sprintf('%04d-%02d-%02d %02d:%02d', $y, $mo, $d, (int) ($m[4] ?? 0), (int) ($m[5] ?? 0));
    }
}

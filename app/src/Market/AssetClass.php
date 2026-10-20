<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

/**
 * What an instrument actually is: share, rights, a fund by strategy (fixed income, equity, mixed,
 * leveraged, gold, commodity, real estate, venture), bond, option, commodity certificate, housing
 * facility. The kind decides the fee schedule, how the analysis treats the holding (a fixed-income
 * fund is cash-like, not a growth bet) and what the user is told about its role in the portfolio.
 *
 * Sources, strongest first (ENDPOINTS.md, «تشخیص نوع ابزار مالی و صنعت»):
 * ISIN prefix (IRO = share, IRR = rights, IRT = fund, IRB = bond, IRO9/IROF/IRS = option),
 * the TSE sub-sector (e.g. «صندوق هاي سرمايه گذاري اهرمي»), then keywords of the official name.
 */
final class AssetClass
{
    public const STOCK = 'stock';
    public const RIGHTS = 'rights';
    public const FUND_EQUITY = 'fund_equity';
    public const FUND_FIXED_INCOME = 'fund_fixed_income';
    public const FUND_MIXED = 'fund_mixed';
    public const FUND_LEVERAGED = 'fund_leveraged';
    public const FUND_GOLD = 'fund_gold';
    public const FUND_COMMODITY = 'fund_commodity';
    public const FUND_REAL_ESTATE = 'fund_real_estate';
    public const FUND_VENTURE = 'fund_venture';
    public const FUND_OTHER = 'fund';
    public const BOND = 'bond';
    public const OPTION_CALL = 'option_call';
    public const OPTION_PUT = 'option_put';
    public const CERTIFICATE = 'commodity_certificate';
    public const HOUSING = 'housing_facility';
    public const UNKNOWN = 'unknown';

    /** kind => [label, group, fee class, colour tone, role in a portfolio] */
    private const KINDS = [
        self::STOCK => ['سهام', 'سهام', 'stock', 'accent', 'سهم شرکت؛ بازده و ریسک به عملکرد شرکت و بازار گره خورده است.'],
        self::RIGHTS => ['حق تقدم', 'حق تقدم', 'stock', 'accent', 'حق خرید سهم جدید در افزایش سرمایه؛ برای تبدیل به سهم باید مبلغ اسمی (معمولاً ۱۰۰ تومان) پرداخت شود و نوسانش از سهم بیشتر است.'],
        self::FUND_EQUITY => ['صندوق سهامی', 'صندوق', 'fund', 'violet', 'سبد متنوع سهام با مدیریت حرفه‌ای؛ ریسک کمتر از تک‌سهم، هم‌جهت با بازار.'],
        self::FUND_FIXED_INCOME => ['صندوق درآمد ثابت', 'صندوق', 'fund', 'teal', 'معادل نقد سبد؛ سود نزدیک به سپرده با نوسان بسیار کم. برای نگهداری نقدینگی و کاهش ریسک، نه برای رشد.'],
        self::FUND_MIXED => ['صندوق مختلط', 'صندوق', 'fund', 'violet', 'ترکیب سهام و اوراق با درآمد ثابت؛ ریسک و بازده میانه.'],
        self::FUND_LEVERAGED => ['صندوق اهرمی', 'صندوق', 'fund', 'orange', 'اهرمی: در رونق و رکود بازار حدود ۱٫۵ تا ۲ برابر سهام نوسان می‌کند؛ مناسب کوتاه‌مدت و با کنترل ریسک.'],
        self::FUND_GOLD => ['صندوق طلا', 'صندوق کالایی', 'fund', 'gold', 'پشتوانه‌ی طلا؛ پوشش تورم و نرخ ارز با همبستگی پایین نسبت به سهام.'],
        self::FUND_COMMODITY => ['صندوق کالایی', 'صندوق کالایی', 'fund', 'gold', 'پشتوانه‌ی کالا (زعفران، نقره و …)؛ بازده به قیمت کالا وابسته است نه به سود شرکت‌ها.'],
        self::FUND_REAL_ESTATE => ['صندوق زمین و ساختمان', 'صندوق', 'fund', 'violet', 'سرمایه‌گذاری در پروژه‌های ساختمانی؛ کم‌نقدشونده‌تر و وابسته به بازار مسکن.'],
        self::FUND_VENTURE => ['صندوق جسورانه / پروژه', 'صندوق', 'fund', 'violet', 'سرمایه‌گذاری خصوصی یا پروژه‌ای؛ افق بلندمدت و نقدشوندگی پایین.'],
        self::FUND_OTHER => ['صندوق', 'صندوق', 'fund', 'violet', 'صندوق سرمایه‌گذاری قابل معامله.'],
        self::BOND => ['اوراق بدهی', 'اوراق', 'bond', 'teal', 'بازده تا سررسید مشخص است؛ ریسک اصلی تغییر نرخ بهره است.'],
        self::OPTION_CALL => ['اختیار خرید', 'اختیار معامله', 'option', 'orange', 'قرارداد اختیار خرید؛ اهرم بالا، سررسید دارد و ممکن است بی‌ارزش منقضی شود.'],
        self::OPTION_PUT => ['اختیار فروش', 'اختیار معامله', 'option', 'orange', 'قرارداد اختیار فروش؛ بیمه‌ی کاهش قیمت سهم پایه تا سررسید.'],
        self::CERTIFICATE => ['گواهی سپرده کالایی', 'گواهی کالایی', 'fund', 'gold', 'مالکیت کالای انبارشده (مثل سکه)؛ قیمت تابع بازار همان کالاست.'],
        self::HOUSING => ['تسهیلات مسکن', 'تسهیلات مسکن', 'stock', 'neutral', 'حق تقدم استفاده از تسهیلات مسکن؛ ارزش آن به اعتبار وام وابسته است.'],
        self::UNKNOWN => ['نامشخص', 'نامشخص', 'stock', 'neutral', ''],
    ];

    /** Fund strategy keywords (normalised, spaces removed), checked in this order. */
    private const FUND_KEYWORDS = [
        self::FUND_LEVERAGED => ['اهرم'],
        self::FUND_FIXED_INCOME => ['درامدثابت', 'بادرامد'],
        self::FUND_GOLD => ['طلا', 'سکه'],
        self::FUND_COMMODITY => ['کالایی', 'کالا', 'زعفران', 'نقره', 'پسته', 'پشتوانه'],
        self::FUND_REAL_ESTATE => ['زمینوساختمان', 'املاک', 'مستغلات'],
        self::FUND_VENTURE => ['جسورانه', 'پروژه', 'خصوصی'],
        self::FUND_MIXED => ['مختلط'],
        self::FUND_EQUITY => ['سهام', 'سهامی', 'شاخص', 'بخشی'],
    ];

    public function __construct(public readonly string $kind, public readonly string $board = '')
    {
    }

    public static function classify(string $isin, string $name = '', string $symbol = '', string $sector = '',
                                    string $subSector = '', string $board = ''): self
    {
        $isin = strtoupper(trim($isin));
        $text = self::key("$name $subSector $sector");
        // «صندوق» in a company name (e.g. a holding that invests for a pension fund) is not a fund:
        // trust the ISIN or the official sector, and the name only when nothing else is known.
        $fund = str_starts_with($isin, 'IRT') || str_contains(self::key("$subSector $sector"), 'صندوق')
            || ($isin === '' && $sector === '' && str_contains($text, 'صندوق'));
        $kind = match (true) {
            str_contains($text, 'تسهیلاتمسکن') => self::HOUSING,
            str_contains($text, 'گواهیسپرده') => self::CERTIFICATE,
            str_starts_with($isin, 'IRS'), str_starts_with($isin, 'IROF'), str_contains($text, 'اختیارف') => self::OPTION_PUT,
            str_starts_with($isin, 'IRO9'), str_contains($text, 'اختیارخ') => self::OPTION_CALL,
            str_starts_with($isin, 'IRR'), (bool) preg_match('/^ح\s*\./u', trim($name)) => self::RIGHTS,
            str_starts_with($isin, 'IRB') => self::BOND,
            $fund => self::fundKind($text),
            str_starts_with($isin, 'IRO') => self::STOCK,
            default => self::UNKNOWN,
        };
        return new self($kind, trim($board));
    }

    private static function fundKind(string $text): string
    {
        foreach (self::FUND_KEYWORDS as $kind => $words) {
            foreach ($words as $w) {
                if (str_contains($text, $w)) {
                    return $kind;
                }
            }
        }
        return self::FUND_OTHER;
    }

    /** Comparison key: Arabic letters to Persian, آ to ا, no spaces or zero-width joiners. */
    private static function key(string $s): string
    {
        $s = strtr($s, ['ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', "\u{200C}" => '', "\u{200F}" => '']);
        return (string) preg_replace('/\s+/u', '', $s);
    }

    /** @return list<string> every kind, for filters and tests */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    public function label(): string
    {
        return self::KINDS[$this->kind][0];
    }

    /** Broad family for allocation views: سهام، صندوق، صندوق کالایی، اوراق، … */
    public function group(): string
    {
        return self::KINDS[$this->kind][1];
    }

    /** Fee schedule: stock | fund | bond | option (FeeSchedule). */
    public function feeClass(): string
    {
        return self::KINDS[$this->kind][2];
    }

    /** CSS tone of the badge. */
    public function tone(): string
    {
        return self::KINDS[$this->kind][3];
    }

    /** One sentence on what this kind does in a portfolio. */
    public function role(): string
    {
        return self::KINDS[$this->kind][4];
    }

    public function isFund(): bool
    {
        return str_starts_with($this->kind, 'fund');
    }

    /** Cash-like holdings: their price path says nothing about growth potential. */
    public function isCashLike(): bool
    {
        return in_array($this->kind, [self::FUND_FIXED_INCOME, self::BOND], true);
    }

    public function isOption(): bool
    {
        return in_array($this->kind, [self::OPTION_CALL, self::OPTION_PUT], true);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'label' => $this->label(), 'group' => $this->group(), 'fee_class' => $this->feeClass(),
                'tone' => $this->tone(), 'board' => $this->board];
    }
}

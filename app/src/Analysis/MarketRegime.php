<?php

declare(strict_types=1);

namespace Sabadgardan\Analysis;

use Sabadgardan\Market\MarketData;
use Sabadgardan\Market\MarketException;

/**
 * The market's primary trend, from TEDPIX against its 200-session average, the direction of that
 * average, the distance from the one-year high, and (when tse.ir answers) today's breadth.
 * Most single-stock setups fail in a falling market, so the signal engine asks for more evidence
 * there (SignalEngine::thresholds) and the pages show the regime next to every buy idea.
 */
final class MarketRegime
{
    public const BULL = 'bull';
    public const NEUTRAL = 'neutral';
    public const BEAR = 'bear';
    public const LABELS = [self::BULL => 'صعودی', self::NEUTRAL => 'خنثی', self::BEAR => 'نزولی'];

    /**
     * @param array{last: ?float, sma200: ?float, slope_pct: ?float, drawdown_pct: ?float, return_3m_pct: ?float, breadth: ?float} $facts
     * @param list<string> $reasons
     */
    public function __construct(public readonly string $state, public readonly array $facts, public readonly array $reasons)
    {
    }

    /**
     * @param array<string, float> $index TEDPIX closes by date, oldest first
     * @param float|null $breadth share of today's advancing symbols among those that moved (0–1)
     */
    public static function assess(array $index, ?float $breadth = null): self
    {
        $values = array_values(array_filter($index, static fn ($v) => $v > 0));
        $n = count($values);
        $facts = ['last' => null, 'sma200' => null, 'slope_pct' => null, 'drawdown_pct' => null, 'return_3m_pct' => null,
                  'breadth' => $breadth];
        if ($n < 221) {
            return new self(self::NEUTRAL, $facts, ['تاریخچه‌ی شاخص کل برای تشخیص روند بازار کافی نیست.']);
        }
        $sma = Indicators::sma($values, 200);
        $last = $values[$n - 1];
        $facts['last'] = $last;
        $facts['sma200'] = $sma[$n - 1];
        $facts['slope_pct'] = ($sma[$n - 1] / $sma[$n - 21] - 1) * 100;           // a month of sessions
        $facts['drawdown_pct'] = ($last / max(array_slice($values, -250)) - 1) * 100;
        $facts['return_3m_pct'] = Stats::pctReturn($values, 63);

        $above = $last > $sma[$n - 1];
        $rising = $facts['slope_pct'] > 0;
        $reasons = [
            sprintf('شاخص کل %s میانگین ۲۰۰ روزه است (%s٪)', $above ? 'بالای' : 'زیر', self::signed(($last / $sma[$n - 1] - 1) * 100)),
            sprintf('میانگین ۲۰۰ روزه در یک ماه اخیر %s (%s٪)', $rising ? 'رو به بالاست' : 'رو به پایین است', self::signed($facts['slope_pct'])),
            sprintf('فاصله از سقف یک ساله %s٪', self::signed($facts['drawdown_pct'])),
        ];
        if ($facts['drawdown_pct'] <= -20 || (!$above && !$rising)) {
            $state = self::BEAR;
        } elseif ($above && $rising && $facts['drawdown_pct'] > -15) {
            $state = self::BULL;
        } else {
            $state = self::NEUTRAL;
        }
        if ($breadth !== null) {
            $reasons[] = sprintf('امروز %d٪ نمادهای پرتحرک مثبت‌اند', round($breadth * 100));
            if ($state === self::BULL && $breadth < 0.3) {
                $state = self::NEUTRAL;   // the index is carried by a few large caps
                $reasons[] = 'رشد شاخص به چند نماد بزرگ متکی است و بیشتر بازار همراهی نمی‌کند';
            }
        }
        return new self($state, $facts, $reasons);
    }

    /** TEDPIX history plus today's breadth from the official market watch (when reachable). */
    public static function fromMarket(MarketData $market): self
    {
        $breadth = null;
        try {
            $up = $down = 0;
            foreach ($market->gateway?->marketWatch('Cash') ?? [] as $item) {
                $row = MarketData::watchRow((array) $item);
                if ($row && $row['change'] > 0) {
                    $up++;
                } elseif ($row && $row['change'] < 0) {
                    $down++;
                }
            }
            $breadth = $up + $down >= 30 ? $up / ($up + $down) : null;
        } catch (MarketException) {
            // tse.ir answers Iranian IPs only; the index alone still decides the regime.
        }
        return self::assess($market->benchmark(), $breadth);
    }

    public function label(): string
    {
        return self::LABELS[$this->state];
    }

    /** CSS tone: up / neutral / down. */
    public function tone(): string
    {
        return ['bull' => 'up', 'neutral' => 'neutral', 'bear' => 'down'][$this->state];
    }

    public function isBear(): bool
    {
        return $this->state === self::BEAR;
    }

    /** One sentence for the pages. */
    public function summary(): string
    {
        return match ($this->state) {
            self::BULL => 'روند اولیه‌ی بازار صعودی است؛ موقعیت‌های خرید با احتمال بیشتری به هدف می‌رسند.',
            self::BEAR => 'روند اولیه‌ی بازار نزولی است؛ بیشتر سهم‌ها هم‌جهت با بازار افت می‌کنند. سیگنال خرید فقط با شواهد قوی‌تر صادر می‌شود و حفظ نقدینگی اولویت دارد.',
            default => 'بازار روند مشخصی ندارد؛ انتخاب نماد و رعایت حد ضرر از همیشه مهم‌تر است.',
        };
    }

    /** @return array{state: string, facts: array, reasons: list<string>} */
    public function toArray(): array
    {
        return ['state' => $this->state, 'facts' => $this->facts, 'reasons' => $this->reasons];
    }

    public static function fromArray(array $a): self
    {
        return new self((string) $a['state'], (array) $a['facts'], array_values((array) $a['reasons']));
    }

    private static function signed(?float $v): string
    {
        return $v === null ? '-' : ($v >= 0 ? '+' : '−') . number_format(abs($v), 1);
    }
}

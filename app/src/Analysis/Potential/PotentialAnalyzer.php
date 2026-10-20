<?php

declare(strict_types=1);

namespace Sabadgardan\Analysis\Potential;

use Sabadgardan\Analysis\Indicators;
use Sabadgardan\Analysis\MoneyFlow;
use Sabadgardan\Analysis\Stats;
use Sabadgardan\Market\Field;
use Sabadgardan\Market\PriceHistory;

/**
 * Growth-potential assessment (پتانسیل رشد): scores the factors a portfolio manager weighs
 * before adding to a position and explains each one.
 *
 * score = 50 + Σ contribution, contribution = 50 · score_f · weight_f / Σ weight.
 * Nominal returns in Iran are inflated, so returns are judged against the inflation hurdle and
 * earnings yield against the risk-free rate, not against zero.
 */
final class PotentialAnalyzer
{
    public function __construct(
        private float $inflationAnnual = 0.35,
        private float $riskFreeAnnual = 0.30,
    ) {
    }

    /**
     * @param PriceHistory $h adjusted daily history
     * @param array<string, mixed> $info GetInstrumentInfo record
     * @param array<string, ?float> $todayFlow MoneyFlow::fromRecord() for today
     * @param list<array<string, mixed>> $flowRecords daily client-type history
     * @param array<string, float> $benchmark index closes by date (Y-m-d)
     */
    public function assess(PriceHistory $h, array $info = [], array $todayFlow = [], array $flowRecords = [],
                           array $benchmark = []): PotentialResult
    {
        $c = $h->close;
        $n = count($c);
        $price = $c[$n - 1];
        $sma50 = Indicators::sma($c, 50);
        $sma200 = Indicators::sma($c, 200);
        $s50 = Stats::last($sma50);
        $s200 = Stats::last($sma200);
        $rsi = Stats::last(Indicators::rsi($c, 14));
        $atr = Stats::last(Indicators::atr($h->high, $h->low, $c, 14));
        $study = new HistoricalStudy($h, $this->inflationAnnual);
        $record = $study->record();
        $analogs = $study->analogs();
        $val = $this->valuation($info, $price);
        $flow = MoneyFlow::trend($flowRecords);
        $f = [];

        // --- technical: trend structure
        if ($s200) {
            $slope = $n > 220 && $sma200[$n - 21] ? ($s200 / $sma200[$n - 21] - 1) * 100 : 0.0;
            $gap = ($price / $s200 - 1) * 100;
            $above = $price > $s200;
            $trend = $above && $slope > 0 ? 'صعودی' : (!$above && $slope < 0 ? 'نزولی' : 'در حال تغییر');
            $f[] = new Factor('روند اولیه (Primary trend)', 0.6 * ($above ? 1 : -1) + 0.4 * Stats::clip($slope / 5), 2.0,
                sprintf('روند بلندمدت %s است: قیمت %s٪ %s میانگین متحرک ۲۰۰ روزه قرار دارد و شیب این میانگین در ۲۰ جلسه‌ی اخیر %s٪ بوده است.',
                    $trend, number_format(abs($gap)), $above ? 'بالای' : 'زیر', self::signed($slope, 1)));
        }
        if ($s50 && $s200) {
            $golden = $s50 > $s200;
            $f[] = new Factor('روند ثانویه', ($golden ? 0.5 : -0.5) + ($price > $s50 ? 0.5 : -0.5), 1.5,
                ($golden ? 'آرایش صعودی میانگین‌ها (SMA50 > SMA200، تقاطع طلایی برقرار است)'
                         : 'آرایش نزولی میانگین‌ها (SMA50 < SMA200، تقاطع مرگ برقرار است)')
                . ' و قیمت ' . ($price > $s50 ? 'بالای' : 'زیر') . ' میانگین ۵۰ روزه است.');
        }

        // --- technical: momentum in real terms
        if (($r6 = Stats::pctReturn($c, 126)) !== null) {
            $h6 = $study->hurdle(126);
            $real = ((1 + $r6 / 100) / (1 + $h6 / 100) - 1) * 100;
            $f[] = new Factor('مومنتوم واقعی ۶ ماهه', tanh($real / 20), 1.0,
                sprintf('بازده اسمی ۶ ماه %s٪ در برابر تورم دوره (~%d٪)؛ بازده واقعی %s٪ است%s.',
                    self::signed($r6, 1), round($h6), self::signed($real, 1),
                    $real > 0 ? ' و سهم ارزش خلق کرده' : ' و قدرت خرید را حفظ نکرده'));
        }

        // --- technical: relative strength against the index
        if (count($benchmark) > 63 && $n > 64) {
            $back = $h->dates[$n - 64];
            $bNow = $benchmark[$h->dates[$n - 1]] ?? end($benchmark);
            $bThen = self::valueOnOrBefore($benchmark, $back);
            if ($bNow && $bThen) {
                $rs = (($price / $c[$n - 64]) / ($bNow / $bThen) - 1) * 100;
                $f[] = new Factor('قدرت نسبی در برابر شاخص', Stats::clip($rs / 15), 1.0,
                    sprintf('بازده ۳ ماهه‌ی نماد %s واحد درصد %s از شاخص کل بوده است%s.', self::signed($rs, 1),
                        $rs > 0 ? 'بهتر' : 'ضعیف‌تر', $rs > 10 ? ' (رهبر بازار)' : ($rs < -10 ? ' (عقب‌مانده از بازار)' : '')));
            }
        }

        // --- technical: position in the 52-week range
        if ($s200) {
            $dd = ($price / $record['high_52w'] - 1) * 100;
            $up = $price > $s200;
            [$score, $text] = match (true) {
                $up && $dd > -5 => [0.6, 'قیمت در محدوده‌ی سقف ۵۲ هفته و در روند صعودی است؛ شکست سقف معمولاً با ادامه‌ی روند همراه است'],
                $up && $dd < -30 => [0.5, 'فاصله‌ی زیاد تا سقف ۵۲ هفته در حالی که روند اولیه صعودی شده؛ فضای بازگشت قیمت وجود دارد'],
                $up => [0.3, 'قیمت در میانه‌ی دامنه‌ی ۵۲ هفته و در روند صعودی است'],
                $dd < -40 => [-0.6, 'افت عمیق از سقف در حالی که روند نزولی ادامه دارد (ریسک «چاقوی در حال سقوط»)'],
                default => [-0.3, 'قیمت زیر سقف ۵۲ هفته و در روند نزولی است'],
            };
            $f[] = new Factor('موقعیت در دامنه‌ی ۵۲ هفته', $score, 1.0, sprintf('%s؛ فاصله تا سقف %d٪.', $text, round($dd)));
        }

        // --- technical: overextension / mean reversion
        if ($rsi !== null && $s50) {
            $ext = ($price / $s50 - 1) * 100;
            [$score, $text] = match (true) {
                $rsi > 75 || $ext > 25 => [-0.8, sprintf('کشش بیش از حد: RSI=%d و قیمت %s٪ از میانگین ۵۰ روزه فاصله دارد؛ احتمال اصلاح یا استراحت قیمت بالاست و ورود جدید ریسک زمان‌بندی دارد', round($rsi), self::signed($ext, 0))],
                $rsi < 35 && $s200 && $price > $s200 => [0.6, sprintf('پولبک در روند صعودی: RSI=%d؛ اصلاح قیمت در یک روند اولیه‌ی صعودی معمولاً بهترین نقطه‌ی ورود از نظر نسبت پاداش به ریسک است', round($rsi))],
                $rsi < 30 => [0.2, sprintf('اشباع فروش (RSI=%d)؛ احتمال برگشت کوتاه‌مدت، ولی روند اولیه تأییدش نمی‌کند', round($rsi))],
                default => [0.0, sprintf('کشش متعادل: RSI=%d و فاصله از میانگین ۵۰ روزه %s٪', round($rsi), self::signed($ext, 0))],
            };
            $f[] = new Factor('کشش قیمت', $score, 1.0, $text . '.');
        }

        // --- technical: accumulation / distribution
        if ($n > 25) {
            $up = $down = 0.0;
            for ($i = $n - 20; $i < $n; $i++) {
                $chg = $c[$i] - $c[$i - 1];
                if ($chg > 0) {
                    $up += $h->volume[$i];
                } elseif ($chg < 0) {
                    $down += $h->volume[$i];
                }
            }
            if ($up > 0 && $down > 0) {
                $ratio = $up / $down;
                $f[] = new Factor('انباشت / توزیع حجم', Stats::clip(log($ratio) / log(2)), 1.0,
                    sprintf('در ۲۰ جلسه‌ی اخیر حجم روزهای مثبت %.2f برابر روزهای منفی بوده است (%s).', $ratio,
                        $ratio > 1.2 ? 'نشانه‌ی انباشت سهم' : ($ratio < 0.8 ? 'نشانه‌ی توزیع سهم' : 'بدون برتری مشخص')));
            }
        }

        // --- technical: volatility regime (Bollinger squeeze)
        if ($n > 150) {
            $bb = Indicators::bollinger($c);
            $width = [];
            foreach ($bb['mid'] as $i => $mid) {
                if ($mid) {
                    $width[] = ($bb['upper'][$i] - $bb['lower'][$i]) / $mid;
                }
            }
            if (count($width) > 120) {
                $now = end($width);
                $window = array_slice($width, -120);
                $pct = count(array_filter($window, static fn ($w) => $w < $now)) / count($window);
                if ($pct < 0.2) {
                    $up = $s200 !== null && $price > $s200;
                    $f[] = new Factor('رژیم نوسان', $up ? 0.4 : -0.2, 0.5,
                        'فشردگی نوسان (پهنای باند بولینگر در ۲۰٪ پایین ۶ ماه اخیر)؛ معمولاً پیش‌درآمد یک حرکت قوی است و جهت روند اولیه ('
                        . ($up ? 'صعودی' : 'نزولی') . ') احتمال جهت شکست را بالا می‌برد.');
                }
            }
        }

        // --- money flow
        if ($flow) {
            $power = $flow['avg_buyer_power'];
            $net = $flow['individual_net_share'];
            $score = 0.6 * Stats::clip(($power - 1) / 0.5) + 0.4 * Stats::clip($net * 5);
            $f[] = new Factor('جریان پول حقیقی (۲۰ جلسه)', $score, 1.0,
                sprintf('میانگین قدرت خریدار حقیقی %.2f و خالص خرید حقیقی %s٪ از کل حجم؛ %s.', $power,
                    self::signed($net * 100, 1), $score > 0.2 ? 'ورود پول هوشمند و انتقال سهم از حقوقی به حقیقی'
                    : ($score < -0.2 ? 'خروج پول حقیقی و فشار عرضه' : 'تعادل نسبی بین خریدار و فروشنده')), Factor::FLOW);
        } elseif (!empty($todayFlow['buyer_power'])) {
            $power = $todayFlow['buyer_power'];
            $f[] = new Factor('جریان پول حقیقی (امروز)', 0.6 * Stats::clip(($power - 1) / 0.6), 0.5,
                sprintf('قدرت خریدار حقیقی امروز %.2f؛ داده‌ی تاریخی جریان پول در دسترس نبود.', $power), Factor::FLOW);
        }
        if ($n > 140) {
            $v20 = Stats::mean(array_slice($h->value, -20));
            $v120 = Stats::mean(array_slice($h->value, -120));
            if ($v120) {
                $change = $v20 / $v120 - 1;
                $f[] = new Factor('نقدشوندگی', Stats::clip($change / 0.5), 0.5,
                    sprintf('ارزش معاملات ۲۰ جلسه‌ی اخیر %s٪ نسبت به میانگین ۶ ماه؛ %s.', self::signed($change * 100, 0),
                        $change > 0.2 ? 'افزایش توجه بازار به نماد' : ($change < -0.2 ? 'کاهش توجه بازار' : 'نقدشوندگی پایدار')),
                    Factor::FLOW);
            }
        }

        // --- fundamental
        if ($val['negative_eps']) {
            $f[] = new Factor('ارزش‌گذاری', -0.8, 1.5, 'سود هر سهم (EPS) منفی است؛ قیمت پشتوانه‌ی سودآوری ندارد.',
                Factor::FUNDAMENTAL);
        } elseif ($val['pe']) {
            $parts = $scores = [];
            if ($val['pe_vs_sector']) {
                $ratio = $val['pe_vs_sector'];
                $scores[] = Stats::clip((1 - $ratio) / 0.4);
                $parts[] = sprintf('P/E=%.1f در برابر P/E صنعت %.1f (%s از صنعت به میزان %d٪)', $val['pe'],
                    $val['sector_pe'], $ratio < 1 ? 'ارزان‌تر' : 'گران‌تر', round(abs(1 - $ratio) * 100));
            }
            $ey = 100 / $val['pe'];
            $spread = $ey - $this->riskFreeAnnual * 100;
            $scores[] = Stats::clip($spread / 15);
            $parts[] = sprintf('بازده سود (E/P) %d٪ در برابر نرخ بدون ریسک %d٪ (%s)', round($ey),
                round($this->riskFreeAnnual * 100),
                $spread > 0 ? 'صرف ریسک مثبت' : 'صرف ریسک منفی؛ اوراق درآمد ثابت جذاب‌ترند');
            $f[] = new Factor('ارزش‌گذاری', array_sum($scores) / count($scores), 1.5, implode('؛ ', $parts) . '.',
                Factor::FUNDAMENTAL);
        }

        // --- statistical: historical analogs
        if ($analogs && isset($analogs['mean_pct'])) {
            $a = $analogs;
            $score = 0.5 * Stats::clip(($a['mean_pct'] - $a['hurdle_pct']) / 15)
                + 0.5 * Stats::clip(($a['beat_inflation_rate'] - 0.5) * 4);
            $f[] = new Factor('الگوهای مشابه تاریخی', $score, 2.0,
                sprintf('در %d موقعیت تاریخی با ساختار فنی مشابه، بازده ۳ ماه بعد به‌طور میانه %s٪ بوده؛ %d٪ موارد مثبت و %d٪ بالاتر از تورم دوره بسته شده‌اند (بازه‌ی ۲۰ تا ۸۰ درصدی: %s٪ تا %s٪).',
                    $a['samples'], self::signed($a['median_pct'], 1), round($a['win_rate'] * 100),
                    round($a['beat_inflation_rate'] * 100), self::signed($a['p20_pct'], 0), self::signed($a['p80_pct'], 0)),
                Factor::STATISTICAL);
        }

        $totalWeight = array_sum(array_map(static fn (Factor $x) => $x->weight, $f));
        foreach ($f as $x) {
            $x->contribution = $totalWeight ? 50 * $x->score * $x->weight / $totalWeight : 0.0;
        }
        $score = 50 + array_sum(array_map(static fn (Factor $x) => $x->contribution, $f));
        $levels = self::levels($h, $price, $s200, $record, $atr);
        $result = new PotentialResult(
            score: $score,
            label: self::label($score),
            confidence: self::confidence($n, $analogs, $val, count($f)),
            factors: $f,
            levels: $levels,
            history: $record,
            analogs: $analogs,
            valuation: $val,
            flow: $flow,
            scenarios: self::scenarios($price, $analogs, $atr),
            probabilityUp: $analogs && isset($analogs['mean_pct']) ? $analogs['win_rate'] : null,
            adjustments: $h->adjustments,
            sector: trim((string) Field::get(Field::get($info, 'sector', []), 'lSecVal', '')),
        );
        $result->summary = self::summary($result);
        return $result;
    }

    /** @return array<string, mixed> */
    public function valuation(array $info, float $price): array
    {
        $epsObj = Field::get($info, 'eps', []);
        $eps = Field::float($epsObj, ['epsValue', 'estimatedEPS']) ?? Field::float($info, ['epsValue', 'estimatedEPS']);
        $sectorPe = Field::float($epsObj, 'sectorPE') ?? Field::float($info, 'sectorPE');
        $shares = Field::float($info, 'zTitad');
        $pe = $eps !== null && $eps > 0 ? $price / $eps : null;
        return [
            'eps' => $eps,
            'pe' => $pe,
            'sector_pe' => $sectorPe,
            'pe_vs_sector' => $pe && $sectorPe && $sectorPe > 0 ? $pe / $sectorPe : null,
            'earnings_yield_pct' => $pe ? 100 / $pe : null,
            'market_cap' => $shares ? $price * $shares : null,
            'negative_eps' => $eps !== null && $eps <= 0,
        ];
    }

    /** @return array<string, ?float> */
    public static function levels(PriceHistory $h, float $price, ?float $s200, array $record, ?float $atr): array
    {
        $target = null;
        foreach ([$record['high_52w'], $record['ath']] as $level) {
            if ($price < $level * 0.97) {
                $target = $level;
                break;
            }
        }
        $low60 = min(array_slice($h->low, -60));
        $supports = array_filter([$low60, $s200], static fn ($x) => $x && $x < $price);
        $support = $supports ? max($supports) : null;
        // Structural stop 2% under the nearest support when that is within the 2.5 ATR risk budget;
        // otherwise the ATR stop, so a distant support can't make the risk unbounded.
        $candidates = array_filter([$atr ? $price - 2.5 * $atr : null, $support ? $support * 0.98 : null],
            static fn ($x) => $x && $x > 0);
        $stop = $candidates ? max($candidates) : null;
        $risk = $stop ? $price - $stop : ($support ? $price - $support : null);
        return [
            'price' => $price,
            'target' => $target,
            'upside_pct' => $target ? ($target / $price - 1) * 100 : null,
            'support' => $support,
            'downside_pct' => $support ? ($support / $price - 1) * 100 : null,
            'stop' => $stop,
            'stop_pct' => $stop ? ($stop / $price - 1) * 100 : null,
            'atr' => $atr,
            'reward_risk' => $target && $risk ? ($target - $price) / $risk : null,
        ];
    }

    /** Bear/base/bull prices ~3 months ahead: analog percentiles, else a ±1σ ATR band. */
    private static function scenarios(float $price, ?array $analogs, ?float $atr): ?array
    {
        if ($analogs && isset($analogs['mean_pct'])) {
            return ['bear' => $price * (1 + $analogs['p20_pct'] / 100), 'base' => $price * (1 + $analogs['median_pct'] / 100),
                    'bull' => $price * (1 + $analogs['p80_pct'] / 100), 'source' => 'analogs'];
        }
        if ($atr) {
            $move = $atr * sqrt(HistoricalStudy::HORIZON);
            return ['bear' => max($price - $move, 0), 'base' => $price, 'bull' => $price + $move, 'source' => 'volatility'];
        }
        return null;
    }

    private static function summary(PotentialResult $p): string
    {
        $parts = [sprintf('ارزیابی کلی: %s (امتیاز %d از ۱۰۰، اطمینان %s).', $p->label, round($p->score), $p->confidence)];
        if ($s = array_slice($p->strengths(), 0, 2)) {
            $parts[] = 'مهم‌ترین پشتوانه‌ها: ' . implode('، ', array_map(static fn ($x) => $x->name, $s)) . '.';
        }
        if ($w = array_slice($p->weaknesses(), 0, 2)) {
            $parts[] = 'مهم‌ترین ریسک‌ها: ' . implode('، ', array_map(static fn ($x) => $x->name, $w)) . '.';
        }
        if ($p->probabilityUp !== null && $p->scenarios) {
            $parts[] = sprintf('بر پایه‌ی رفتار تاریخی، احتمال بالاتر بودن قیمت پس از ۳ ماه حدود %d٪ است و سناریوی پایه قیمت %s ریال را نشان می‌دهد (بدبینانه %s، خوش‌بینانه %s).',
                round($p->probabilityUp * 100), number_format($p->scenarios['base']), number_format($p->scenarios['bear']),
                number_format($p->scenarios['bull']));
        }
        $lv = $p->levels;
        if ($lv['stop']) {
            $parts[] = sprintf('حد ضرر (زیر نزدیک‌ترین حمایت، حداکثر ۲.۵ ATR): %s ریال (%s٪).', number_format($lv['stop']),
                self::signed($lv['stop_pct'], 0));
        }
        if ($lv['reward_risk']) {
            $rr = $lv['reward_risk'];
            $parts[] = sprintf('نسبت پاداش به ریسک تا مقاومت بعدی %.1f است%s', $rr,
                $rr >= 2 ? '؛ از نظر مدیریت ریسک جذاب است.' : ($rr < 1 ? '؛ برای ورود جدید کافی نیست.' : '.'));
        }
        return implode(' ', $parts);
    }

    public static function label(float $score): string
    {
        return match (true) {
            $score >= 65 => 'پتانسیل رشد بالا',
            $score >= 55 => 'پتانسیل رشد متوسط',
            $score > 45 => 'نامشخص / خنثی',
            $score > 35 => 'پتانسیل رشد پایین',
            default => 'پتانسیل رشد ضعیف (ریسک افت)',
        };
    }

    private static function confidence(int $n, ?array $analogs, array $val, int $factors): string
    {
        $points = (int) ($n >= 2 * HistoricalStudy::TRADING_DAYS) + (int) ($analogs && isset($analogs['mean_pct']))
            + (int) ($val['pe'] !== null) + (int) ($factors >= 7);
        return $points >= 4 ? 'بالا' : ($points >= 2 ? 'متوسط' : 'پایین');
    }

    /** @param array<string, float> $series */
    private static function valueOnOrBefore(array $series, string $date): ?float
    {
        $value = null;
        foreach ($series as $d => $v) {
            if ($d > $date) {
                break;
            }
            $value = $v;
        }
        return $value;
    }

    public static function signed(float $v, int $digits): string
    {
        $r = round($v, $digits);
        return ($r > 0 ? '+' : '') . number_format($r, $digits);
    }
}

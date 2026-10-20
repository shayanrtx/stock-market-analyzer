<?php

declare(strict_types=1);

namespace Sabadgardan\Portfolio;

use Sabadgardan\Analysis\Potential\PotentialAnalyzer;
use Sabadgardan\Analysis\StockReport;
use Sabadgardan\Core\Settings;
use Sabadgardan\Market\AssetClass;
use Sabadgardan\Market\FundNav;
use Sabadgardan\Market\Instrument;
use Sabadgardan\Market\Jalali;
use Sabadgardan\Market\MarketData;
use Sabadgardan\Market\Rates;

/**
 * Portfolio views built from the ledger and market data:
 * - valuation(): P/L right now (fast: one price request per holding)
 * - performance(): value, cost basis and cumulative P/L over time, monthly P/L
 * - analytics(): growth potential, risk, liquidity, queue status and suggested actions
 */
final class PortfolioService
{
    public function __construct(
        private PortfolioRepository $repo,
        private MarketData $market,
        private Settings $settings,
        private ?Rates $rates = null,
        private array $multipliers = [],   // calibrated factor weights (PotentialAnalyzer::loadMultipliers)
    ) {
    }

    /** @return array<string, mixed> */
    public function valuation(int $userId, int $portfolioId): array
    {
        $txs = $this->repo->transactions($userId, $portfolioId);
        $ledger = new Ledger($txs);
        $open = $ledger->openPositions();
        $errors = [];
        $bundles = $this->market->bundles(array_column($open, 'ins_code'), MarketData::PARTS_PRICE, $errors);
        $fees = $this->settings->fees($userId);

        $rows = [];
        foreach ($open as $p) {
            $b = $bundles[$p['ins_code']] ?? null;
            $inst = $this->market->instrument($p['ins_code']);
            $class = $inst ? $inst->assetClass() : new AssetClass(AssetClass::UNKNOWN);
            $close = $b?->closePrice();
            $last = $b?->lastPrice();
            $ref = $b?->referencePrice();
            $qty = $p['quantity'];
            $value = $close !== null ? $close * $qty : null;
            $sellFee = $close !== null ? $fees->fee('sell', $class->feeClass(), $qty, $close) : null;
            $net = $value !== null ? $value - $sellFee : null;
            $rows[] = [
                'ins_code' => $p['ins_code'], 'symbol' => $p['symbol'], 'name' => $inst?->name ?? '', 'type' => $class->group(),
                'class' => $class->label(), 'class_kind' => $class->kind, 'class_tone' => $class->tone(),
                'quantity' => $qty, 'avg_cost' => $p['avg_cost'], 'cost' => $p['cost'],
                'last' => $last, 'close' => $close, 'reference' => $ref,
                'change_pct' => $close && $ref ? ($close / $ref - 1) * 100 : null,
                'last_change_pct' => $last && $ref ? ($last / $ref - 1) * 100 : null,
                'day_pnl' => $close && $ref ? ($close - $ref) * $qty : 0.0,
                'market_value' => $value, 'sell_fee' => $sellFee, 'net_value' => $net,
                'unrealized' => $net !== null ? $net - $p['cost'] : null,
                'unrealized_pct' => $net !== null && $p['cost'] > 0 ? ($net / $p['cost'] - 1) * 100 : null,
                'realized' => $p['realized'], 'dividends' => $p['dividends'], 'first_date' => $p['first_date'],
                'status' => $b?->status() ?? '', 'error' => $close === null ? 'قیمت در دسترس نیست' : '',
            ];
        }
        $t = $ledger->totals();
        $marketValue = array_sum(array_map(static fn ($r) => $r['market_value'] ?? 0.0, $rows));
        $netValue = array_sum(array_map(static fn ($r) => $r['net_value'] ?? 0.0, $rows));
        $costOfPriced = array_sum(array_map(static fn ($r) => $r['net_value'] !== null ? $r['cost'] : 0.0, $rows));
        $cash = $t['tracks_cash'] ? $t['cash'] : 0.0;
        $nav = $marketValue + max($cash, 0.0);
        foreach ($rows as &$r) {
            $r['weight'] = $nav > 0 && $r['market_value'] !== null ? $r['market_value'] / $nav * 100 : 0.0;
        }
        unset($r);
        usort($rows, static fn ($a, $b) => ($b['market_value'] ?? 0) <=> ($a['market_value'] ?? 0));
        $dayPnl = array_sum(array_column($rows, 'day_pnl'));
        $unrealized = $netValue - $costOfPriced;

        return [
            'positions' => $rows,
            'closed' => $ledger->closedPositions(),
            'transactions' => $txs,
            'totals' => [
                'market_value' => $marketValue,
                'net_value' => $netValue,
                'cost_basis' => $t['cost_basis'],
                'unrealized' => $unrealized,
                'unrealized_pct' => $costOfPriced > 0 ? $unrealized / $costOfPriced * 100 : null,
                'realized' => $t['realized'],
                'dividends' => $t['dividends'],
                'total_pnl' => $unrealized + $t['realized'] + $t['dividends'],
                'fees' => $t['fees'],
                'day_pnl' => $dayPnl,
                'day_pct' => $marketValue - $dayPnl > 0 ? $dayPnl / ($marketValue - $dayPnl) * 100 : null,
                'cash' => $cash,
                'tracks_cash' => $t['tracks_cash'],
                'invested' => $t['invested'],
                'nav' => $nav,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Daily value of the actual holdings (unadjusted prices × quantity held that day), cost basis and
     * cumulative P/L (unrealized + realized + dividends), plus P/L per Jalali month and, given the
     * user (for their inflation and risk-free assumptions), the return summary.
     *
     * @param list<Transaction> $txs
     * @return array<string, mixed>
     */
    public function performance(array $txs, ?int $userId = null): array
    {
        $trades = array_values(array_filter($txs, static fn (Transaction $t) => $t->insCode));
        if (!$trades) {
            return ['series' => [], 'monthly' => [], 'warnings' => [], 'returns' => null];
        }
        usort($txs, static fn ($a, $b) => [$a->date, $a->id] <=> [$b->date, $b->id]);
        $codes = array_values(array_unique(array_map(static fn (Transaction $t) => (string) $t->insCode, $trades)));
        $bundles = $this->market->bundles($codes, ['history']);
        $start = $txs[0]->date;

        $prices = [];
        $dates = [];
        foreach ($bundles as $code => $b) {
            foreach ($b->raw->dates as $i => $d) {
                if ($d >= $start) {
                    $prices[$code][$d] = $b->raw->close[$i];
                    $dates[$d] = true;
                }
            }
        }
        ksort($dates);
        $ledger = new Ledger([]);
        $last = [];
        $k = 0;
        $series = [];
        foreach (array_keys($dates) as $d) {
            while ($k < count($txs) && $txs[$k]->date <= $d) {
                $ledger->add($txs[$k++]);
            }
            foreach ($prices as $code => $byDate) {
                if (isset($byDate[$d])) {
                    $last[$code] = $byDate[$d];  // forward-filled across days without trades
                }
            }
            $value = 0.0;
            foreach ($ledger->openPositions() as $p) {
                $value += ($last[$p['ins_code']] ?? 0.0) * $p['quantity'];
            }
            $tot = $ledger->totals();
            $series[$d] = ['value' => $value, 'cost' => $tot['cost_basis'],
                           'pnl' => $value - $tot['cost_basis'] + $tot['realized'] + $tot['dividends']];
        }

        $monthly = [];
        $prevPnl = 0.0;
        $monthEnd = [];
        foreach ($series as $d => $row) {
            [$y, $m, $day] = array_map('intval', explode('-', $d));
            [$jy, $jm] = Jalali::fromGregorian($y, $m, $day);
            $monthEnd[sprintf('%04d/%02d', $jy, $jm)] = $row['pnl'];
        }
        foreach ($monthEnd as $month => $pnl) {
            $monthly[$month] = $pnl - $prevPnl;
            $prevPnl = $pnl;
        }

        return ['series' => $series, 'monthly' => $monthly, 'warnings' => $this->unrecordedCapitalIncreases($txs, $bundles),
                'returns' => $userId !== null ? $this->returnsSummary($userId, $txs, $series) : null];
    }

    /**
     * Time- and money-weighted returns of the holdings since the first purchase, next to what the
     * same period gave in TEDPIX, inflation (the user's assumption) and the risk-free rate.
     *
     * @param list<Transaction> $txs
     * @param array<string, array{value: float, cost: float, pnl: float}> $series
     * @return array<string, mixed>|null
     */
    private function returnsSummary(int $userId, array $txs, array $series): ?array
    {
        $firstTrade = min(array_map(static fn (Transaction $t) => $t->date,
            array_filter($txs, static fn (Transaction $t) => $t->type === Transaction::BUY)) ?: ['9999-12-31']);
        $series = array_filter($series, static fn ($d) => $d >= $firstTrade, ARRAY_FILTER_USE_KEY);
        if (count($series) < 2) {
            return null;
        }
        $twr = Returns::twrIndex(array_map(static fn ($r) => $r['value'], $series), $txs);
        $start = (string) array_key_first($series);
        $end = (string) array_key_last($series);
        $days = (strtotime($end) - strtotime($start)) / 86400;
        $total = end($twr) / 100 - 1;

        $bench = array_filter($this->market->benchmark(), static fn ($d) => $d >= $start && $d <= $end, ARRAY_FILTER_USE_KEY);
        $benchTotal = count($bench) >= 2 ? end($bench) / reset($bench) - 1 : null;
        $years = $days / 365;
        $inflation = $this->settings->float($userId, 'inflation_annual');
        $riskFree = $this->rates?->riskFree($userId) ?? $this->settings->float($userId, 'risk_free_annual');
        $row = static fn (?float $tot) => ['total' => $tot, 'annual' => $tot === null ? null : Returns::annualise($tot, $days)];
        // The free-market dollar over the same period: in a high-inflation economy the question is also
        // whether the portfolio kept its value in hard currency.
        $usd = $this->market->usd();
        $at = static function (array $series, string $date): ?float {
            $value = null;
            foreach ($series as $d => $v) {
                if ($d > $date) {
                    break;
                }
                $value = $v;
            }
            return $value;
        };
        $u0 = $usd ? $at($usd, $start) : null;
        $u1 = $usd ? $at($usd, $end) : null;
        $usdTotal = $u0 && $u1 ? $u1 / $u0 - 1 : null;
        return [
            'start' => $start, 'end' => $end, 'days' => $days,
            'twr' => $row($total),
            // XIRR is an annual rate by construction, so it follows the same minimum period.
            'xirr' => $days >= Returns::MIN_ANNUAL_DAYS ? Returns::xirr(Returns::investorFlows($txs, $end, $series[$end]['value'])) : null,
            'benchmark' => $row($benchTotal),
            'inflation' => ['total' => (1 + $inflation) ** $years - 1, 'annual' => $inflation],
            'risk_free' => ['total' => (1 + $riskFree) ** $years - 1, 'annual' => $riskFree],
            'usd' => $row($usdTotal),
            'twr_in_usd' => $usdTotal !== null ? (1 + $total) / (1 + $usdTotal) - 1 : null,
            'index' => $twr,
            'bench_index' => $bench,
        ];
    }

    /**
     * A capital increase after the user's purchase, without a recorded bonus transaction,
     * makes the holding look like a loss. Point it out.
     *
     * @param list<Transaction> $txs
     * @param array<string, \Sabadgardan\Market\SymbolBundle> $bundles
     * @return list<string>
     */
    private function unrecordedCapitalIncreases(array $txs, array $bundles): array
    {
        $ledger = new Ledger($txs);
        $warnings = [];
        foreach ($ledger->openPositions() as $p) {
            $b = $bundles[$p['ins_code']] ?? null;
            if (!$b) {
                continue;
            }
            foreach ($b->history->adjustments as [$date, $factor]) {
                if ($date <= $p['first_date'] || $factor > 0.9) {
                    continue;  // before the holding, or a small cash dividend
                }
                $hasBonus = (bool) array_filter($txs, static fn (Transaction $t) => $t->insCode === $p['ins_code']
                    && $t->type === Transaction::BONUS && $t->date >= $date);
                if (!$hasBonus) {
                    $warnings[] = sprintf('«%s» در تاریخ %s افزایش سرمایه داشته (ضریب %.2f) ولی سهام جایزه‌ای ثبت نشده؛ برای محاسبه‌ی درست سود و زیان، تراکنش «سهام جایزه» را ثبت کنید.',
                        $p['symbol'], Jalali::fromGregorianString($date), $factor);
                }
            }
        }
        return $warnings;
    }

    /** @return array<string, mixed> analysis for the open positions of a valuation */
    public function analytics(int $userId, array $valuation): array
    {
        $positions = array_values(array_filter($valuation['positions'], static fn ($r) => $r['market_value']));
        if (!$positions) {
            return ['positions' => [], 'risk' => null, 'sectors' => [], 'types' => [], 'concentration' => [], 'warnings' => []];
        }
        $riskFree = $this->rates?->riskFree($userId) ?? $this->settings->float($userId, 'risk_free_annual');
        $analyzer = new PotentialAnalyzer($this->settings->float($userId, 'inflation_annual'), $riskFree, $this->multipliers);
        $advisor = new Advisor($this->settings->float($userId, 'max_position_weight'));
        $risk = new RiskAnalyzer($riskFree);
        $benchmark = $this->market->benchmark();
        $errors = [];
        $bundles = $this->market->bundles(array_column($positions, 'ins_code'), MarketData::PARTS_FULL, $errors);

        $values = [];
        $out = [];
        foreach ($positions as $p) {
            $b = $bundles[$p['ins_code']] ?? null;
            $row = $p + ['potential' => null, 'report' => null, 'advice' => null, 'queue' => '', 'days_to_exit' => null,
                         'sector' => '', 'risk_share' => null, 'volatility_pct' => null, 'return_1y_pct' => null, 'premium' => null];
            if ($b) {
                $class = $b->assetClass();  // fresher than the registry: includes today's sub-sector
                $row = ['class' => $class->label(), 'class_kind' => $class->kind, 'class_tone' => $class->tone()] + $row;
                if ($class->isFund()) {
                    $row['premium'] = FundNav::premium($b->closePrice(), FundNav::fetch($this->market->cdn, $b->instrument, $class));
                }
            }
            if ($b && $b->history->count() > 1) {
                $report = StockReport::build($b, $analyzer, $benchmark);
                $row['report'] = $report;
                $row['potential'] = $report->potential;
                $row['sector'] = $b->sector() ?: ($report->potential->sector ?: 'نامشخص');
                $row['queue'] = $b->queue();
                $row['days_to_exit'] = RiskAnalyzer::liquidity($b->raw->value, (float) $p['market_value'])['days_to_exit'];
                $values[$p['symbol']] = array_combine($b->history->dates,
                    array_map(static fn ($c) => $c * $p['quantity'], $b->history->close));
            }
            $out[] = $row;
        }

        $riskResult = $risk->analyze($values, $benchmark);
        if ($riskResult) {
            // Balance risk among the risky holdings only: a fixed-income fund barely moves, so
            // equal risk would hand it nearly all the money.
            $risky = array_flip(array_column(array_filter($out, static fn ($r) => !(new AssetClass($r['class_kind'] ?? AssetClass::UNKNOWN))->isCashLike()), 'symbol'));
            $cov = array_map(static fn ($row) => array_intersect_key($row, $risky), array_intersect_key($riskResult['covariance'], $risky));
            $parity = count($cov) >= 2 ? RiskAnalyzer::riskParity($cov) : null;
            $riskResult['risk_parity'] = $parity ? array_map(static fn ($x) => $x * 100, $parity) : null;
        }
        $sectors = $types = [];
        foreach ($out as &$row) {
            if ($riskResult) {
                $row['risk_share'] = $riskResult['risk_contribution'][$row['symbol']] ?? null;
                $row['volatility_pct'] = $riskResult['per_symbol'][$row['symbol']]['volatility_annual_pct'] ?? null;
                $row['return_1y_pct'] = $riskResult['per_symbol'][$row['symbol']]['return_period_pct'] ?? null;
            }
            $pot = $row['potential'];
            $sma200 = $row['report']?->technical['sma200'] ?? null;
            $row['advice'] = $advisor->advise($row['weight'], $pot?->score, $pot?->probabilityUp,
                $sma200 ? $row['close'] > $sma200 : null, $row['risk_share'], $row['days_to_exit'], $row['status'],
                $pot?->levels['reward_risk'] ?? null, new AssetClass($row['class_kind']), $row['premium']);
            $sectors[$row['sector'] ?: 'نامشخص'] = ($sectors[$row['sector'] ?: 'نامشخص'] ?? 0) + $row['weight'];
            $types[$row['class']] = ($types[$row['class']] ?? 0) + $row['weight'];
        }
        unset($row);
        arsort($sectors);
        arsort($types);
        if ($valuation['totals']['cash'] > 0 && $valuation['totals']['nav'] > 0) {
            $types['نقد'] = $valuation['totals']['cash'] / $valuation['totals']['nav'] * 100;
        }

        return [
            'positions' => $out,
            'risk' => $riskResult,
            'sectors' => $sectors,
            'types' => $types,
            'concentration' => RiskAnalyzer::concentration(array_column($out, 'weight')),
            'warnings' => $this->warnings($out, $sectors, $userId),
            'errors' => $errors,
        ];
    }

    /** @return list<string> */
    private function warnings(array $positions, array $sectors, int $userId): array
    {
        $max = $this->settings->float($userId, 'max_position_weight');
        $w = [];
        foreach ($positions as $p) {
            $s = $p['symbol'];
            if ($p['status'] !== '' && $p['status'] !== 'مجاز') {
                $w[] = "«{$s}» {$p['status']} است.";
            }
            if ($p['queue'] === 'sell') {
                $w[] = "«{$s}» در صف فروش است؛ خروج فوری ممکن نیست.";
            }
            if ($p['weight'] > $max) {
                $w[] = sprintf('«%s» %d٪ از سبد است (بیش از سقف %d٪).', $s, round($p['weight']), $max);
            }
            if ($p['potential'] && $p['potential']->score <= 35) {
                $w[] = sprintf('«%s»: %s (امتیاز %d).', $s, $p['potential']->label, round($p['potential']->score));
            }
            if ($p['days_to_exit'] !== null && $p['days_to_exit'] > Advisor::MAX_DAYS_TO_EXIT) {
                $w[] = sprintf('خروج کامل از «%s» حدود %d جلسه طول می‌کشد (نقدشوندگی پایین).', $s, round($p['days_to_exit']));
            }
            if ($p['premium'] !== null && $p['premium'] > Advisor::PREMIUM_HIGH) {
                $w[] = sprintf('حباب «%s» %.1f٪ است؛ قیمت بالاتر از ارزش دارایی‌های صندوق است.', $s, $p['premium']);
            }
            $stop = $p['potential']?->levels['stop'] ?? null;
            if ($stop && $p['close'] && $p['close'] < $stop) {
                $w[] = "قیمت «{$s}» زیر حد ضرر است.";
            }
        }
        foreach ($sectors as $name => $weight) {
            if ($weight > 40) {
                $w[] = sprintf('صنعت «%s» %d٪ از سبد است (تمرکز صنعتی بالا).', $name, round($weight));
            }
        }
        return $w;
    }

    /** Builds a Transaction from a submitted form, computing the fee if left empty. */
    public function transactionFromInput(int $userId, array $in, ?int $id = null): Transaction
    {
        $type = (string) ($in['type'] ?? '');
        if (!isset(Transaction::LABELS[$type])) {
            throw new \DomainException('نوع تراکنش معتبر نیست.');
        }
        $date = Jalali::parse((string) ($in['date'] ?? ''));
        if ($date === null) {
            throw new \DomainException('تاریخ را به شکل ۱۴۰۵/۰۷/۲۸ وارد کنید.');
        }
        if ($date > date('Y-m-d')) {
            throw new \DomainException('تاریخ تراکنش نمی‌تواند در آینده باشد.');
        }
        $num = static fn (string $k) => \Sabadgardan\Market\Text::parseNumber((string) ($in[$k] ?? ''));
        $note = trim((string) ($in['note'] ?? ''));

        if (in_array($type, [Transaction::DEPOSIT, Transaction::WITHDRAW], true)) {
            $amount = $num('amount');
            if (!$amount || $amount <= 0) {
                throw new \DomainException('مبلغ را وارد کنید.');
            }
            return new Transaction($id, $type, $date, null, null, 0, 0, 0, $amount, $note);
        }

        $insCode = trim((string) ($in['ins_code'] ?? ''));
        $inst = $insCode !== '' ? $this->market->instrument($insCode) : null;
        if (!$inst) {
            throw new \DomainException('نماد را از فهرست جستجو انتخاب کنید.');
        }
        if ($type === Transaction::DIVIDEND) {
            $amount = $num('amount');
            if (!$amount || $amount <= 0) {
                throw new \DomainException('مبلغ سود نقدی را وارد کنید.');
            }
            return new Transaction($id, $type, $date, $inst->insCode, $inst->symbol, 0, 0, 0, $amount, $note);
        }
        $qty = $num('quantity');
        if (!$qty || $qty <= 0 || floor($qty) != $qty) {
            throw new \DomainException('تعداد سهم باید عدد صحیح مثبت باشد.');
        }
        if ($type === Transaction::BONUS) {
            return new Transaction($id, $type, $date, $inst->insCode, $inst->symbol, $qty, 0, 0, 0, $note);
        }
        $price = $num('price');
        if (!$price || $price <= 0) {
            throw new \DomainException('قیمت هر سهم (ریال) را وارد کنید.');
        }
        $fee = $num('fee');
        if ($fee === null) {
            $fee = $this->settings->fees($userId)->fee($type, $inst->assetClass()->feeClass(), $qty, $price);
        }
        return new Transaction($id, $type, $date, $inst->insCode, $inst->symbol, $qty, $price, max(0.0, $fee), 0, $note);
    }

    /**
     * Bulk import from text pasted from a broker table: ``symbol quantity [avg price]`` per line,
     * recorded as buys on ``$date``. Returns [imported count, list of errors].
     *
     * @return array{0: int, 1: list<string>}
     */
    public function import(int $userId, int $portfolioId, string $text, string $date): array
    {
        $count = 0;
        $errors = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $lineNo => $line) {
            $line = trim(\Sabadgardan\Market\Text::normalizeDigits($line));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([^\d]+?)[\s,;\t]+([\d,]+)(?:[\s;\t]+([\d,.]+))?/u', $line, $m)) {
                continue;  // header row or a line without numbers
            }
            $symbol = trim($m[1], " ,;\t");
            try {
                $inst = $this->market->resolve($symbol);
                $qty = (float) str_replace(',', '', $m[2]);
                $price = isset($m[3]) ? (float) str_replace(',', '', $m[3]) : 0.0;
                if ($price <= 0) {
                    $bundle = $this->market->bundles([$inst->insCode], MarketData::PARTS_PRICE)[$inst->insCode] ?? null;
                    $price = (float) ($bundle?->closePrice() ?? 0);
                }
                if ($qty <= 0 || $price <= 0) {
                    throw new \DomainException('تعداد یا قیمت نامعتبر');
                }
                $fee = $this->settings->fees($userId)->fee('buy', $inst->assetClass()->feeClass(), $qty, $price);
                $this->repo->saveTransaction($userId, $portfolioId,
                    new Transaction(null, Transaction::BUY, $date, $inst->insCode, $inst->symbol, $qty, $price, $fee, 0,
                        'ورود گروهی'));
                $count++;
            } catch (\Throwable $e) {
                $errors[] = sprintf('خط %d («%s»): %s', $lineNo + 1, $symbol, $e->getMessage());
            }
        }
        return [$count, $errors];
    }
}

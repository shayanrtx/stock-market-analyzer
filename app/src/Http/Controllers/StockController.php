<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Analysis\MoneyFlow;
use Sabadgardan\Analysis\Potential\HistoricalStudy;
use Sabadgardan\Analysis\Potential\PotentialAnalyzer;
use Sabadgardan\Analysis\StockReport;
use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Market\MarketData;
use Sabadgardan\Market\MarketException;
use Sabadgardan\Portfolio\Ledger;
use Sabadgardan\Signals\SignalEngine;

final class StockController extends Controller
{
    public function show(Request $q): Response
    {
        $code = $q->query('ins');
        $term = $q->query('s');
        $market = $this->app->market();
        try {
            if ($code === '' && $term !== '') {
                $code = $market->resolve($term)->insCode;
            }
            if ($code === '') {
                return $this->searchPage($q);
            }
            $errors = [];
            $bundle = $market->bundles([$code], MarketData::PARTS_FULL, $errors)[$code];
            if ($bundle->history->count() < 2) {
                throw new MarketException($errors[$code] ?? 'تاریخچه‌ی قیمت این نماد در دسترس نیست.');
            }
        } catch (MarketException $e) {
            return $this->searchPage($q, $e->getMessage(), $term);
        }
        $uid = $this->userId();
        $settings = $this->app->settings();
        $analyzer = new PotentialAnalyzer($settings->float($uid, 'inflation_annual'), $settings->float($uid, 'risk_free_annual'));
        $report = StockReport::build($bundle, $analyzer, $market->benchmark());
        $watched = (bool) array_filter($this->app->portfolios()->watchlist($uid), static fn ($w) => $w['ins_code'] === $code);
        return $this->view($q, 'stock/show', [
            'title' => $bundle->instrument->symbol . ' — تحلیل', 'r' => $report, 'watched' => $watched,
            'plan' => $this->tradePlan($report, $settings->float($uid, 'inflation_annual')),
            'flowDaily' => MoneyFlow::daily($bundle->flowRecords, 60),
            'holdings' => $this->holdings($uid, $code),
            'portfolio' => $this->app->portfolios()->defaultPortfolio($uid),
            'fees' => $settings->fees($uid)->all(),
            'nav' => 'stock', 'tour' => true,
        ]);
    }

    private function searchPage(Request $q, string $error = '', string $term = ''): Response
    {
        $uid = $this->userId();
        $repo = $this->app->portfolios();
        $quick = [];
        foreach ((new Ledger($repo->transactions($uid, (int) $repo->defaultPortfolio($uid)['id'])))->openPositions() as $p) {
            $quick[$p['ins_code']] = $p['symbol'];
        }
        foreach ($repo->watchlist($uid) as $w) {
            $quick[$w['ins_code']] ??= $w['symbol'];
        }
        return $this->view($q, 'stock/search', ['title' => 'تحلیل نماد', 'nav' => 'stock', 'error' => $error,
                                                'term' => $term, 'quick' => $quick]);
    }

    /**
     * Buy at the last close, stop and target from the potential levels; odds from comparable past sessions.
     *
     * @return array<string, mixed>|null
     */
    private function tradePlan(StockReport $r, float $inflation): ?array
    {
        $lv = $r->potential->levels;
        $price = (float) $lv['price'];
        $stop = $lv['stop'] ?? null;
        $target = $lv['target'] ?? null;
        if (!$stop || !$target || $price <= 0 || $stop >= $price || $target <= $price) {
            return null;
        }
        $targetPct = ($target / $price - 1) * 100;
        $stopPct = (1 - $stop / $price) * 100;
        $odds = (new HistoricalStudy($r->bundle->history, $inflation))
            ->tradeOdds($targetPct, $stopPct, SignalEngine::HORIZON, 1);
        return ['entry' => $price, 'stop' => $stop, 'target' => $target, 'target_pct' => $targetPct, 'stop_pct' => $stopPct,
                'reward_risk' => $lv['reward_risk'] ?? null, 'odds' => $odds];
    }

    /**
     * The user's open position in this symbol, per portfolio.
     *
     * @return list<array<string, mixed>>
     */
    private function holdings(int $uid, string $code): array
    {
        $repo = $this->app->portfolios();
        $out = [];
        foreach ($repo->portfolios($uid) as $p) {
            $position = (new Ledger($repo->transactions($uid, (int) $p['id'])))->position($code);
            if ($position && $position['quantity'] > 0) {
                $out[] = ['portfolio' => $p] + $position;
            }
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Analysis\Potential\PotentialAnalyzer;
use Sabadgardan\Analysis\StockReport;
use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Market\MarketData;
use Sabadgardan\Market\MarketException;

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
                return $this->view($q, 'stock/search', ['title' => 'تحلیل نماد', 'nav' => 'stock']);
            }
            $errors = [];
            $bundle = $market->bundles([$code], MarketData::PARTS_FULL, $errors)[$code];
            if ($bundle->history->count() < 2) {
                throw new MarketException($errors[$code] ?? 'تاریخچه‌ی قیمت این نماد در دسترس نیست.');
            }
        } catch (MarketException $e) {
            return $this->view($q, 'stock/search', ['title' => 'تحلیل نماد', 'nav' => 'stock', 'error' => $e->getMessage(),
                                                    'term' => $term]);
        }
        $uid = $this->userId();
        $settings = $this->app->settings();
        $analyzer = new PotentialAnalyzer($settings->float($uid, 'inflation_annual'), $settings->float($uid, 'risk_free_annual'));
        $report = StockReport::build($bundle, $analyzer, $market->benchmark());
        $watched = (bool) array_filter($this->app->portfolios()->watchlist($uid), static fn ($w) => $w['ins_code'] === $code);
        return $this->view($q, 'stock/show', ['title' => $bundle->instrument->symbol . ' — تحلیل', 'r' => $report,
                                              'watched' => $watched, 'nav' => 'stock', 'tour' => true]);
    }
}

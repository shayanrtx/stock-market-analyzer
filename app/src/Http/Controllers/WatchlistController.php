<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Analysis\Potential\PotentialAnalyzer;
use Sabadgardan\Analysis\StockReport;
use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Market\MarketData;

final class WatchlistController extends Controller
{
    public function index(Request $q): Response
    {
        $items = $this->app->portfolios()->watchlist($this->userId());
        $bundles = $this->app->market()->bundles(array_column($items, 'ins_code'), MarketData::PARTS_PRICE);
        return $this->view($q, 'watchlist', ['title' => 'دیده‌بان', 'items' => $items, 'bundles' => $bundles, 'nav' => 'watchlist',
            'alerts' => $this->app->alerts()->forUser($this->userId(), null, $this->app->now())]);
    }

    /** Growth-potential ranking of the watchlist, loaded asynchronously. */
    public function analysis(Request $q): Response
    {
        $uid = $this->userId();
        $items = $this->app->portfolios()->watchlist($uid);
        $market = $this->app->market();
        $settings = $this->app->settings();
        $analyzer = new PotentialAnalyzer($settings->float($uid, 'inflation_annual'), $this->app->rates()->riskFree($uid),
            $this->app->factorMultipliers());
        $benchmark = $market->benchmark();
        $rows = [];
        foreach ($market->bundles(array_column($items, 'ins_code'), ['history', 'info', 'flow', 'price', 'client']) as $code => $b) {
            if ($b->history->count() > 1) {
                $rows[] = ['bundle' => $b, 'report' => StockReport::build($b, $analyzer, $benchmark)];
            }
        }
        usort($rows, static fn ($a, $b) => $b['report']->potential->score <=> $a['report']->potential->score);
        return Response::html($this->app->view($q)->partial('watchlist_analysis', ['rows' => $rows]));
    }

    public function add(Request $q): Response
    {
        $code = $q->input('ins_code');
        $inst = $code !== '' ? $this->app->market()->instrument($code) : null;
        if (!$inst) {
            $this->flash('error', 'نماد را از فهرست جستجو انتخاب کنید.');
        } else {
            $this->app->portfolios()->watch($this->userId(), $inst->insCode, $inst->symbol);
            $this->flash('success', "«{$inst->symbol}» به دیده‌بان اضافه شد.");
        }
        $back = $q->input('back');
        return $this->redirect($q, str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/watchlist');
    }

    public function remove(Request $q): Response
    {
        $this->app->portfolios()->unwatch($this->userId(), $q->param('code'));
        $this->flash('success', 'از دیده‌بان حذف شد.');
        $back = $q->input('back');
        return $this->redirect($q, str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/watchlist');
    }
}

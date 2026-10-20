<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Market\MarketData;

final class WatchlistController extends Controller
{
    public function index(Request $q): Response
    {
        $items = $this->app->portfolios()->watchlist($this->userId());
        $bundles = $this->app->market()->bundles(array_column($items, 'ins_code'), MarketData::PARTS_PRICE);
        return $this->view($q, 'watchlist', ['title' => 'دیده‌بان', 'items' => $items, 'bundles' => $bundles, 'nav' => 'watchlist']);
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

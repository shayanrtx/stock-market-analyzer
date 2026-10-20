<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Market\MarketData;
use Sabadgardan\Market\MarketException;
use Sabadgardan\Market\TsetmcClient;

final class MarketController extends Controller
{
    public function show(Request $q): Response
    {
        $market = $this->app->market();
        $data = ['title' => 'نمای بازار', 'nav' => 'market', 'tour' => true, 'error' => null, 'bourse' => [], 'fara' => [],
                 'index' => [], 'watch' => [], 'effects' => []];
        try {
            $data['bourse'] = $market->cdn->indices(TsetmcClient::FLOW_BOURSE);
        } catch (MarketException $e) {
            $data['error'] = $e->getMessage();
            return $this->view($q, 'market/show', $data);
        }
        try {
            $data['fara'] = $market->cdn->indices(TsetmcClient::FLOW_FARABOURSE);
        } catch (MarketException) {
        }
        $data['index'] = $market->benchmark();
        try {
            $data['effects'] = $market->cdn->indexEffect(TsetmcClient::FLOW_BOURSE, 10);
        } catch (MarketException) {
        }
        try {
            foreach ($market->gateway?->marketWatch('Cash') ?? [] as $item) {
                if ($row = MarketData::watchRow((array) $item)) {
                    $data['watch'][] = $row;
                }
            }
        } catch (MarketException) {
            // tse.ir answers Iranian IPs only; the page works without it.
        }
        return $this->view($q, 'market/show', $data);
    }
}

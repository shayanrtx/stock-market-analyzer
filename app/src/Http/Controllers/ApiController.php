<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Market\MarketData;
use Sabadgardan\Market\MarketException;

final class ApiController extends Controller
{
    /** Symbol autocomplete. */
    public function search(Request $q): Response
    {
        $term = $q->query('q');
        if (mb_strlen($term) < 2) {
            return Response::json(['results' => []]);
        }
        try {
            return Response::json(['results' => $this->app->market()->search($term)]);
        } catch (MarketException $e) {
            return Response::json(['results' => [], 'error' => $e->getMessage()], 502);
        }
    }

    /** Current prices for the transaction form. */
    public function price(Request $q): Response
    {
        $code = $q->query('ins');
        if (!ctype_digit($code)) {
            return Response::json(['error' => 'شناسه‌ی نماد نامعتبر'], 422);
        }
        $b = $this->app->market()->bundles([$code], MarketData::PARTS_PRICE)[$code] ?? null;
        $inst = $this->app->market()->instrument($code);
        return Response::json(['last' => $b?->lastPrice(), 'close' => $b?->closePrice(), 'reference' => $b?->referencePrice(),
                               'type' => $inst?->type()]);
    }

    /** Live monitor: runs a cycle if due, then returns the user's view. */
    public function live(Request $q): Response
    {
        $live = $this->app->live();
        try {
            $tick = $live->tick();
        } catch (\Throwable $e) {
            $tick = ['ran' => false, 'error' => $e->getMessage()];
        }
        return Response::json($live->state($this->userId()) + ['tick' => $tick]);
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Market\MarketData;
use Sabadgardan\Market\MarketException;
use Sabadgardan\Market\Text;

/** Price alerts: created on a symbol's page, listed there and on the watchlist. */
final class AlertController extends Controller
{
    public function create(Request $q): Response
    {
        $back = $this->back($q);
        $inst = ($code = $q->input('ins_code')) !== '' ? $this->app->market()->instrument($code) : null;
        $price = Text::parseNumber($q->input('price'));
        $direction = $q->input('direction');
        if (!$inst || $price === null) {
            $this->flash('error', 'نماد و قیمت هشدار را درست وارد کنید.');
            return $this->redirect($q, $back);
        }
        // An alert that is already true would fire on the next cycle; say so instead.
        try {
            $last = $this->app->market()->bundles([$inst->insCode], MarketData::PARTS_PRICE)[$inst->insCode]->lastPrice();
        } catch (MarketException) {
            $last = null;
        }
        if ($last && (($direction === 'above' && $last >= $price) || ($direction === 'below' && $last <= $price))) {
            $this->flash('error', sprintf('آخرین قیمت «%s» (%s) همین حالا %s این عدد است.', $inst->symbol, number_format($last),
                $direction === 'above' ? 'بالاتر از' : 'پایین‌تر از'));
            return $this->redirect($q, $back);
        }
        $error = $this->app->alerts()->create($this->userId(), $inst->insCode, $inst->symbol, $direction, $price,
            $q->input('note'), $this->app->now());
        if ($error) {
            $this->flash('error', $error);
        } else {
            $linked = (bool) $this->app->notifier()->channels($this->userId());
            $this->flash('success', "هشدار قیمت «{$inst->symbol}» ثبت شد" . ($linked ? '؛ به پیام‌رسان شما ارسال می‌شود.'
                : '. برای دریافت آن در بله یا تلگرام، پیام‌رسان را در تنظیمات متصل کنید.'));
        }
        return $this->redirect($q, $back);
    }

    public function delete(Request $q): Response
    {
        $this->app->alerts()->delete($this->userId(), (int) $q->param('id'));
        $this->flash('success', 'هشدار حذف شد.');
        return $this->redirect($q, $this->back($q));
    }

    private function back(Request $q): string
    {
        $back = $q->input('back');
        return str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/watchlist';
    }
}

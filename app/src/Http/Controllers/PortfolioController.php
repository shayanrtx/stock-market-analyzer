<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Market\Jalali;
use Sabadgardan\Portfolio\Transaction;

final class PortfolioController extends Controller
{
    /** @return array<string, mixed>|null the requested (or default) portfolio, if owned */
    private function portfolio(Request $q): ?array
    {
        $id = $q->param('id');
        $repo = $this->app->portfolios();
        return $id === '' ? $repo->defaultPortfolio($this->userId()) : $repo->portfolio($this->userId(), (int) $id);
    }

    /** @return array<string, float> the user's fee rates, for the client-side fee preview */
    private function fees(): array
    {
        return $this->app->settings()->fees($this->userId())->all();
    }

    public function dashboard(Request $q): Response
    {
        $p = $this->portfolio($q);
        if (!$p) {
            return (new ErrorController($this->app))->notFound($q);
        }
        $service = $this->app->portfolioService();
        $valuation = $service->valuation($this->userId(), (int) $p['id']);
        $performance = $valuation['transactions'] ? $service->performance($valuation['transactions'], $this->userId()) : null;
        return $this->view($q, 'portfolio/dashboard', [
            'title' => $p['name'], 'portfolio' => $p, 'val' => $valuation, 'performance' => $performance,
            'nav' => 'portfolio', 'tour' => true, 'fees' => $this->fees(),
        ]);
    }

    /** Heavy analysis, loaded asynchronously into the dashboard. */
    public function analytics(Request $q): Response
    {
        $p = $this->portfolio($q);
        if (!$p) {
            return Response::html('', 404);
        }
        $service = $this->app->portfolioService();
        $valuation = $service->valuation($this->userId(), (int) $p['id']);
        $analytics = $service->analytics($this->userId(), $valuation);
        $view = $this->app->view($q);
        return Response::html($view->partial('portfolio/analytics', ['a' => $analytics, 'val' => $valuation, 'portfolio' => $p]));
    }

    public function transactions(Request $q): Response
    {
        $p = $this->portfolio($q);
        if (!$p) {
            return (new ErrorController($this->app))->notFound($q);
        }
        $txs = array_reverse($this->app->portfolios()->transactions($this->userId(), (int) $p['id']));
        $symbols = array_values(array_unique(array_filter(array_map(static fn (Transaction $t) => $t->symbol, $txs))));
        sort($symbols);
        $classes = [];
        foreach (array_unique(array_filter(array_map(static fn (Transaction $t) => (string) $t->insCode, $txs))) as $code) {
            $classes[$code] = $this->app->market()->instrument($code)?->assetClass();
        }
        $type = $q->query('type');
        $symbol = $q->query('symbol');
        if ($type !== '') {
            $txs = array_values(array_filter($txs, static fn (Transaction $t) => $t->type === $type));
        }
        if ($symbol !== '') {
            $txs = array_values(array_filter($txs, static fn (Transaction $t) => $t->symbol === $symbol));
        }
        return $this->view($q, 'portfolio/transactions', ['title' => 'تراکنش‌ها — ' . $p['name'], 'portfolio' => $p,
            'transactions' => $txs, 'symbols' => $symbols, 'classes' => $classes, 'filterType' => $type, 'filterSymbol' => $symbol, 'nav' => 'transactions',
            'fees' => $this->fees()]);
    }

    public function saveTransaction(Request $q): Response
    {
        $p = $this->portfolio($q);
        $ajax = $q->wantsJson();
        if (!$p) {
            return Response::json(['ok' => false, 'error' => 'سبد پیدا نشد.'], 404);
        }
        $id = $q->input('id') !== '' ? (int) $q->input('id') : null;
        try {
            $tx = $this->app->portfolioService()->transactionFromInput($this->userId(), $q->post, $id);
            $this->app->portfolios()->saveTransaction($this->userId(), (int) $p['id'], $tx);
        } catch (\DomainException $e) {
            if ($ajax) {
                return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            $this->flash('error', $e->getMessage());
            return $this->redirect($q, "/portfolio/{$p['id']}/transactions");
        }
        $message = sprintf('%s %s ثبت شد.', Transaction::LABELS[$tx->type], $tx->symbol ? '«' . $tx->symbol . '»' : '');
        $this->flash('success', $id ? 'تراکنش ویرایش شد.' : $message);
        return $ajax ? Response::json(['ok' => true]) : $this->redirect($q, "/portfolio/{$p['id']}");
    }

    public function deleteTransaction(Request $q): Response
    {
        $p = $this->portfolio($q);
        if (!$p) {
            return (new ErrorController($this->app))->notFound($q);
        }
        try {
            $deleted = $this->app->portfolios()->deleteTransaction($this->userId(), (int) $q->param('tx'));
            $this->flash($deleted ? 'success' : 'error', $deleted ? 'تراکنش حذف شد.' : 'تراکنش پیدا نشد.');
        } catch (\DomainException $e) {
            $this->flash('error', 'حذف ممکن نیست: ' . $e->getMessage());
        }
        return $this->redirect($q, "/portfolio/{$p['id']}/transactions");
    }

    public function import(Request $q): Response
    {
        $p = $this->portfolio($q);
        if (!$p) {
            return (new ErrorController($this->app))->notFound($q);
        }
        $date = Jalali::parse($q->input('date')) ?? date('Y-m-d');
        [$count, $errors] = $this->app->portfolioService()->import($this->userId(), (int) $p['id'], $q->input('text'), $date);
        if ($count) {
            $this->flash('success', "$count خرید از متن واردشده ثبت شد.");
        }
        foreach (array_slice($errors, 0, 8) as $e) {
            $this->flash('error', $e);
        }
        if (!$count && !$errors) {
            $this->flash('error', 'خطی با قالب «نماد تعداد [قیمت]» پیدا نشد.');
        }
        return $this->redirect($q, "/portfolio/{$p['id']}");
    }

    /** Ledger as CSV (UTF-8 with BOM so Excel shows Persian text correctly). */
    public function export(Request $q): Response
    {
        $p = $this->portfolio($q);
        if (!$p) {
            return (new ErrorController($this->app))->notFound($q);
        }
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['تاریخ', 'تاریخ میلادی', 'نوع', 'نماد', 'تعداد', 'قیمت (ریال)', 'کارمزد (ریال)', 'مبلغ (ریال)',
            'اثر بر نقدینگی (ریال)', 'یادداشت']);
        foreach ($this->app->portfolios()->transactions($this->userId(), (int) $p['id']) as $t) {
            fputcsv($out, [Jalali::fromGregorianString($t->date), $t->date, Transaction::LABELS[$t->type], $t->symbol ?? '',
                $t->quantity ?: '', $t->price ?: '', $t->fee ?: '', $t->amount ?: '', round($t->cashFlow()), $t->note]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $name = 'transactions-' . $p['id'] . '-' . date('Ymd') . '.csv';
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"']);
    }

    public function manage(Request $q): Response
    {
        return $this->view($q, 'portfolio/manage', ['title' => 'سبدهای من', 'nav' => 'portfolios']);
    }

    public function create(Request $q): Response
    {
        $id = $this->app->portfolios()->createPortfolio($this->userId(), $q->input('name'));
        $this->flash('success', 'سبد جدید ساخته شد.');
        return $this->redirect($q, "/portfolio/$id");
    }

    public function rename(Request $q): Response
    {
        $ok = $this->app->portfolios()->renamePortfolio($this->userId(), (int) $q->param('id'), $q->input('name'));
        $this->flash($ok ? 'success' : 'error', $ok ? 'نام سبد تغییر کرد.' : 'سبد پیدا نشد.');
        return $this->redirect($q, '/portfolios');
    }

    public function delete(Request $q): Response
    {
        try {
            $ok = $this->app->portfolios()->deletePortfolio($this->userId(), (int) $q->param('id'));
            $this->flash($ok ? 'success' : 'error', $ok ? 'سبد و تراکنش‌هایش حذف شد.' : 'سبد پیدا نشد.');
        } catch (\DomainException $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->redirect($q, '/portfolios');
    }
}

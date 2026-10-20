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

    public function dashboard(Request $q): Response
    {
        $p = $this->portfolio($q);
        if (!$p) {
            return (new ErrorController($this->app))->notFound($q);
        }
        $service = $this->app->portfolioService();
        $valuation = $service->valuation($this->userId(), (int) $p['id']);
        $performance = $valuation['transactions'] ? $service->performance($valuation['transactions']) : null;
        return $this->view($q, 'portfolio/dashboard', [
            'title' => $p['name'], 'portfolio' => $p, 'val' => $valuation, 'performance' => $performance,
            'nav' => 'portfolio', 'tour' => true,
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
        $type = $q->query('type');
        $symbol = $q->query('symbol');
        if ($type !== '') {
            $txs = array_values(array_filter($txs, static fn (Transaction $t) => $t->type === $type));
        }
        if ($symbol !== '') {
            $txs = array_values(array_filter($txs, static fn (Transaction $t) => $t->symbol === $symbol));
        }
        return $this->view($q, 'portfolio/transactions', ['title' => 'تراکنش‌ها — ' . $p['name'], 'portfolio' => $p,
            'transactions' => $txs, 'filterType' => $type, 'filterSymbol' => $symbol, 'nav' => 'transactions']);
    }

    public function saveTransaction(Request $q): Response
    {
        $p = $this->portfolio($q);
        $ajax = ($q->server['HTTP_ACCEPT'] ?? '') === 'application/json';
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

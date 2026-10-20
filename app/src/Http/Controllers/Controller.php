<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\App;
use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Http\Session;
use Sabadgardan\View\Glossary;

abstract class Controller
{
    public function __construct(protected App $app)
    {
    }

    /** @return array<string, mixed> */
    protected function user(): array
    {
        return (array) $this->app->auth()->user();
    }

    protected function userId(): int
    {
        return $this->app->auth()->id();
    }

    /** @param array<string, mixed> $data */
    protected function view(Request $q, string $template, array $data = [], ?string $layout = 'layout', int $status = 200): Response
    {
        $view = $this->app->view($q);
        $user = $this->app->auth()->user();
        $view->shared = [
            'user' => $user,
            'isAdmin' => $this->app->auth()->isAdmin(),
            'siteName' => $this->app->settings()->system('site_name'),
            'flashes' => Session::takeFlashes(),
            'glossary' => Glossary::ENTRIES,
            'portfolioList' => $user ? $this->app->portfolios()->portfolios((int) $user['id']) : [],
            'path' => $q->path,
            'demo' => $this->app->config->get('market.transport') === 'demo',
        ];
        return Response::html($view->render($template, $data, $layout), $status);
    }

    protected function redirect(Request $q, string $path, array $query = []): Response
    {
        return Response::redirect($this->app->view($q)->url($path, $query));
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    protected function forbidden(Request $q): Response
    {
        return (new ErrorController($this->app))->forbidden($q);
    }

    protected function audit(Request $q, string $action, string $detail = '', ?int $userId = null): void
    {
        $this->app->audit()->record($userId ?? ($this->userId() ?: null), $action, $detail, $q->ip());
    }
}

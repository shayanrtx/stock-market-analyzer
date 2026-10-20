<?php

declare(strict_types=1);

namespace Sabadgardan;

use Sabadgardan\Auth\Auth;
use Sabadgardan\Core\AuditLog;
use Sabadgardan\Core\Cache;
use Sabadgardan\Core\Config;
use Sabadgardan\Core\Database;
use Sabadgardan\Core\Migrations;
use Sabadgardan\Core\Settings;
use Sabadgardan\Http\Controllers as C;
use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Http\Router;
use Sabadgardan\Http\Session;
use Sabadgardan\Image\SignalChart;
use Sabadgardan\Live\LiveMonitor;
use Sabadgardan\Market\GatewayClient;
use Sabadgardan\Market\Http\CurlTransport;
use Sabadgardan\Market\Http\Transport;
use Sabadgardan\Market\MarketData;
use Sabadgardan\Market\TsetmcClient;
use Sabadgardan\Notify\NotificationService;
use Sabadgardan\Portfolio\PortfolioRepository;
use Sabadgardan\Portfolio\PortfolioService;
use Sabadgardan\View\View;

/**
 * Service container and front controller. Services are built lazily on first use, so a request
 * only pays for what it touches (e.g. the login page never opens a market client).
 */
final class App
{
    /** @var array<string, object> */
    private array $services = [];
    private ?Transport $transport = null;
    /** @var null|\Closure(): int */
    private ?\Closure $clock = null;
    /** @var array<string, float>|null */
    private ?array $multipliers = null;

    public function __construct(public readonly string $root, public readonly Config $config)
    {
    }

    public static function create(string $root): self
    {
        return new self($root, Config::load($root));
    }

    /** Injects a transport (tests, demo mode) instead of real HTTP. */
    public function withTransport(Transport $transport): self
    {
        $this->transport = $transport;
        return $this;
    }

    private ?string $storage = null;

    /** Writable runtime directory (cache, logs, lock, backups): storage/ unless redirected (tests). */
    public function storagePath(string $relative = ''): string
    {
        $base = $this->storage ?? $this->root . '/storage';
        return $relative === '' ? $base : $base . '/' . ltrim($relative, '/');
    }

    public function withStorage(string $dir): self
    {
        foreach (['', 'cache', 'logs', 'backups'] as $sub) {
            @mkdir(rtrim("$dir/$sub", '/'), 0775, true);
        }
        $this->storage = $dir;
        return $this;
    }

    /** Simulated time for the live monitor and date-relative views (tests, bin/demo.php). */
    public function withClock(callable $clock): self
    {
        $this->clock = \Closure::fromCallable($clock);
        return $this;
    }

    /** Current Unix time, simulated when a clock is set. */
    public function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }

    public function installed(): bool
    {
        return $this->config->exists();
    }

    // ------------------------------------------------------------- services

    private function make(string $id, callable $factory): object
    {
        return $this->services[$id] ??= $factory();
    }

    public function db(): Database
    {
        return $this->make('db', fn () => Database::fromConfig($this->config, $this->root));
    }

    public function setDatabase(Database $db): void
    {
        $this->services['db'] = $db;
    }

    /** Tests and simulations keep their cache out of storage/. */
    public function setCache(Cache $cache): void
    {
        $this->services['cache'] = $cache;
    }

    public function settings(): Settings
    {
        return $this->make('settings', fn () => new Settings($this->db()));
    }

    public function auth(): Auth
    {
        return $this->make('auth', fn () => new Auth($this->db()));
    }

    public function audit(): AuditLog
    {
        return $this->make('audit', fn () => new AuditLog($this->db()));
    }

    public function cache(): Cache
    {
        return $this->make('cache', fn () => new Cache($this->storagePath('cache')));
    }

    public function market(): MarketData
    {
        return $this->make('market', fn () => $this->buildMarket($this->cache()));
    }

    /** Market client that bypasses the cache: health checks must measure the live services. */
    public function uncachedMarket(): MarketData
    {
        return $this->buildMarket(Cache::disabled());
    }

    private function buildMarket(Cache $cache): MarketData
    {
        $transport = $this->transport ?? $this->make('transport', fn () => $this->config->get('market.transport') === 'demo'
            ? new \Sabadgardan\Market\Http\DemoTransport() : new CurlTransport());
        $timeout = (int) $this->config->get('market.timeout');
        $retries = (int) $this->config->get('market.max_retries');
        $cdn = new TsetmcClient($transport, $cache, $timeout, $retries);
        $gw = new GatewayClient($transport, $cache, $timeout, 1);
        return new MarketData($cdn, $gw, $this->db(), new \Sabadgardan\Market\TgjuClient($transport, $cache, $timeout, 1));
    }

    public function rates(): \Sabadgardan\Market\Rates
    {
        return $this->make('rates', fn () => new \Sabadgardan\Market\Rates($this->market(), $this->settings(), $this->cache(), $this->clock));
    }

    public function portfolios(): PortfolioRepository
    {
        return $this->make('portfolios', fn () => new PortfolioRepository($this->db()));
    }

    public function portfolioService(): PortfolioService
    {
        return $this->make('portfolioService', fn () => new PortfolioService($this->portfolios(), $this->market(), $this->settings(),
            $this->rates(), $this->factorMultipliers()));
    }

    /** @return array<string, float> calibrated factor multipliers from config/weights.json, or [] */
    public function factorMultipliers(): array
    {
        return $this->multipliers ??= \Sabadgardan\Analysis\Potential\PotentialAnalyzer::loadMultipliers($this->root . '/config/weights.json');
    }

    public function notifier(): NotificationService
    {
        return $this->make('notifier', function () {
            $fonts = $this->root . '/resources/fonts';
            $chart = new SignalChart("$fonts/Vazirmatn-FD-Regular.ttf", "$fonts/Vazirmatn-FD-Bold.ttf");
            return new NotificationService($this->db(), $this->settings(), $this->audit(), $chart);
        });
    }

    public function live(): LiveMonitor
    {
        return $this->make('live', fn () => new LiveMonitor($this->db(), $this->market(), $this->portfolios(), $this->settings(),
            $this->cache(), $this->notifier(), $this->storagePath('live.lock'), (int) $this->config->get('live.interval'),
            $this->clock, null, $this->rates(), $this->alerts(),
            new \Sabadgardan\Alerts\DisclosureWatch($this->db(), $this->market(), $this->cache()), $this->factorMultipliers()));
    }

    public function backup(): \Sabadgardan\Core\Backup
    {
        return $this->make('backup', fn () => new \Sabadgardan\Core\Backup($this->db(), $this->storagePath('backups')));
    }

    public function passwordReset(): \Sabadgardan\Auth\PasswordReset
    {
        return $this->make('password_reset', fn () => new \Sabadgardan\Auth\PasswordReset($this->db(), $this->notifier()));
    }

    public function alerts(): \Sabadgardan\Alerts\PriceAlerts
    {
        return $this->make('alerts', fn () => new \Sabadgardan\Alerts\PriceAlerts($this->db()));
    }

    public function view(Request $request): View
    {
        return $this->make('view', fn () => new View($this->root . '/views', $request->basePath));
    }

    // -------------------------------------------------------------- routing

    private function routes(): Router
    {
        $r = new Router();
        $c = fn (string $class) => new $class($this);

        // Public
        $r->get('/login', fn ($q) => $c(C\AuthController::class)->loginForm($q), false);
        $r->post('/login', fn ($q) => $c(C\AuthController::class)->login($q), false);
        $r->get('/forgot', fn ($q) => $c(C\AuthController::class)->forgotForm($q), false);
        $r->post('/forgot', fn ($q) => $c(C\AuthController::class)->forgot($q), false);
        $r->get('/reset', fn ($q) => $c(C\AuthController::class)->resetForm($q), false);
        $r->post('/reset', fn ($q) => $c(C\AuthController::class)->reset($q), false);
        $r->get('/register', fn ($q) => $c(C\AuthController::class)->registerForm($q), false);
        $r->post('/register', fn ($q) => $c(C\AuthController::class)->register($q), false);
        $r->post('/logout', fn ($q) => $c(C\AuthController::class)->logout($q));
        $r->get('/cron', fn ($q) => $c(C\CronController::class)->run($q), false);

        // Portfolio
        $r->get('/', fn ($q) => $c(C\PortfolioController::class)->dashboard($q));
        $r->get('/portfolio/{id}', fn ($q) => $c(C\PortfolioController::class)->dashboard($q));
        $r->get('/portfolio/{id}/analytics', fn ($q) => $c(C\PortfolioController::class)->analytics($q));
        $r->get('/portfolio/{id}/transactions', fn ($q) => $c(C\PortfolioController::class)->transactions($q));
        $r->post('/portfolio/{id}/transactions', fn ($q) => $c(C\PortfolioController::class)->saveTransaction($q));
        $r->post('/portfolio/{id}/transactions/{tx}/delete', fn ($q) => $c(C\PortfolioController::class)->deleteTransaction($q));
        $r->post('/portfolio/{id}/import', fn ($q) => $c(C\PortfolioController::class)->import($q));
        $r->get('/portfolio/{id}/export', fn ($q) => $c(C\PortfolioController::class)->export($q));
        $r->get('/portfolios', fn ($q) => $c(C\PortfolioController::class)->manage($q));
        $r->post('/portfolios', fn ($q) => $c(C\PortfolioController::class)->create($q));
        $r->post('/portfolios/{id}/rename', fn ($q) => $c(C\PortfolioController::class)->rename($q));
        $r->post('/portfolios/{id}/delete', fn ($q) => $c(C\PortfolioController::class)->delete($q));

        // Analysis
        $r->get('/stock', fn ($q) => $c(C\StockController::class)->show($q));
        $r->get('/market', fn ($q) => $c(C\MarketController::class)->show($q));
        $r->get('/watchlist', fn ($q) => $c(C\WatchlistController::class)->index($q));
        $r->get('/watchlist/analysis', fn ($q) => $c(C\WatchlistController::class)->analysis($q));
        $r->post('/watchlist', fn ($q) => $c(C\WatchlistController::class)->add($q));
        $r->post('/watchlist/{code}/delete', fn ($q) => $c(C\WatchlistController::class)->remove($q));
        $r->post('/alerts', fn ($q) => $c(C\AlertController::class)->create($q));
        $r->post('/alerts/{id}/delete', fn ($q) => $c(C\AlertController::class)->delete($q));
        $r->get('/live', fn ($q) => $c(C\LiveController::class)->show($q));
        $r->get('/signals', fn ($q) => $c(C\LiveController::class)->signals($q));

        // Settings
        $r->get('/settings', fn ($q) => $c(C\SettingsController::class)->show($q));
        $r->post('/settings/profile', fn ($q) => $c(C\SettingsController::class)->profile($q));
        $r->post('/settings/password', fn ($q) => $c(C\SettingsController::class)->password($q));
        $r->post('/settings/analysis', fn ($q) => $c(C\SettingsController::class)->analysis($q));
        $r->post('/settings/notifications', fn ($q) => $c(C\SettingsController::class)->notifications($q));
        $r->post('/settings/notifications/test', fn ($q) => $c(C\SettingsController::class)->testNotification($q));
        $r->post('/settings/notifications/check', fn ($q) => $c(C\SettingsController::class)->checkLink($q));
        $r->post('/settings/notifications/{channel}/unlink', fn ($q) => $c(C\SettingsController::class)->unlink($q));

        // API
        $r->get('/api/search', fn ($q) => $c(C\ApiController::class)->search($q));
        $r->get('/api/price', fn ($q) => $c(C\ApiController::class)->price($q));
        $r->get('/api/live', fn ($q) => $c(C\ApiController::class)->live($q));

        // Admin
        $r->get('/admin', fn ($q) => $c(C\AdminController::class)->dashboard($q));
        $r->get('/admin/users', fn ($q) => $c(C\AdminController::class)->users($q));
        $r->post('/admin/users/{id}/status', fn ($q) => $c(C\AdminController::class)->setStatus($q));
        $r->post('/admin/users/{id}/role', fn ($q) => $c(C\AdminController::class)->setRole($q));
        $r->post('/admin/users/{id}/password', fn ($q) => $c(C\AdminController::class)->resetPassword($q));
        $r->post('/admin/users/{id}/delete', fn ($q) => $c(C\AdminController::class)->deleteUser($q));
        $r->get('/admin/settings', fn ($q) => $c(C\AdminController::class)->settings($q));
        $r->post('/admin/settings', fn ($q) => $c(C\AdminController::class)->saveSettings($q));
        $r->get('/admin/audit', fn ($q) => $c(C\AdminController::class)->auditLog($q));
        $r->get('/admin/health', fn ($q) => $c(C\AdminController::class)->health($q));
        $r->post('/admin/health/cron', fn ($q) => $c(C\AdminController::class)->testCron($q));
        $r->post('/admin/backups', fn ($q) => $c(C\AdminController::class)->createBackup($q));
        $r->get('/admin/backups/{name}', fn ($q) => $c(C\AdminController::class)->downloadBackup($q));
        return $r;
    }

    public function handle(Request $request): Response
    {
        if (!$this->installed()) {
            return (new C\InstallController($this))->handle($request);
        }
        Session::start($request->isSecure());
        try {
            $migrations = new Migrations($this->db());
            if ($migrations->pending()) {
                $migrations->migrate();
            }
            $match = $this->routes()->match($request);
            if ($match === null) {
                return (new C\ErrorController($this))->notFound($request);
            }
            if ($match === false) {
                return Response::html('Method Not Allowed', 405);
            }
            if ($match['auth'] && !$this->auth()->check()) {
                return $request->wantsJson()
                    ? Response::json(['error' => 'برای ادامه وارد شوید.'], 401)
                    : Response::redirect($this->view($request)->url('login'));
            }
            if ($request->method === 'POST' && $request->path !== '/cron'
                && !Session::checkCsrf($request->input('_token', (string) ($request->server['HTTP_X_CSRF_TOKEN'] ?? '')))) {
                return $request->wantsJson()
                    ? Response::json(['error' => 'نشست منقضی شده است؛ صفحه را دوباره بارگذاری کنید.'], 419)
                    : (new C\ErrorController($this))->expired($request);
            }
            return ($match['handler'])($request);
        } catch (\Throwable $e) {
            return (new C\ErrorController($this))->server($request, $e);
        }
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }
}

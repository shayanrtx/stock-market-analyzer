<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Core\Database;
use Sabadgardan\Core\Migrations;
use Sabadgardan\Core\Settings;
use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Http\Session;

/** End-to-end requests through App::handle(): routing, auth, CSRF, isolation between accounts. */
final class HttpTest extends TestCase
{
    private Database $db;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->db = Database::memory();
        (new Migrations($this->db))->migrate();
    }

    /** @param array<string, string> $post */
    private function send(string $method, string $path, array $post = [], array $query = [], bool $json = false,
                          bool $csrf = true): Response
    {
        $app = $this->makeApp($this->db);
        if ($method === 'POST' && $csrf) {
            $post['_token'] = Session::csrfToken();
        }
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_HOST' => 'test.local',
                   'HTTP_ACCEPT' => $json ? 'application/json' : 'text/html'];
        return $app->handle(new Request($method, $path, $query, $post, $server));
    }

    private function user(string $username, bool $admin = false): int
    {
        return $this->makeApp($this->db)->auth()->createUser($username, 'password123', ucfirst($username), $admin);
    }

    private function login(string $username): void
    {
        $_SESSION = [];
        $r = $this->send('POST', '/login', ['username' => $username, 'password' => 'password123']);
        $this->assertSame(303, $r->status, 'login redirects');
    }

    private function portfolioId(int $uid): int
    {
        return (int) $this->db->value('SELECT id FROM portfolios WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]);
    }

    private function insCode(string $symbol): string
    {
        return $this->makeApp($this->db)->market()->resolve($symbol)->insCode;
    }

    public function testGuestsAreSentToLogin(): void
    {
        $r = $this->send('GET', '/');
        $this->assertSame(303, $r->status);
        $this->assertSame('/login', $r->headers['Location']);
        $this->assertSame(401, $this->send('GET', '/api/live', json: true)->status);
        $this->assertSame(200, $this->send('GET', '/login')->status);
    }

    public function testLoginShowsTheDashboard(): void
    {
        $this->user('sara');
        $bad = $this->send('POST', '/login', ['username' => 'sara', 'password' => 'wrong-password']);
        $this->assertContains('اشتباه', $bad->body);
        $this->assertFalse(isset($_SESSION['user_id']));
        $this->login('sara');
        $r = $this->send('GET', '/');
        $this->assertSame(200, $r->status);
        $this->assertContains('سبد شما هنوز خالی است', $r->body);
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $this->user('sara');
        $this->login('sara');
        $r = $this->send('POST', '/portfolios', ['name' => 'x'], csrf: false);
        $this->assertSame(419, $r->status);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM portfolios'));
    }

    public function testAccountsCannotReachEachOthersData(): void
    {
        $a = $this->user('alice');
        $this->user('bob');
        $pa = $this->portfolioId($a);
        $this->login('alice');
        $code = $this->insCode('فملی');
        $ok = $this->send('POST', "/portfolio/$pa/transactions", ['type' => 'buy', 'ins_code' => $code, 'date' => '1405/07/01',
            'quantity' => '100', 'price' => '5000'], json: true);
        $this->assertSame(200, $ok->status, $ok->body);

        $this->login('bob');
        $this->assertSame(404, $this->send('GET', "/portfolio/$pa")->status);
        $this->assertSame(404, $this->send('GET', "/portfolio/$pa/transactions")->status);
        $this->assertSame(404, $this->send('GET', "/portfolio/$pa/analytics")->status);
        $this->assertSame(404, $this->send('GET', "/portfolio/$pa/export")->status);
        $this->assertSame(404, $this->send('POST', "/portfolio/$pa/transactions", ['type' => 'sell', 'ins_code' => $code,
            'date' => '1405/07/02', 'quantity' => '100', 'price' => '5000'], json: true)->status);
        $txId = (int) $this->db->value('SELECT id FROM transactions LIMIT 1');
        $this->send('POST', "/portfolio/$pa/transactions/$txId/delete");
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM transactions'), "bob cannot delete alice's trade");
    }

    public function testTransactionLifecycle(): void
    {
        $uid = $this->user('sara');
        $pid = $this->portfolioId($uid);
        $this->login('sara');
        $code = $this->insCode('فملی');
        $buy = $this->send('POST', "/portfolio/$pid/transactions", ['type' => 'buy', 'ins_code' => $code, 'date' => '۱۴۰۵/۰۷/۰۱',
            'quantity' => '۱,۰۰۰', 'price' => '5,000', 'fee' => ''], json: true);
        $this->assertSame('{"ok":true}', $buy->body);
        $this->assertNear(18560.0, (float) $this->db->value('SELECT fee FROM transactions'), 1e-9, 'fee from the stock schedule');

        $oversell = $this->send('POST', "/portfolio/$pid/transactions", ['type' => 'sell', 'ins_code' => $code,
            'date' => '1405/07/05', 'quantity' => '5000', 'price' => '6000'], json: true);
        $this->assertSame(422, $oversell->status);
        $this->assertContains('بیشتر از مانده', json_decode($oversell->body, true)['error']);

        $future = $this->send('POST', "/portfolio/$pid/transactions", ['type' => 'buy', 'ins_code' => $code,
            'date' => '1406/01/01', 'quantity' => '1', 'price' => '1'], json: true);
        $this->assertSame(422, $future->status);

        $fund = $this->insCode('کمند');
        $this->send('POST', "/portfolio/$pid/transactions", ['type' => 'buy', 'ins_code' => $fund, 'date' => '1405/07/02',
            'quantity' => '100', 'price' => '24000'], json: true);
        $this->assertNear(2784.0, (float) $this->db->value('SELECT fee FROM transactions WHERE ins_code = ?', [$fund]), 1e-9,
            'funds pay the fund fee rate');

        $page = $this->send('GET', "/portfolio/$pid/transactions");
        $this->assertContains('فملی', $page->body);
        $this->assertContains('صندوق درآمد ثابت', $page->body, 'instrument kind is shown');
        $csv = $this->send('GET', "/portfolio/$pid/export");
        $this->assertSame('text/csv; charset=utf-8', $csv->headers['Content-Type']);
        $this->assertContains('1405/07/01', $csv->body);

        $dash = $this->send('GET', "/portfolio/$pid");
        $this->assertSame(200, $dash->status);
        $this->assertContains('ارزش روز سبد', $dash->body);
        $analytics = $this->send('GET', "/portfolio/$pid/analytics");
        $this->assertContains('پیشنهادهای مدیریت سبد', $analytics->body);
    }

    public function testAdminAreaIsForAdminsOnly(): void
    {
        $this->user('sara');
        $this->user('root', true);
        $this->login('sara');
        $this->assertSame(403, $this->send('GET', '/admin')->status);
        $this->assertSame(403, $this->send('GET', '/admin/users')->status);
        $this->login('root');
        $this->assertSame(200, $this->send('GET', '/admin/users')->status);
    }

    public function testRegistrationModes(): void
    {
        $settings = $this->makeApp($this->db)->settings();
        $settings->set(Settings::SYSTEM, 'registration_approval', '1');
        $r = $this->send('POST', '/register', ['username' => 'newbie', 'email' => '', 'display_name' => 'تازه‌وارد',
            'password' => 'password123', 'password_confirm' => 'password123']);
        $this->assertContains('تأیید', $r->body);
        $this->assertSame('pending', (string) $this->db->value("SELECT status FROM users WHERE username = 'newbie'"));
        $login = $this->send('POST', '/login', ['username' => 'newbie', 'password' => 'password123']);
        $this->assertContains('در انتظار تأیید', $login->body);

        $bot = $this->send('POST', '/register', ['username' => 'spam', 'website' => 'http://x', 'password' => 'password123',
            'password_confirm' => 'password123']);
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM users WHERE username = 'spam'"), 'honeypot');
        $this->assertSame(303, $bot->status);

        $settings->set(Settings::SYSTEM, 'registration_open', '0');
        $closed = $this->send('GET', '/register');
        $this->assertNotContains('name="password_confirm"', $closed->body, 'no sign-up form while registration is closed');
        $this->assertSame(403, $this->send('POST', '/register', ['username' => 'late', 'password' => 'password123',
            'password_confirm' => 'password123'])->status);
    }

    public function testCronEndpointNeedsTheToken(): void
    {
        $this->assertSame(403, $this->send('GET', '/cron', query: ['token' => 'nope'])->status);
        $r = $this->send('GET', '/cron', query: ['token' => 'secret-token']);
        $this->assertSame(200, $r->status);
        $this->assertTrue(array_key_exists('live', json_decode($r->body, true)));
    }

    public function testHealthPageDiagnosesCron(): void
    {
        $this->user('root', true);
        $this->login('root');
        $before = $this->send('GET', '/admin/health');
        $this->assertContains('هنوز حتی یک بار به اسکریپت نرسیده', $before->body);
        $this->assertContains('/bin/cron.php', $before->body, 'shows the command to use');

        $test = $this->send('POST', '/admin/health/cron');
        $this->assertSame(303, $test->status);
        $this->assertContains('اجرای آزمایشی', $_SESSION['_flash'][0]['message']);
        $this->assertSame('', $this->makeApp($this->db)->settings()->system('last_cron_at'), 'a test run is not a working cron');

        \Sabadgardan\Http\Controllers\CronController::runJobs($this->makeApp($this->db));
        $after = $this->send('GET', '/admin/health');
        $this->assertContains('Cron درست کار می‌کند', $after->body);
    }

    public function testPriceAlertRoutes(): void
    {
        $alice = $this->user('alice');
        $this->user('bob');
        $code = $this->insCode('فملی');
        $this->login('alice');
        $r = $this->send('POST', '/alerts', ['ins_code' => $code, 'direction' => 'above', 'price' => '1', 'back' => "/stock?ins=$code"]);
        $this->assertSame(303, $r->status);
        $this->assertContains('همین حالا بالاتر از', $_SESSION['_flash'][0]['message'], 'an alert that is already true is refused');
        $_SESSION['_flash'] = [];
        $this->send('POST', '/alerts', ['ins_code' => $code, 'direction' => 'above', 'price' => '۹۹٬۰۰۰', 'note' => 'یادداشت-محرمانه-آلیس']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM price_alerts WHERE user_id = ?', [$alice]));
        $this->assertNear(99000.0, (float) $this->db->value('SELECT price FROM price_alerts'));
        $this->assertContains('یادداشت-محرمانه-آلیس', $this->send('GET', '/watchlist')->body, 'alice sees her alert');
        $id = (int) $this->db->value('SELECT id FROM price_alerts');

        $this->login('bob');
        $this->send('POST', "/alerts/$id/delete");
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM price_alerts'), "bob can't delete alice's alert");
        $this->assertNotContains('یادداشت-محرمانه-آلیس', $this->send('GET', '/watchlist')->body, 'nor see it');
        $this->login('alice');
        $this->send('POST', "/alerts/$id/delete");
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM price_alerts'));
    }

    public function testPasswordResetDoesNotRevealWhichAccountsExist(): void
    {
        $this->user('alice');
        $_SESSION = [];
        $known = $this->send('POST', '/forgot', ['username' => 'alice']);
        $unknown = $this->send('POST', '/forgot', ['username' => 'nobody']);
        $this->assertSame(200, $known->status);
        $this->assertSame($known->status, $unknown->status);
        $strip = static fn (string $html) => preg_replace(['/value="[^"]*"/', '/name="_token"[^>]*>/'], '', $html);
        $this->assertSame($strip($known->body), $strip($unknown->body), 'the same answer either way');
        $bad = $this->send('POST', '/reset', ['username' => 'alice', 'code' => '12345678', 'password' => 'newpassword9',
                                              'password_confirm' => 'newpassword9']);
        $this->assertSame(422, $bad->status);
        $this->assertContains('کد بازیابی نادرست یا منقضی است.', $bad->body);
        for ($i = 0; $i < 10; $i++) {
            $this->send('POST', '/forgot', ['username' => 'alice']);
        }
        $this->assertSame(429, $this->send('POST', '/forgot', ['username' => 'alice'])->status, 'one network is throttled');
    }

    /** Smoke test: every page renders for an admin with a real portfolio. */
    public function testEveryPageRenders(): void
    {
        $uid = $this->user('root', true);
        $pid = $this->portfolioId($uid);
        $this->login('root');
        foreach (['فملی' => 5000, 'عیار' => 30000, 'اهرم' => 12000] as $symbol => $price) {
            $this->send('POST', "/portfolio/$pid/transactions", ['type' => 'buy', 'ins_code' => $this->insCode($symbol),
                'date' => '1404/07/01', 'quantity' => '1000', 'price' => (string) $price], json: true);
        }
        $this->send('POST', '/watchlist', ['ins_code' => $this->insCode('فارس')]);
        $pages = ['/', "/portfolio/$pid/analytics", "/portfolio/$pid/transactions", '/portfolios', '/stock',
                  '/stock?s=' . rawurlencode('فملی'), '/stock?s=' . rawurlencode('کمند'), '/market', '/live', '/signals',
                  '/watchlist', '/watchlist/analysis', '/settings', '/admin', '/admin/users', '/admin/settings', '/admin/audit',
                  '/admin/health', '/forgot', '/reset'];
        foreach ($pages as $page) {
            [$path, $qs] = array_pad(explode('?', $page, 2), 2, '');
            parse_str($qs, $query);
            $r = $this->send('GET', $path, query: $query);
            $this->assertSame(200, $r->status, "GET $page");
            $this->assertNotContains('خطای غیرمنتظره', $r->body, "GET $page");
        }
        $stock = $this->send('GET', '/stock', query: ['s' => 'عیار']);
        $this->assertContains('صندوق طلا', $stock->body);
        foreach (['حباب (قیمت به NAV)', 'اطلاعیه‌ها و پیام‌های ناظر', 'رابطه با دلار آزاد', 'هشدار قیمت', 'بازه‌ی ۹۵٪'] as $card) {
            $this->assertContains($card, $stock->body, 'stock page');
        }
        $market = $this->send('GET', '/market')->body;
        foreach (['روند اولیه‌ی بازار', 'بازده اسناد خزانه', 'دلار آزاد'] as $card) {
            $this->assertContains($card, $market, 'market page');
        }
        $this->assertContains('بازده سبد در برابر شاخص، تورم و سود بدون ریسک', $this->send('GET', '/')->body);
        $this->assertContains('وزن برابری ریسک', $this->send('GET', "/portfolio/$pid/analytics")->body);
        $this->assertContains('پشتیبان پایگاه داده', $this->send('GET', '/admin/health')->body);
        $live = json_decode($this->send('GET', '/api/live', json: true)->body, true);
        $this->assertSame(4, count($live['symbols']));
        $search = json_decode($this->send('GET', '/api/search', query: ['q' => 'اهرم'], json: true)->body, true);
        $this->assertSame('صندوق اهرمی', $search['results'][0]['class']);
    }
}

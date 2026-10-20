<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Auth\Auth;
use Sabadgardan\Core\Migrations;
use Sabadgardan\Core\Settings;
use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Market\MarketException;
use Sabadgardan\Market\TsetmcClient;

/** Admin panel: overview, user management, system settings, audit log and health checks. */
final class AdminController extends Controller
{
    private function guard(Request $q): ?Response
    {
        return $this->app->auth()->isAdmin() ? null : $this->forbidden($q);
    }

    public function dashboard(Request $q): Response
    {
        if ($r = $this->guard($q)) {
            return $r;
        }
        $db = $this->app->db();
        $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        $stats = [
            'users' => (int) $db->value('SELECT COUNT(*) FROM users'),
            'active' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'pending' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'pending'"),
            'new_week' => (int) $db->value('SELECT COUNT(*) FROM users WHERE created_at > ?', [$since]),
            'logins_week' => (int) $db->value('SELECT COUNT(DISTINCT id) FROM users WHERE last_login_at > ?', [$since]),
            'portfolios' => (int) $db->value('SELECT COUNT(*) FROM portfolios'),
            'transactions' => (int) $db->value('SELECT COUNT(*) FROM transactions'),
            'signals_week' => (int) $db->value('SELECT COUNT(*) FROM signals WHERE created_at > ?', [time() - 7 * 86400]),
            'notifications_week' => (int) $db->value("SELECT COUNT(*) FROM notifications WHERE status = 'sent' AND created_at > ?", [time() - 7 * 86400]),
            'linked' => (int) $db->value('SELECT COUNT(DISTINCT user_id) FROM notification_channels'),
        ];
        $signups = $db->all('SELECT substr(created_at, 1, 10) AS d, COUNT(*) AS n FROM users WHERE created_at > ? GROUP BY d ORDER BY d',
            [date('Y-m-d', time() - 30 * 86400)]);
        return $this->view($q, 'admin/dashboard', ['title' => 'مدیریت', 'nav' => 'admin', 'stats' => $stats, 'signups' => $signups,
            'lastCron' => $this->app->settings()->system('last_cron_at'), 'recent' => $this->app->audit()->recent(12)]);
    }

    public function users(Request $q): Response
    {
        if ($r = $this->guard($q)) {
            return $r;
        }
        $term = $q->query('q');
        $status = $q->query('status');
        $sql = 'SELECT u.*, (SELECT COUNT(*) FROM portfolios p WHERE p.user_id = u.id) AS portfolios,
                (SELECT COUNT(*) FROM transactions t JOIN portfolios p ON p.id = t.portfolio_id WHERE p.user_id = u.id) AS transactions,
                (SELECT COUNT(*) FROM notification_channels c WHERE c.user_id = u.id) AS channels
                FROM users u WHERE 1 = 1';
        $params = [];
        if ($term !== '') {
            $sql .= ' AND (u.username LIKE ? OR u.display_name LIKE ? OR u.email LIKE ?)';
            $params = array_fill(0, 3, '%' . $term . '%');
        }
        if (isset(Auth::STATUS_LABELS[$status])) {
            $sql .= ' AND u.status = ?';
            $params[] = $status;
        }
        $users = $this->app->db()->all($sql . ' ORDER BY u.id DESC LIMIT 500', $params);
        return $this->view($q, 'admin/users', ['title' => 'کاربران', 'nav' => 'admin', 'users' => $users, 'term' => $term,
                                               'status' => $status]);
    }

    public function setStatus(Request $q): Response
    {
        if ($r = $this->guard($q)) {
            return $r;
        }
        $id = (int) $q->param('id');
        $status = $q->input('status');
        if (!isset(Auth::STATUS_LABELS[$status]) || $id === $this->userId()) {
            $this->flash('error', 'این تغییر مجاز نیست (وضعیت حساب خودتان را نمی‌توانید تغییر دهید).');
        } else {
            $this->app->db()->update('users', ['status' => $status], ['id' => $id]);
            $this->audit($q, 'admin_user_status', "user #$id → $status");
            $this->flash('success', 'وضعیت کاربر به «' . Auth::STATUS_LABELS[$status] . '» تغییر کرد.');
        }
        return $this->redirect($q, '/admin/users');
    }

    public function setRole(Request $q): Response
    {
        if ($r = $this->guard($q)) {
            return $r;
        }
        $id = (int) $q->param('id');
        $admin = $q->input('admin') === '1';
        $admins = (int) $this->app->db()->value('SELECT COUNT(*) FROM users WHERE is_admin = 1');
        if ($id === $this->userId() && !$admin && $admins <= 1) {
            $this->flash('error', 'سامانه دست‌کم یک مدیر لازم دارد.');
        } elseif ($id === $this->userId()) {
            $this->flash('error', 'نقش حساب خودتان را نمی‌توانید تغییر دهید.');
        } else {
            $this->app->db()->update('users', ['is_admin' => $admin ? 1 : 0], ['id' => $id]);
            $this->audit($q, 'admin_user_role', "user #$id → " . ($admin ? 'admin' : 'user'));
            $this->flash('success', $admin ? 'کاربر مدیر شد.' : 'دسترسی مدیر برداشته شد.');
        }
        return $this->redirect($q, '/admin/users');
    }

    public function resetPassword(Request $q): Response
    {
        if ($r = $this->guard($q)) {
            return $r;
        }
        $id = (int) $q->param('id');
        $password = (string) ($q->post['password'] ?? '');
        if ($e = Auth::validatePassword($password)) {
            $this->flash('error', $e);
        } else {
            $this->app->db()->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $id]);
            $this->audit($q, 'admin_password_reset', "user #$id");
            $this->flash('success', 'رمز عبور کاربر بازنشانی شد. آن را به‌صورت امن به کاربر اطلاع دهید.');
        }
        return $this->redirect($q, '/admin/users');
    }

    public function deleteUser(Request $q): Response
    {
        if ($r = $this->guard($q)) {
            return $r;
        }
        $id = (int) $q->param('id');
        if ($id === $this->userId()) {
            $this->flash('error', 'حساب خودتان را نمی‌توانید حذف کنید.');
            return $this->redirect($q, '/admin/users');
        }
        $db = $this->app->db();
        $username = (string) $db->value('SELECT username FROM users WHERE id = ?', [$id]);
        $db->transaction(static function ($db) use ($id): void {
            $db->run('DELETE FROM transactions WHERE portfolio_id IN (SELECT id FROM portfolios WHERE user_id = ?)', [$id]);
            foreach (['portfolios', 'watchlist', 'notification_channels', 'notifications', 'settings'] as $table) {
                $db->run("DELETE FROM $table WHERE user_id = ?", [$id]);
            }
            $db->run('DELETE FROM users WHERE id = ?', [$id]);
        });
        $this->audit($q, 'admin_user_delete', $username);
        $this->flash('success', "کاربر «{$username}» و همه‌ی داده‌هایش حذف شد.");
        return $this->redirect($q, '/admin/users');
    }

    public const SYSTEM_FIELDS = [
        'site_name' => 'نام سامانه',
        'bale_token' => 'توکن ربات بله',
        'bale_username' => 'نام کاربری ربات بله (بدون @)',
        'telegram_token' => 'توکن ربات تلگرام',
        'telegram_username' => 'نام کاربری ربات تلگرام (بدون @)',
    ];

    public function settings(Request $q): Response
    {
        if ($r = $this->guard($q)) {
            return $r;
        }
        $s = $this->app->settings();
        $values = [];
        foreach (array_keys(self::SYSTEM_FIELDS) as $k) {
            $values[$k] = $s->system($k);
        }
        foreach (['registration_open', 'registration_approval', ...array_keys(SettingsController::ANALYSIS_FIELDS)] as $k) {
            $values[$k] = $s->system($k);
        }
        return $this->view($q, 'admin/settings', ['title' => 'تنظیمات سامانه', 'nav' => 'admin', 'values' => $values,
            'fields' => self::SYSTEM_FIELDS, 'analysisFields' => SettingsController::ANALYSIS_FIELDS,
            'cronUrl' => ($q->isSecure() ? 'https' : 'http') . '://' . ($q->server['HTTP_HOST'] ?? 'localhost')
                . $this->app->view($q)->url('cron', ['token' => (string) $this->app->config->get('cron_token')]),
            'appRoot' => $this->app->root]);
    }

    public function saveSettings(Request $q): Response
    {
        if ($r = $this->guard($q)) {
            return $r;
        }
        $s = $this->app->settings();
        foreach (array_keys(self::SYSTEM_FIELDS) as $k) {
            $s->set(Settings::SYSTEM, $k, trim(mb_substr($q->input($k), 0, 200)));
        }
        $s->set(Settings::SYSTEM, 'registration_open', $q->input('registration_open') === '1' ? '1' : '0');
        $s->set(Settings::SYSTEM, 'registration_approval', $q->input('registration_approval') === '1' ? '1' : '0');
        foreach (SettingsController::ANALYSIS_FIELDS as $k => [$label, , $min, $max, $scale]) {
            $v = \Sabadgardan\Market\Text::parseNumber($q->input($k));
            if ($v !== null && $v >= $min && $v <= $max) {
                $s->set(Settings::SYSTEM, $k, (string) ($v / $scale));
            }
        }
        $this->audit($q, 'admin_settings');
        $this->flash('success', 'تنظیمات سامانه ذخیره شد.');
        return $this->redirect($q, '/admin/settings');
    }

    public function auditLog(Request $q): Response
    {
        if ($r = $this->guard($q)) {
            return $r;
        }
        return $this->view($q, 'admin/audit', ['title' => 'گزارش رویدادها', 'nav' => 'admin', 'rows' => $this->app->audit()->recent(300)]);
    }

    /** Connectivity and schema checks against the live APIs (the PHP counterpart of `doctor`). */
    public function health(Request $q): Response
    {
        if ($r = $this->guard($q)) {
            return $r;
        }
        $checks = [];
        $env = [
            ['نسخه‌ی PHP', PHP_VERSION, PHP_VERSION_ID >= 80100],
            ['افزونه‌ی cURL', extension_loaded('curl') ? 'فعال' : 'غیرفعال', extension_loaded('curl')],
            ['افزونه‌ی GD (تصویر سیگنال)', function_exists('imagettftext') ? 'فعال' : 'غیرفعال', function_exists('imagettftext')],
            ['پوشه‌ی storage قابل نوشتن', is_writable($this->app->root . '/storage') ? 'بله' : 'خیر', is_writable($this->app->root . '/storage')],
            ['آخرین اجرای cron', $this->app->settings()->system('last_cron_at') ?: 'هرگز', $this->app->settings()->system('last_cron_at') !== ''],
        ];
        $market = $this->app->uncachedMarket();
        $probe = function (string $name, callable $fn, array $fields) use (&$checks): void {
            $start = microtime(true);
            try {
                $rows = $fn();
                $first = is_array($rows) && array_is_list($rows) ? ($rows[0] ?? []) : $rows;
                $keys = array_change_key_case(is_array($first) ? $first : [], CASE_LOWER);
                $missing = array_values(array_filter($fields, static fn ($f) => !array_key_exists(strtolower($f), $keys)));
                $checks[] = [$name, $missing ? 'فیلد ناموجود: ' . implode('، ', $missing) : 'سالم', !$missing,
                             round((microtime(true) - $start) * 1000)];
            } catch (\Throwable $e) {
                $checks[] = [$name, $e->getMessage(), false, round((microtime(true) - $start) * 1000)];
            }
        };
        $cdn = $market->cdn;
        $probe('جستجوی نماد (cdn)', fn () => $cdn->search('فولاد'), ['insCode', 'lVal18AFC', 'lVal30', 'cIsin']);
        $code = '';
        try {
            $code = $cdn->resolve('فولاد')->insCode;
        } catch (MarketException) {
        }
        if ($code !== '') {
            $probe('تاریخچه‌ی قیمت', fn () => \Sabadgardan\Market\Field::list($cdn->get("ClosingPrice/GetClosingPriceDailyList/$code/5"), 'closingPriceDaily'),
                ['dEven', 'priceFirst', 'priceMax', 'priceMin', 'pClosing', 'pDrCotVal', 'priceYesterday', 'qTotTran5J', 'qTotCap']);
            $probe('قیمت لحظه‌ای', fn () => $cdn->closingPriceInfo($code), ['pClosing', 'pDrCotVal', 'priceYesterday', 'instrumentState']);
            $probe('حقیقی/حقوقی امروز', fn () => $cdn->clientType($code), ['buy_I_Volume', 'buy_CountI', 'sell_I_Volume', 'sell_CountI']);
            $probe('حقیقی/حقوقی تاریخی', fn () => $cdn->clientTypeHistory($code), ['recDate', 'buy_I_Volume', 'buy_CountI']);
            $probe('اطلاعات نماد (EPS)', fn () => $cdn->instrumentInfo($code), ['lVal18AFC', 'sector', 'eps', 'zTitad']);
            $probe('تابلوی سفارش', fn () => $cdn->bestLimits($code), ['number', 'pMeDem', 'qTitMeDem', 'pMeOf', 'qTitMeOf']);
            $probe('دامنه‌ی نوسان', fn () => \Sabadgardan\Market\Field::list($cdn->get("MarketData/GetStaticThreshold/$code/" . date('Ymd')), 'staticThreshold'),
                ['psGelStaMax', 'psGelStaMin']);
        }
        $probe('شاخص‌ها', fn () => $cdn->indices(TsetmcClient::FLOW_BOURSE), ['insCode', 'lVal30', 'xDrNivJIdx004', 'xVarIdxJRfV']);
        $probe('تاریخچه‌ی شاخص کل', fn () => \Sabadgardan\Market\Field::list($cdn->get('Index/GetIndexB2History/' . TsetmcClient::TEDPIX), 'indexB2'),
            ['dEven', 'xNivInuClMresIbs']);
        if ($market->gateway) {
            $probe('دیده‌بان رسمی (tse.ir)', fn () => $market->gateway->marketWatch('Cash'), ['instrumentName', 'closingPricePercent', 'tradeValue']);
        }
        return $this->view($q, 'admin/health', ['title' => 'سلامت سامانه', 'nav' => 'admin', 'env' => $env, 'checks' => $checks,
            'migration' => (new Migrations($this->app->db()))->current()]);
    }
}

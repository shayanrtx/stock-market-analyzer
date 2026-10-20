<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\App;
use Sabadgardan\Auth\Auth;
use Sabadgardan\Core\Config;
use Sabadgardan\Core\Database;
use Sabadgardan\Core\Migrations;
use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\View\View;

/**
 * First-run wizard: checks requirements, writes config/config.php, creates the schema and the
 * first admin. Runs only while config/config.php does not exist.
 */
final class InstallController
{
    public function __construct(private App $app)
    {
    }

    /** @return list<array{0: string, 1: bool, 2: string}> label, ok, hint */
    public function requirements(): array
    {
        $root = $this->app->root;
        return [
            ['PHP نسخه‌ی 8.1 یا جدیدتر', PHP_VERSION_ID >= 80100, 'نسخه‌ی فعلی: ' . PHP_VERSION],
            ['افزونه‌ی PDO SQLite یا MySQL', extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'), 'از کنترل‌پنل هاست فعال کنید'],
            ['افزونه‌ی cURL', extension_loaded('curl'), 'برای دریافت داده‌ی بورس لازم است'],
            ['افزونه‌ی mbstring', extension_loaded('mbstring'), 'برای پردازش متن فارسی لازم است'],
            ['پوشه‌ی config قابل نوشتن', is_writable($root . '/config'), 'دسترسی پوشه را 755 یا 775 کنید'],
            ['پوشه‌ی storage قابل نوشتن', is_writable($root . '/storage'), 'دسترسی پوشه را 755 یا 775 کنید'],
            ['افزونه‌ی GD (اختیاری، تصویر سیگنال)', function_exists('imagettftext'), 'بدون آن سیگنال‌ها بدون تصویر ارسال می‌شوند'],
        ];
    }

    public function handle(Request $q): Response
    {
        if (str_starts_with($q->path, '/assets/')) {
            return Response::html('', 404);
        }
        $view = new View($this->app->root . '/views', $q->basePath);
        $view->shared = ['user' => null, 'flashes' => [], 'siteName' => 'دستیار سبدگردان', 'glossary' => [], 'path' => $q->path];
        $data = ['title' => 'نصب', 'requirements' => $this->requirements(), 'errors' => [], 'old' => ['driver' => 'sqlite']];
        if ($q->method === 'POST') {
            [$errors, $old] = $this->install($q);
            if (!$errors) {
                return Response::redirect($view->url('login'));
            }
            $data['errors'] = $errors;
            $data['old'] = $old;
        }
        return Response::html($view->render('install', $data, 'layout_guest'));
    }

    /** @return array{0: list<string>, 1: array<string, string>} */
    private function install(Request $q): array
    {
        $old = ['driver' => $q->input('driver', 'sqlite'), 'host' => $q->input('host', 'localhost'), 'port' => $q->input('port', '3306'),
                'database' => $q->input('database'), 'db_user' => $q->input('db_user'), 'username' => $q->input('username'),
                'display_name' => $q->input('display_name')];
        $errors = [];
        foreach ($this->requirements() as [$label, $ok]) {
            if (!$ok && !str_contains($label, 'اختیاری')) {
                $errors[] = "پیش‌نیاز برقرار نیست: $label";
            }
        }
        $password = (string) ($q->post['password'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', Auth::normalizeUsername($old['username']))) {
            $errors[] = 'نام کاربری مدیر ۳ تا ۳۰ حرف لاتین، عدد، نقطه، خط تیره یا زیرخط باشد.';
        }
        if ($e = Auth::validatePassword($password)) {
            $errors[] = $e;
        }
        if ($errors) {
            return [$errors, $old];
        }
        $values = [
            'debug' => false,
            'db' => $old['driver'] === 'mysql'
                ? ['driver' => 'mysql', 'mysql' => ['host' => $old['host'], 'port' => (int) $old['port'], 'database' => $old['database'],
                                                    'username' => $old['db_user'], 'password' => (string) ($q->post['db_password'] ?? ''),
                                                    'charset' => 'utf8mb4']]
                : ['driver' => 'sqlite', 'sqlite_path' => 'storage/database.sqlite'],
            'cron_token' => bin2hex(random_bytes(16)),
        ];
        try {
            $db = Database::fromConfig(Config::fromArray($values), $this->app->root);
            (new Migrations($db))->migrate();
            $auth = new Auth($db);
            if (!$db->value('SELECT 1 FROM users WHERE is_admin = 1')) {
                $id = $auth->createUser($old['username'], $password, $old['display_name'] ?: $old['username'], true);
                (new \Sabadgardan\Core\AuditLog($db))->record($id, 'install', '', $q->ip());
            }
        } catch (\Throwable $e) {
            return [['اتصال یا ساخت پایگاه داده ناموفق بود: ' . $e->getMessage()], $old];
        }
        $php = "<?php\n\n// Written by the installer on " . date('Y-m-d H:i') . ". Keep this file private.\nreturn "
            . var_export($values, true) . ";\n";
        if (@file_put_contents($this->app->root . '/config/config.php', $php) === false) {
            return [['نوشتن config/config.php ممکن نشد؛ دسترسی پوشه‌ی config را بررسی کنید.'], $old];
        }
        return [[], $old];
    }
}

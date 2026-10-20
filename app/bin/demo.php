<?php

declare(strict_types=1);

/*
 * Demo installation with synthetic market data, for trying the app anywhere (e.g. locally in VS Code):
 *
 *   php bin/demo.php                 # creates config + database in demo mode and a sample account
 *   php -S localhost:8000 index.php  # then open http://localhost:8000  (user: demo, password: demo1234)
 *
 * It refuses to touch an installation that uses real market data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Sabadgardan\App;
use Sabadgardan\Core\Cache;
use Sabadgardan\Core\Migrations;
use Sabadgardan\Core\Settings;
use Sabadgardan\Live\LiveMonitor;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Market\GatewayClient;
use Sabadgardan\Market\Http\DemoTransport;
use Sabadgardan\Market\MarketData;
use Sabadgardan\Market\TsetmcClient;
use Sabadgardan\Portfolio\Transaction;

$root = dirname(__DIR__);
$configFile = "$root/config/config.php";
if (is_file($configFile)) {
    $existing = require $configFile;
    if (($existing['market']['transport'] ?? 'curl') !== 'demo') {
        fwrite(STDERR, "config/config.php uses real market data; the demo seeder will not modify it.\n");
        exit(1);
    }
} else {
    $config = ['debug' => true, 'db' => ['driver' => 'sqlite', 'sqlite_path' => 'storage/demo.sqlite'],
               'market' => ['transport' => 'demo'], 'cron_token' => bin2hex(random_bytes(16))];
    file_put_contents($configFile, "<?php\n\n// Demo mode: synthetic market data (bin/demo.php).\nreturn " . var_export($config, true) . ";\n");
    echo "Created config/config.php (demo mode)\n";
}

$app = App::create($root);
$db = $app->db();
(new Migrations($db))->migrate();
if ($db->value('SELECT id FROM users WHERE username = ?', ['demo'])) {
    echo "Demo data already present. Delete storage/demo.sqlite and config/config.php to start over.\n";
    exit(0);
}

// --------------------------------------------------------------- accounts
$auth = $app->auth();
$uid = $auth->createUser('demo', 'demo1234', 'کاربر نمایشی', true, 'demo@example.com');
$auth->createUser('sara', 'sara12345', 'سارا محمدی', false, 'sara@example.com');
$auth->createUser('reza', 'reza12345', 'رضا احمدی', false, '', 'pending');
$app->audit()->record($uid, 'install', 'demo');
$app->settings()->set(Settings::SYSTEM, 'site_name', 'دستیار سبدگردان');

// --------------------------------------------------------------- portfolio
$market = $app->market();
$repo = $app->portfolios();
$pid = (int) $repo->defaultPortfolio($uid)['id'];
$resolve = static function (string $symbol) use ($market) {
    return $market->resolve($symbol);   // also stores the instrument locally
};
$history = static function (string $code) use ($market) {
    return $market->bundles([$code], ['history'])[$code]->raw;
};
$fees = $app->settings()->fees($uid);

$start = null;
$trades = [
    // symbol, sessions ago, quantity, side
    ['فملی', 620, 20000, 'buy'], ['فولاد', 540, 25000, 'buy'], ['شپنا', 480, 12000, 'buy'], ['وبملت', 400, 30000, 'buy'],
    ['کگل', 640, 9000, 'buy'], ['رمپنا', 420, 15000, 'buy'], ['اخابر', 300, 16000, 'buy'], ['خودرو', 210, 25000, 'buy'],
    ['خساپا', 380, 30000, 'buy'], ['خساپا', 120, 30000, 'sell'], ['فملی', 150, 8000, 'buy'], ['فولاد', 60, 10000, 'sell'],
    ['کمند', 90, 4000, 'buy'], ['عیار', 200, 2500, 'buy'],
];
$txs = [];
foreach ($trades as [$symbol, $ago, $qty, $side]) {
    $inst = $resolve($symbol);
    $raw = $history($inst->insCode);
    $i = max(0, $raw->count() - 1 - $ago);
    $price = $raw->close[$i];
    $date = $raw->dates[$i];
    $start = $start === null || $date < $start ? $date : $start;
    $txs[] = new Transaction(null, $side, $date, $inst->insCode, $inst->symbol, $qty, $price,
        $fees->fee($side, $inst->assetClass()->feeClass(), $qty, $price), 0, '');
}
// Capital increases after the purchase: record the bonus shares (as a careful investor would),
// and cash dividends of the holdings.
$holdings = [];
foreach ($txs as $t) {
    $holdings[$t->insCode][] = $t;
}
foreach ($holdings as $code => $list) {
    $raw = $history((string) $code);
    $first = min(array_map(static fn (Transaction $t) => $t->date, $list));
    foreach ($raw->dates as $i => $d) {
        if ($i === 0 || $d <= $first) {
            continue;
        }
        $factor = $raw->yesterday[$i] / $raw->close[$i - 1];
        $qty = 0.0;
        foreach ($list as $t) {
            if ($t->date < $d) {
                $qty += $t->type === 'sell' ? -$t->quantity : $t->quantity;
            }
        }
        if ($qty <= 0 || $factor > 0.995) {
            continue;
        }
        if ($factor < 0.9) {
            $txs[] = new Transaction(null, Transaction::BONUS, $d, (string) $code, $list[0]->symbol, round($qty * (1 / $factor - 1)), 0, 0, 0, 'افزایش سرمایه');
        } else {
            $dps = $raw->close[$i - 1] - $raw->yesterday[$i];
            $txs[] = new Transaction(null, Transaction::DIVIDEND, $d, (string) $code, $list[0]->symbol, 0, 0, 0, round($dps * $qty), 'سود نقدی مجمع');
        }
    }
}
// Initial deposit sized so the account ends with roughly 8% of the invested amount in cash.
$cost = array_sum(array_map(static fn (Transaction $t) => $t->type === 'buy' ? $t->quantity * $t->price + $t->fee : 0, $txs));
$cashFlow = array_sum(array_map(static fn (Transaction $t) => $t->cashFlow(), $txs));
array_unshift($txs, new Transaction(null, Transaction::DEPOSIT, $start, null, null, 0, 0, 0, ceil((0.08 * $cost - $cashFlow) / 1e8) * 1e8, 'واریز اولیه'));
usort($txs, static fn (Transaction $a, Transaction $b) => $a->date <=> $b->date);
foreach ($txs as $t) {
    $repo->saveTransaction($uid, $pid, $t);
}
echo count($txs), " transactions\n";

foreach (['فارس', 'پارسان', 'کچاد', 'شبندر', 'تاپیکو', 'حکشتی', 'مارون', 'اهرم', 'اخزا۴۱۲'] as $symbol) {
    $inst = $resolve($symbol);
    $repo->watch($uid, $inst->insCode, $inst->symbol);
}
$second = $repo->createPortfolio($uid, 'حساب نوسان‌گیری');
$inst = $resolve('شتران');
$raw = $history($inst->insCode);
$i = $raw->count() - 25;
$repo->saveTransaction($uid, $second, new Transaction(null, 'buy', $raw->dates[$i], $inst->insCode, $inst->symbol, 50000,
    $raw->close[$i], $fees->fee('buy', $inst->assetClass()->feeClass(), 50000, $raw->close[$i]), 0, ''));

// ------------------------------------------------ replay market sessions
// The monitor runs on a simulated clock, exactly as cron would: the last 12 sessions at 10-minute
// steps (so the signals page has a track record), then the latest session at 3-minute steps.
$now = time();
$days = [];
$day = $now;
if (!(MarketClock::isTradingDay($now) && MarketClock::minuteOfDay($now) >= 560)) {
    $day -= 86400;
}
while (count($days) < 13) {
    if (MarketClock::isTradingDay($day)) {
        $days[] = $day;
    }
    $day -= 86400;
}
$days = array_reverse($days);
$clockTs = 0;
$clock = static function () use (&$clockTs): int {
    return $clockTs;
};
$transport = new DemoTransport($clock);
$cacheDir = sys_get_temp_dir() . '/sabadgardan-demo-' . bin2hex(random_bytes(4));
$cache = new Cache($cacheDir, $clock);
$replayMarket = new MarketData(new TsetmcClient($transport, $cache, 5, 0), new GatewayClient($transport, $cache, 5, 0), $db);
$monitor = new LiveMonitor($db, $replayMarket, $repo, $app->settings(), $cache, $app->notifier(), "$root/storage/live.lock", 45, $clock);
$ticks = $insights = $signals = 0;
foreach ($days as $k => $day) {
    $last = $k === count($days) - 1;
    $midnight = strtotime(MarketClock::local($day, 'Y-m-d') . ' 00:00:00 UTC') - MarketClock::OFFSET;
    $end = min($now, $midnight + 749 * 60);
    for ($clockTs = $midnight + 526 * 60; $clockTs <= $end; $clockTs += $last ? 180 : 600) {
        $r = $monitor->tick(true);
        $ticks++;
        $insights += $r['insights'];
        $signals += $r['signals'];
    }
}
array_map('unlink', glob("$cacheDir/*") ?: []);
@rmdir($cacheDir);
printf("Replayed %d sessions (%s to %s): %d cycles, %d insights, %d signals\n", count($days),
    MarketClock::local($days[0], 'Y-m-d'), MarketClock::local(end($days), 'Y-m-d'), $ticks, $insights, $signals);
$app->settings()->set(Settings::SYSTEM, 'live_last_tick', '0');
echo "Done. Run: php -S localhost:8000 index.php  → http://localhost:8000  (demo / demo1234)\n";

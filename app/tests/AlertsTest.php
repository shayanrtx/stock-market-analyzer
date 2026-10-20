<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Alerts\DisclosureWatch;
use Sabadgardan\Alerts\PriceAlerts;
use Sabadgardan\Auth\PasswordReset;
use Sabadgardan\Core\Backup;
use Sabadgardan\Core\Cache;
use Sabadgardan\Core\Database;
use Sabadgardan\Core\Migrations;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Live\Snapshot;
use Sabadgardan\Market\GatewayClient;
use Sabadgardan\Market\Http\HttpResult;
use Sabadgardan\Market\Http\Transport;
use Sabadgardan\Market\MarketData;
use Sabadgardan\Market\TsetmcClient;

require_once __DIR__ . '/NotifierTest.php';   // FakeMessenger

/** Serves fixed JSON per URL fragment; the test edits $routes between calls. */
final class ScriptedTransport implements Transport
{
    /** @var array<string, array<string, mixed>> URL fragment => JSON payload */
    public array $routes = [];

    public function fetchMany(array $urls, array $headers, int $timeout): array
    {
        $out = [];
        foreach ($urls as $url) {
            $out[$url] = new HttpResult(404, '');
            foreach ($this->routes as $fragment => $payload) {
                if (str_contains($url, $fragment)) {
                    $out[$url] = new HttpResult(200, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
                }
            }
        }
        return $out;
    }
}

/** Price alerts, announcement alerts, password reset over the messenger, database backups. */
final class AlertsTest extends TestCase
{
    private static function tehran(string $time): int
    {
        return strtotime("$time UTC") - MarketClock::OFFSET;
    }

    public function testPriceAlertsFireOnceAndUseTheDayRangeOnlyForOlderAlerts(): void
    {
        $this->now = self::tehran('2026-10-20 11:00');
        $app = $this->makeApp();
        $uid = $app->auth()->createUser('alice', 'password1', 'آلیس', false);
        $alerts = $app->alerts();
        $yesterday = $this->now - 86400;
        $this->assertNull($alerts->create($uid, '1', 'نماد', PriceAlerts::ABOVE, 108, 'شکست مقاومت', $yesterday));
        $this->assertNull($alerts->create($uid, '1', 'نماد', PriceAlerts::ABOVE, 108, '', $this->now));
        $this->assertNull($alerts->create($uid, '1', 'نماد', PriceAlerts::BELOW, 96, '', $yesterday));
        $this->assertNull($alerts->create($uid, '1', 'نماد', PriceAlerts::BELOW, 90, '', $yesterday));
        $this->assertSame('شرط یا قیمت هشدار معتبر نیست.', $alerts->create($uid, '1', 'نماد', 'sideways', 100, '', $this->now));

        // Last trade 105 after a 95–110 range: the morning's touch counts only for alerts set before the open.
        $snap = new Snapshot('1', 'نماد', $this->now, last: 105.0, high: 110.0, low: 95.0);
        $fired = $alerts->trigger(['1' => $snap], $this->now);
        $this->assertSame(2, count($fired));
        $this->assertSame(['above', 'below'], array_column($fired, 'direction'));
        $this->assertContains('به بالای 108 ریال رسید', PriceAlerts::message($fired[0], 'سامانه'));
        $this->assertContains('شکست مقاومت', PriceAlerts::message($fired[0], 'سامانه'));
        $this->assertSame([], $alerts->trigger(['1' => $snap], $this->now + 60), 'an alert fires once');

        $later = new Snapshot('1', 'نماد', $this->now + 120, last: 108.5);
        $this->assertSame(1, count($alerts->trigger(['1' => $later], $this->now + 120)), 'the same-day alert, on a real trade');
        $this->assertSame(4, count($alerts->forUser($uid)), 'fired alerts stay listed');
        $this->assertSame(['1' => 'نماد'], $alerts->activeSymbols());

        $bob = $app->auth()->createUser('bob', 'password1', 'باب', false);
        $id = (int) $app->db()->value('SELECT id FROM price_alerts WHERE triggered_at IS NULL');
        $this->assertFalse($alerts->delete($bob, $id), "one user can't delete another's alert");
        $this->assertTrue($alerts->delete($uid, $id));
    }

    public function testLiveMonitorSendsAlertsForSymbolsOutsideTheWatchlist(): void
    {
        $this->now = self::tehran('2026-10-20 10:30');
        $app = $this->makeApp();
        $uid = $app->auth()->createUser('alice', 'password1', 'آلیس', false);
        $fake = new FakeMessenger('bale');
        $app->notifier()->useMessengers($fake);
        $app->notifier()->link($uid, 'bale', '42');
        // Not held and not watched: the monitor fetches its price just for the alert.
        $inst = $app->market()->resolve('شپنا');
        $price = $app->market()->bundles([$inst->insCode], MarketData::PARTS_PRICE)[$inst->insCode]->lastPrice();
        $app->alerts()->create($uid, $inst->insCode, $inst->symbol, PriceAlerts::BELOW, $price * 1.2, '', $this->now - 86400);

        $result = $app->live()->tick(true);
        $this->assertSame(1, $result['alerts']);
        $this->assertSame(1, count($fake->sent));
        $this->assertContains('هشدار قیمت — شپنا', $fake->sent[0][2]);
        $this->assertSame('42', $fake->sent[0][1]);
        $this->now += 60;
        $this->assertSame(0, $app->live()->tick(true)['alerts']);
    }

    public function testImportantAnnouncementsAreReportedOnceAndOnlyWhenNew(): void
    {
        $this->now = self::tehran('2026-10-20 19:00');
        $clock = fn (): int => $this->now;
        $transport = new ScriptedTransport();
        $transport->routes['Msg/GetMsgByInsCode/7'] = ['msg' => [
            ['dEven' => 20260901, 'hEven' => 81500, 'tseTitle' => 'توقف نماد آزمون', 'tseDesc' => 'مجمع'],
        ]];
        $transport->routes['Codal/GetPreparedDataByInsCode'] = ['preparedData' => []];
        $db = Database::memory();
        (new Migrations($db))->migrate();
        $cache = new Cache(sys_get_temp_dir() . '/sbg-disc-' . bin2hex(random_bytes(4)), $clock);
        $market = new MarketData(new TsetmcClient($transport, $cache, 2, 0), new GatewayClient($transport, $cache, 2, 0), $db);
        $watch = new DisclosureWatch($db, $market, $cache);

        $this->assertSame([], $watch->check(['7'], $this->now), 'the first read only records what was already published');
        $transport->routes['Msg/GetMsgByInsCode/7']['msg'][] =
            ['dEven' => 20261020, 'hEven' => 183000, 'tseTitle' => 'افزایش سرمایه آزمون', 'tseDesc' => 'ثبت شد'];
        $transport->routes['Codal/GetPreparedDataByInsCode'] = ['preparedData' => [
            ['title' => 'گزارش فعالیت ماهانه', 'publishDateTime' => '1405/07/28 18:00:00', 'url' => '']]];
        $this->now += 60;
        $this->assertSame([], $watch->check(['7'], $this->now), 'each symbol is re-read every 15 minutes at most');
        $this->now += DisclosureWatch::INTERVAL;
        $new = $watch->check(['7'], $this->now);
        $this->assertSame(1, count($new), 'a monthly report is recorded, not announced');
        $this->assertSame('capital', $new[0]['item']['kind']);
        $this->assertContains('افزایش سرمایه آزمون', DisclosureWatch::message('آزمون', $new[0]['item'], 'سامانه'));
        $this->now += DisclosureWatch::INTERVAL;
        $this->assertSame([], $watch->check(['7'], $this->now), 'nothing new the second time');
    }

    public function testPasswordResetThroughTheLinkedMessenger(): void
    {
        $app = $this->makeApp();
        $uid = $app->auth()->createUser('alice', 'password1', 'آلیس', false);
        $fake = new FakeMessenger('bale');
        $app->notifier()->useMessengers($fake);
        $reset = $app->passwordReset();
        $this->assertFalse($reset->request('alice', 'سامانه', $this->now), 'no linked messenger, nothing to send');
        $this->assertFalse($reset->request('nobody', 'سامانه', $this->now));

        $app->notifier()->link($uid, 'bale', '42');
        $this->assertTrue($reset->request('Alice ', 'سامانه', $this->now));
        $this->assertTrue((bool) preg_match('/(\d{4}) (\d{4})/', $fake->sent[0][2], $m), 'the code is in the message');
        $code = $m[1] . $m[2];
        $this->assertSame(64, strlen((string) $app->db()->value('SELECT token_hash FROM password_resets')), 'stored hashed');
        $this->assertNotContains($code, (string) $app->db()->value('SELECT token_hash FROM password_resets'));

        $this->assertSame('کد بازیابی نادرست یا منقضی است.', $reset->reset('alice', '00000000', 'newpassword9', $this->now));
        $this->assertSame('رمز عبور باید دست‌کم 8 کاراکتر باشد.', $reset->reset('alice', $code, 'short', $this->now));
        $persian = strtr($m[1] . ' ' . $m[2], ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
        $this->assertNull($reset->reset('alice', $persian, 'newpassword9', $this->now), 'Persian digits and spaces are fine');
        $this->assertNull($app->auth()->attempt('alice', 'newpassword9', '10.0.0.9'));
        $this->assertSame('کد بازیابی نادرست یا منقضی است.', $reset->reset('alice', $code, 'another-pass1', $this->now), 'one use only');

        // Expiry and the guess limit.
        $reset->request('alice', 'سامانه', $this->now);
        preg_match('/(\d{4}) (\d{4})/', end($fake->sent)[2], $m);
        $this->assertNotNull($reset->reset('alice', $m[1] . $m[2], 'newpassword9', $this->now + PasswordReset::TTL + 1));
        $reset->request('alice', 'سامانه', $this->now);
        preg_match('/(\d{4}) (\d{4})/', end($fake->sent)[2], $m);
        for ($i = 0; $i < PasswordReset::MAX_ATTEMPTS; $i++) {
            $reset->reset('alice', '11111111', 'newpassword9', $this->now);
        }
        $this->assertNotNull($reset->reset('alice', $m[1] . $m[2], 'newpassword9', $this->now), 'too many guesses kill the code');
        $this->assertFalse($reset->request('alice', 'سامانه', $this->now), 'at most three codes an hour');
    }

    public function testBackupsAreConsistentCompressedAndPruned(): void
    {
        $db = Database::memory();
        (new Migrations($db))->migrate();
        $db->insert('audit_log', ['action' => 'probe', 'created_at' => '2026-10-20 10:00:00']);
        $dir = sys_get_temp_dir() . '/sbg-backup-' . bin2hex(random_bytes(4));
        $backup = new Backup($db, $dir);
        $this->assertTrue($backup->due(1_790_000_000));
        $name = $backup->create(1_790_000_000);
        $this->assertTrue((bool) preg_match('/^backup-\d{4}-\d{2}-\d{2}-\d{6}\.sqlite(\.gz)?$/', $name), $name);
        $this->assertFalse($backup->due(1_790_000_000 + 3600));

        $copy = $dir . '/restored.sqlite';
        file_put_contents($copy, str_ends_with($name, '.gz') ? gzdecode((string) file_get_contents("$dir/$name")) : file_get_contents("$dir/$name"));
        $restored = new \PDO("sqlite:$copy");
        $this->assertSame('probe', $restored->query('SELECT action FROM audit_log')->fetchColumn(), 'the backup restores');

        for ($i = 1; $i <= Backup::KEEP + 2; $i++) {
            $backup->create(1_790_000_000 + $i * 86400);
        }
        $this->assertSame(Backup::KEEP, count($backup->list()));
        $this->assertNull($backup->path('../demo.sqlite'));
        $this->assertNull($backup->path('restored.sqlite'), 'only backup files can be downloaded');
        $this->assertTrue($backup->path($backup->list()[0]['name']) !== null);
    }
}

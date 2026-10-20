<?php

declare(strict_types=1);

namespace Sabadgardan\Alerts;

use Sabadgardan\Core\Database;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Live\Snapshot;

/**
 * "Tell me when X trades above/below P." Checked on every live-monitor cycle against the last trade
 * and, for alerts set before today's session, today's high or low too, so a touch between two
 * cycles isn't missed (never another session's range: a halted symbol still shows its last day).
 * An alert fires once, then stays in the list as a record. Delivery is retried for RETRY_WINDOW
 * seconds if the messenger fails.
 */
final class PriceAlerts
{
    public const ABOVE = 'above';
    public const BELOW = 'below';
    public const MAX_ACTIVE = 50;
    public const RETRY_WINDOW = 1800;

    public function __construct(private Database $db)
    {
    }

    /** @return string|null error message, null when saved */
    public function create(int $userId, string $insCode, string $symbol, string $direction, float $price, string $note, int $now): ?string
    {
        if (!in_array($direction, [self::ABOVE, self::BELOW], true) || $price <= 0) {
            return 'شرط یا قیمت هشدار معتبر نیست.';
        }
        $active = (int) $this->db->value('SELECT COUNT(*) FROM price_alerts WHERE user_id = ? AND triggered_at IS NULL', [$userId]);
        if ($active >= self::MAX_ACTIVE) {
            return sprintf('حداکثر %d هشدار فعال مجاز است؛ چند هشدار قدیمی را حذف کنید.', self::MAX_ACTIVE);
        }
        $this->db->insert('price_alerts', ['user_id' => $userId, 'ins_code' => $insCode, 'symbol' => $symbol, 'direction' => $direction,
                                           'price' => $price, 'note' => mb_substr(trim($note), 0, 200), 'created_at' => $now]);
        return null;
    }

    public function delete(int $userId, int $id): bool
    {
        return $this->db->run('DELETE FROM price_alerts WHERE id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0;
    }

    /**
     * Active alerts first, then those that fired in the last 30 days.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId, ?string $insCode = null, ?int $now = null): array
    {
        $since = ($now ?? time()) - 30 * 86400;
        $sql = 'SELECT * FROM price_alerts WHERE user_id = ? AND (triggered_at IS NULL OR triggered_at >= ?)';
        $params = [$userId, $since];
        if ($insCode !== null) {
            $sql .= ' AND ins_code = ?';
            $params[] = $insCode;
        }
        return $this->db->all($sql . ' ORDER BY (triggered_at IS NULL) DESC, triggered_at DESC, symbol, price', $params);
    }

    /** @return array<string, string> ins_code => symbol of every active alert */
    public function activeSymbols(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT DISTINCT ins_code, symbol FROM price_alerts WHERE triggered_at IS NULL') as $r) {
            $out[(string) $r['ins_code']] = (string) $r['symbol'];
        }
        return $out;
    }

    /**
     * Marks and returns the alerts the latest trades reached.
     *
     * @param array<string, Snapshot> $snapshots by ins_code
     * @return list<array<string, mixed>> fired alerts with 'triggered_price'
     */
    public function trigger(array $snapshots, int $now): array
    {
        if (!$snapshots) {
            return [];
        }
        $codes = array_map('strval', array_keys($snapshots));
        $in = implode(',', array_fill(0, count($codes), '?'));
        $open = strtotime(MarketClock::local($now, 'Y-m-d') . ' 00:00:00 UTC') - MarketClock::OFFSET + 9 * 3600;
        $today = MarketClock::local($now, 'Ymd');
        $fired = [];
        foreach ($this->db->all("SELECT * FROM price_alerts WHERE triggered_at IS NULL AND ins_code IN ($in)", $codes) as $a) {
            $s = $snapshots[(string) $a['ins_code']];
            $price = $s->price();
            if (!$price) {
                continue;
            }
            // The day's range counts only if it is today's and the alert existed before the session began.
            $range = (int) $a['created_at'] < $open && $s->tradeDate === $today;
            $target = (float) $a['price'];
            $hit = $a['direction'] === self::ABOVE
                ? max($price, $range ? (float) $s->high : 0.0) >= $target
                : min($price, $range && $s->low ? $s->low : $price) <= $target;
            if (!$hit) {
                continue;
            }
            $this->db->update('price_alerts', ['triggered_at' => $now, 'triggered_price' => $price], ['id' => (int) $a['id']]);
            $fired[] = ['triggered_price' => $price, 'triggered_at' => $now] + $a;
        }
        return $fired;
    }

    /**
     * Fired alerts whose message hasn't reached the user yet (just fired, or the messenger failed
     * a moment ago).
     *
     * @return list<array<string, mixed>>
     */
    public function undelivered(int $now): array
    {
        return $this->db->all('SELECT * FROM price_alerts WHERE triggered_at >= ? AND notified = 0 ORDER BY id',
            [$now - self::RETRY_WINDOW]);
    }

    public function markNotified(int $id): void
    {
        $this->db->update('price_alerts', ['notified' => 1], ['id' => $id]);
    }

    /** Messenger text for a fired alert. */
    public static function message(array $alert, string $siteName): string
    {
        $dir = $alert['direction'] === self::ABOVE ? 'به بالای' : 'به زیر';
        $lines = ['🔔 هشدار قیمت — ' . $alert['symbol'], '',
                  sprintf('قیمت %s %s ریال رسید.', $dir, number_format((float) $alert['price'])),
                  'آخرین قیمت: ' . number_format((float) $alert['triggered_price']) . ' ریال'];
        if ((string) $alert['note'] !== '') {
            $lines[] = 'یادداشت شما: ' . $alert['note'];
        }
        $lines[] = '';
        $lines[] = '— ' . $siteName;
        return implode("\n", $lines);
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Alerts;

use Sabadgardan\Core\Cache;
use Sabadgardan\Core\Database;
use Sabadgardan\Market\Disclosures;
use Sabadgardan\Market\MarketData;

/**
 * Finds new important announcements (halt, meeting, capital increase, dividend) of the monitored
 * symbols. Each symbol is re-read at most every INTERVAL seconds and only PER_TICK symbols per
 * cycle, so a long watchlist costs a few requests a minute. The first read of a symbol only
 * records what is already published; it would be noise to announce last month's meeting.
 */
final class DisclosureWatch
{
    public const PER_TICK = 4;
    public const INTERVAL = 900;
    public const FRESH_DAYS = 3;

    public function __construct(private Database $db, private MarketData $market, private Cache $cache)
    {
    }

    /**
     * @param list<string> $codes monitored symbols
     * @return list<array{ins_code: string, item: array<string, mixed>}> new important announcements
     */
    public function check(array $codes, int $now): array
    {
        $due = [];
        foreach ($codes as $code) {
            $last = $this->cache->get("disclosures_checked:$code");
            if (!is_int($last) || $now - $last >= self::INTERVAL) {
                $due[$code] = is_int($last) ? $last : 0;
            }
        }
        asort($due);   // longest-waiting first
        $fresh = date('Y-m-d', $now - self::FRESH_DAYS * 86400);
        $out = [];
        foreach (array_slice(array_keys($due), 0, self::PER_TICK) as $code) {
            $code = (string) $code;
            $this->cache->set("disclosures_checked:$code", $now, 7 * 86400);
            try {
                $items = $this->market->disclosures($code, 20);
            } catch (\Throwable) {
                continue;
            }
            $baseline = !$this->db->value('SELECT 1 FROM disclosures_seen WHERE ins_code = ? LIMIT 1', [$code]);
            foreach ($items as $item) {
                $key = sha1($item['source'] . '|' . $item['at'] . '|' . $item['title']);
                if ($this->db->value('SELECT 1 FROM disclosures_seen WHERE ins_code = ? AND item_key = ?', [$code, $key])) {
                    continue;
                }
                $this->db->insert('disclosures_seen', ['ins_code' => $code, 'item_key' => $key, 'created_at' => $now]);
                if (!$baseline && $item['important'] && $item['at'] >= $fresh) {
                    $out[] = ['ins_code' => $code, 'item' => $item];
                }
            }
        }
        return $out;
    }

    /** Messenger text for one announcement. */
    public static function message(string $symbol, array $item, string $siteName): string
    {
        $lines = ['📢 اطلاعیه‌ی مهم — ' . $symbol, '', Disclosures::KINDS[$item['kind']] . ' (' . $item['source'] . ')', $item['title']];
        if ($item['body'] !== '') {
            $lines[] = mb_strimwidth($item['body'], 0, 400, '…');
        }
        if ($item['url'] !== '') {
            $lines[] = $item['url'];
        }
        $lines[] = '';
        $lines[] = '— ' . $siteName;
        return implode("\n", $lines);
    }

    /** Old bookkeeping rows are never consulted again once the items fall out of the latest 20. */
    public function prune(int $now): void
    {
        $this->db->run('DELETE FROM disclosures_seen WHERE created_at < ?', [$now - 180 * 86400]);
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Core;

use Sabadgardan\Portfolio\FeeSchedule;

/**
 * Key/value settings. ``user_id = 0`` holds system-wide values managed by admins; a user's own
 * row overrides the system value for user-level keys (fees, rates). Unknown keys fall back to
 * the defaults below.
 */
final class Settings
{
    public const SYSTEM = 0;

    /** System-only keys and their defaults. */
    public const SYSTEM_DEFAULTS = [
        'registration_open' => '1',
        'registration_approval' => '0',      // new accounts wait for admin approval
        'site_name' => 'دستیار سبدگردان',
        'bale_token' => '',
        'bale_username' => '',              // bot username for the link button
        'telegram_token' => '',
        'telegram_username' => '',
        'last_cron_at' => '',
        'last_cron_runner' => '',          // e.g. "CLI · PHP 8.2.10": shown on the health page
        'last_cron_error' => '',           // jobs that failed in the last cron run
        'last_updates_offset_bale' => '0',
        'last_updates_offset_telegram' => '0',
    ];

    /** Keys a user may override; system value (or default) applies otherwise. Fee keys: FeeSchedule::DEFAULTS. */
    public const USER_DEFAULTS = [
        'inflation_annual' => '0.35',
        'risk_free_annual' => '0.30',
        'risk_free_auto' => '1',           // follow treasury-bill yields (Market\Rates); the figure above is the fallback
        'max_position_weight' => '25',
        'notify_buy_signals' => '1',
        'notify_sell_signals' => '1',
        'notify_min_probability' => '0.55',
        'notify_disclosures' => '1',       // important announcements of held and watched symbols
    ];

    /** @var array<int, array<string, string>> */
    private array $loaded = [];

    public function __construct(private Database $db)
    {
    }

    public function system(string $key): string
    {
        $row = $this->load(self::SYSTEM);
        if (isset($row[$key])) {
            return $row[$key];
        }
        if (isset(self::SYSTEM_DEFAULTS[$key]) || isset(self::USER_DEFAULTS[$key])) {
            return self::SYSTEM_DEFAULTS[$key] ?? self::USER_DEFAULTS[$key];
        }
        return isset(FeeSchedule::DEFAULTS[$key]) ? (string) FeeSchedule::DEFAULTS[$key] : '';
    }

    public function forUser(int $userId, string $key): string
    {
        $row = $this->load($userId);
        return $row[$key] ?? $this->system($key);
    }

    public function float(int $userId, string $key): float
    {
        return (float) $this->forUser($userId, $key);
    }

    public function set(int $userId, string $key, string $value): void
    {
        $this->db->run('DELETE FROM settings WHERE user_id = ? AND name = ?', [$userId, $key]);
        $this->db->insert('settings', ['user_id' => $userId, 'name' => $key, 'value' => $value]);
        unset($this->loaded[$userId]);
    }

    public function fees(int $userId): FeeSchedule
    {
        $rates = [];
        foreach (array_keys(FeeSchedule::DEFAULTS) as $key) {
            $rates[$key] = $this->float($userId, $key);
        }
        return new FeeSchedule($rates);
    }

    /** @return array<string, string> */
    private function load(int $userId): array
    {
        if (!isset($this->loaded[$userId])) {
            $rows = $this->db->all('SELECT name, value FROM settings WHERE user_id = ?', [$userId]);
            $this->loaded[$userId] = array_column($rows, 'value', 'name');
        }
        return $this->loaded[$userId];
    }
}

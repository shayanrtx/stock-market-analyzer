<?php

declare(strict_types=1);

namespace Sabadgardan\Notify;

use Sabadgardan\Core\AuditLog;
use Sabadgardan\Core\Database;
use Sabadgardan\Core\Settings;
use Sabadgardan\Image\SignalChart;
use Sabadgardan\Market\PriceHistory;
use Sabadgardan\Signals\Signal;

/**
 * Delivers signals to users over their linked messengers.
 *
 * Linking: the user opens ``https://ble.ir/<bot>?start=<link_code>`` (or t.me for Telegram);
 * the bot receives "/start <link_code>", which pollUpdates() matches to the account.
 * Delivery rules: sell signals only to holders; buy signals to holders and watchers;
 * per-user preferences (buy/sell, minimum probability) and a daily cap apply.
 */
final class NotificationService
{
    public const DAILY_CAP = 30;

    /** @var array<string, Messenger> */
    private array $messengers = [];

    public function __construct(
        private Database $db,
        private Settings $settings,
        private AuditLog $audit,
        private ?SignalChart $chart = null,
    ) {
        foreach (['bale', 'telegram'] as $name) {
            $token = $this->settings->system("{$name}_token");
            if ($token !== '') {
                $this->messengers[$name] = $name === 'bale' ? BotApiMessenger::bale($token) : BotApiMessenger::telegram($token);
            }
        }
    }

    /** Replaces the configured messengers (tests, or after the admin changes tokens). */
    public function useMessengers(Messenger ...$messengers): void
    {
        $this->messengers = [];
        foreach ($messengers as $m) {
            $this->messengers[$m->name()] = $m;
        }
    }

    /** @return list<string> */
    public function enabledChannels(): array
    {
        return array_keys($this->messengers);
    }

    public function linkUrl(string $channel, string $linkCode): ?string
    {
        $bot = ltrim($this->settings->system("{$channel}_username"), '@');
        if ($bot === '' || !isset($this->messengers[$channel])) {
            return null;
        }
        return ($channel === 'bale' ? 'https://ble.ir/' : 'https://t.me/') . rawurlencode($bot) . '?start=' . $linkCode;
    }

    /** Reads bot updates and links accounts that sent "/start <code>". Returns linked count. */
    public function pollUpdates(): int
    {
        $linked = 0;
        foreach ($this->messengers as $name => $m) {
            $offsetKey = "last_updates_offset_$name";
            $offset = (int) $this->settings->system($offsetKey);
            try {
                $updates = $m->updates($offset);
            } catch (NotifyException) {
                continue;
            }
            foreach ($updates as $u) {
                $offset = max($offset, (int) ($u['update_id'] ?? 0) + 1);
                $text = trim((string) ($u['message']['text'] ?? ''));
                $chatId = (string) ($u['message']['chat']['id'] ?? '');
                if ($chatId === '' || !preg_match('/^\/start\s+([a-f0-9]{16})$/', $text, $mm)) {
                    continue;
                }
                $user = $this->db->one("SELECT id, display_name FROM users WHERE link_code = ? AND status = 'active'", [$mm[1]]);
                if (!$user) {
                    $this->safeSend($m, $chatId, 'کد اتصال معتبر نیست. از صفحه‌ی تنظیمات حساب، دکمه‌ی اتصال را دوباره بزنید.');
                    continue;
                }
                $this->link((int) $user['id'], $name, $chatId);
                $this->audit->record((int) $user['id'], 'messenger_link', $name);
                $this->safeSend($m, $chatId, sprintf("%s عزیز، اتصال برقرار شد ✅\nسیگنال‌های خرید و فروش نمادهای سبد و دیده‌بان شما از این پس اینجا ارسال می‌شود.",
                    $user['display_name']));
                $linked++;
            }
            $this->settings->set(Settings::SYSTEM, $offsetKey, (string) $offset);
        }
        return $linked;
    }

    public function link(int $userId, string $channel, string $chatId): void
    {
        $this->db->run('DELETE FROM notification_channels WHERE user_id = ? AND channel = ?', [$userId, $channel]);
        $this->db->insert('notification_channels', ['user_id' => $userId, 'channel' => $channel, 'chat_id' => $chatId,
                                                    'enabled' => 1, 'linked_at' => date('Y-m-d H:i:s')]);
    }

    public function unlink(int $userId, string $channel): void
    {
        $this->db->run('DELETE FROM notification_channels WHERE user_id = ? AND channel = ?', [$userId, $channel]);
    }

    /** @return list<array<string, mixed>> */
    public function channels(int $userId): array
    {
        return $this->db->all('SELECT * FROM notification_channels WHERE user_id = ? ORDER BY channel', [$userId]);
    }

    /**
     * Sends a signal to the users it concerns. Returns the number of messages delivered.
     *
     * @param list<int> $holders
     * @param list<int> $watchers
     */
    public function dispatch(Signal $signal, array $holders, array $watchers, ?PriceHistory $history = null): int
    {
        $recipients = $signal->side === Signal::SELL ? $holders : array_values(array_unique([...$holders, ...$watchers]));
        if (!$recipients || !$this->messengers) {
            return 0;
        }
        $png = null;
        if ($this->chart && $history && SignalChart::available()) {
            try {
                $png = $this->chart->render($signal, $history);
            } catch (\Throwable) {
                $png = null;  // text-only fallback
            }
        }
        $text = $signal->message($this->settings->system('site_name'));
        $sent = 0;
        foreach ($recipients as $userId) {
            if (!$this->wants($userId, $signal)) {
                continue;
            }
            foreach ($this->channels($userId) as $ch) {
                $m = $this->messengers[$ch['channel']] ?? null;
                if (!$m || !(int) $ch['enabled']) {
                    continue;
                }
                $error = '';
                try {
                    $png !== null ? $m->sendPhoto((string) $ch['chat_id'], $png, $text)
                                  : $m->sendMessage((string) $ch['chat_id'], $text);
                    $sent++;
                } catch (NotifyException $e) {
                    $error = $e->getMessage();
                }
                $this->db->insert('notifications', ['user_id' => $userId, 'signal_id' => $signal->id, 'channel' => $ch['channel'],
                                                    'status' => $error === '' ? 'sent' : 'failed',
                                                    'error' => mb_substr($error, 0, 255), 'created_at' => time()]);
            }
        }
        return $sent;
    }

    /**
     * Plain text to every linked chat of a user: price alerts, announcements, password reset links.
     * ``$capped`` counts it against the daily cap (not for a reset link the user asked for).
     * Returns the number of messages delivered.
     */
    public function sendText(int $userId, string $text, bool $capped = true): int
    {
        if ($capped && $this->sentToday($userId) >= self::DAILY_CAP) {
            return 0;
        }
        $sent = 0;
        foreach ($this->channels($userId) as $ch) {
            $m = $this->messengers[$ch['channel']] ?? null;
            if (!$m || !(int) $ch['enabled']) {
                continue;
            }
            $error = '';
            try {
                $m->sendMessage((string) $ch['chat_id'], $text);
                $sent++;
            } catch (NotifyException $e) {
                $error = $e->getMessage();
            }
            $this->db->insert('notifications', ['user_id' => $userId, 'signal_id' => null, 'channel' => $ch['channel'],
                                                'status' => $error === '' ? 'sent' : 'failed',
                                                'error' => mb_substr($error, 0, 255), 'created_at' => time()]);
        }
        return $sent;
    }

    /** Sends a test message to all of a user's linked channels; returns errors by channel. */
    public function test(int $userId): array
    {
        $errors = [];
        foreach ($this->channels($userId) as $ch) {
            $m = $this->messengers[$ch['channel']] ?? null;
            if (!$m) {
                $errors[$ch['channel']] = 'ربات این پیام‌رسان توسط مدیر تنظیم نشده است.';
                continue;
            }
            try {
                $m->sendMessage((string) $ch['chat_id'], 'پیام آزمایشی ' . $this->settings->system('site_name') . ' ✅');
            } catch (NotifyException $e) {
                $errors[$ch['channel']] = $e->getMessage();
            }
        }
        return $errors;
    }

    private function wants(int $userId, Signal $signal): bool
    {
        $key = $signal->side === Signal::BUY ? 'notify_buy_signals' : 'notify_sell_signals';
        if ($this->settings->forUser($userId, $key) !== '1') {
            return false;
        }
        $min = $this->settings->float($userId, 'notify_min_probability');
        if ($signal->side === Signal::BUY && ($signal->probability ?? 0) < $min) {
            return false;
        }
        return $this->sentToday($userId) < self::DAILY_CAP;
    }

    private function sentToday(int $userId): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'sent' AND created_at > ?",
            [$userId, time() - 86400]);
    }

    private function safeSend(Messenger $m, string $chatId, string $text): void
    {
        try {
            $m->sendMessage($chatId, $text);
        } catch (NotifyException) {
            // A failed courtesy reply must not stop processing other updates.
        }
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Auth;

use Sabadgardan\Core\Database;
use Sabadgardan\Market\Text;
use Sabadgardan\Notify\NotificationService;

/**
 * Self-service password reset through the user's linked Bale/Telegram chat.
 *
 * A one-time 8-digit code is sent rather than a link: a link would be built from the request's Host
 * header, which an attacker controls, and would carry the secret to the attacker's site. The code
 * is stored hashed, expires in TTL seconds, dies after MAX_ATTEMPTS wrong guesses, and at most
 * MAX_PER_HOUR codes are issued per account. Answers never reveal whether an account exists.
 */
final class PasswordReset
{
    public const TTL = 900;
    public const MAX_ATTEMPTS = 5;
    public const MAX_PER_HOUR = 3;

    public function __construct(private Database $db, private NotificationService $notifier)
    {
    }

    /** Sends a code if the account exists, is active and has a linked messenger. Returns whether one was sent. */
    public function request(string $username, string $siteName, int $now): bool
    {
        $user = $this->db->one("SELECT id FROM users WHERE username = ? AND status = 'active'", [Auth::normalizeUsername($username)]);
        if (!$user || !$this->notifier->channels((int) $user['id'])) {
            return false;
        }
        $id = (int) $user['id'];
        $recent = (int) $this->db->value('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?', [$id, $now - 3600]);
        if ($recent >= self::MAX_PER_HOUR) {
            return false;
        }
        $code = sprintf('%08d', random_int(0, 99_999_999));
        // Only the newest code is valid.
        $this->db->run('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$now, $id]);
        $this->db->insert('password_resets', ['user_id' => $id, 'token_hash' => self::hash($id, $code), 'created_at' => $now,
                                              'expires_at' => $now + self::TTL]);
        $text = sprintf("🔑 کد بازیابی رمز عبور %s:\n\n%s %s\n\nاین کد %d دقیقه اعتبار دارد. اگر شما درخواست نکرده‌اید، این پیام را نادیده بگیرید و به کسی ندهید.",
            $siteName, substr($code, 0, 4), substr($code, 4), self::TTL / 60);
        return $this->notifier->sendText($id, $text, false) > 0;
    }

    /** @return string|null error, or null when the password was changed */
    public function reset(string $username, string $code, string $password, int $now): ?string
    {
        $generic = 'کد بازیابی نادرست یا منقضی است.';
        $user = $this->db->one("SELECT id FROM users WHERE username = ? AND status = 'active'", [Auth::normalizeUsername($username)]);
        if (!$user) {
            return $generic;
        }
        $id = (int) $user['id'];
        $row = $this->db->one('SELECT * FROM password_resets WHERE user_id = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1',
            [$id, $now]);
        if (!$row || (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return $generic;
        }
        $code = (string) preg_replace('/\D/', '', Text::normalizeDigits($code));
        if (!hash_equals((string) $row['token_hash'], self::hash($id, $code))) {
            $this->db->run('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?', [(int) $row['id']]);
            return $generic;
        }
        if ($error = Auth::validatePassword($password)) {
            return $error;   // the code stays valid for another try
        }
        $this->db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $id]);
        $this->db->run('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$now, $id]);
        return null;
    }

    public function userId(string $username): ?int
    {
        $id = $this->db->value('SELECT id FROM users WHERE username = ?', [Auth::normalizeUsername($username)]);
        return $id === null ? null : (int) $id;
    }

    private static function hash(int $userId, string $code): string
    {
        return hash('sha256', "$userId:$code");
    }
}

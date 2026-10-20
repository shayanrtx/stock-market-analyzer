<?php

declare(strict_types=1);

namespace Sabadgardan\Auth;

use Sabadgardan\Core\Database;
use Sabadgardan\Http\Session;

/** Username/password authentication with brute-force throttling. */
final class Auth
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_SECONDS = 900;
    public const MIN_PASSWORD = 8;
    public const ACTIVE = 'active';
    public const PENDING = 'pending';
    public const SUSPENDED = 'suspended';
    public const STATUS_LABELS = [self::ACTIVE => 'فعال', self::PENDING => 'در انتظار تأیید', self::SUSPENDED => 'مسدود'];
    public const REGISTER_LIMIT = 5;  // accounts per IP per day

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->user === null && ($id = Session::get('user_id'))) {
            $user = $this->db->one('SELECT id, username, email, display_name, is_admin, status, link_code FROM users WHERE id = ?',
                [(int) $id]);
            // A suspended account loses access immediately, even with a live session.
            $this->user = $user && $user['status'] === self::ACTIVE ? $user : null;
        }
        return $this->user;
    }

    public function id(): int
    {
        return (int) ($this->user()['id'] ?? 0);
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function isAdmin(): bool
    {
        return (bool) ($this->user()['is_admin'] ?? false);
    }

    public function isThrottled(string $ip): bool
    {
        $since = time() - self::WINDOW_SECONDS;
        $count = (int) $this->db->value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?',
            [$ip, $since]);
        return $count >= self::MAX_ATTEMPTS;
    }

    /** Counts toward the per-IP throttle: failed logins, reset-code requests and wrong reset codes. */
    public function recordFailure(string $ip, string $username): void
    {
        $this->db->insert('login_attempts', ['ip' => $ip, 'username' => mb_substr($username, 0, 100), 'attempted_at' => time()]);
    }

    /** @return string|null error message, or null on success */
    public function attempt(string $username, string $password, string $ip): ?string
    {
        if ($this->isThrottled($ip)) {
            return 'تعداد تلاش‌های ناموفق زیاد است. ۱۵ دقیقه بعد دوباره امتحان کنید.';
        }
        $row = $this->db->one('SELECT id, password_hash, status FROM users WHERE username = ?', [self::normalizeUsername($username)]);
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            $this->recordFailure($ip, $username);
            return 'نام کاربری یا رمز عبور اشتباه است.';
        }
        if ($row['status'] === self::PENDING) {
            return 'حساب شما ساخته شده و در انتظار تأیید مدیر است.';
        }
        if ($row['status'] === self::SUSPENDED) {
            return 'این حساب مسدود شده است. با مدیر سامانه تماس بگیرید.';
        }
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
                ['id' => $row['id']]);
        }
        $this->db->run('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        Session::regenerate();
        Session::set('user_id', (int) $row['id']);
        $this->db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);
        $this->user = null;
        return null;
    }

    public function logout(): void
    {
        Session::destroy();
        $this->user = null;
    }

    /** @return string|null validation error */
    public static function validatePassword(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return 'رمز عبور باید دست‌کم ' . self::MIN_PASSWORD . ' کاراکتر باشد.';
        }
        return null;
    }

    /**
     * Validates sign-up input. Usernames: 3-30 latin letters, digits, dot, dash or underscore.
     *
     * @return array<string, string> field => error
     */
    public function validateRegistration(string $username, string $email, string $password, string $confirm): array
    {
        $errors = [];
        $username = self::normalizeUsername($username);
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) {
            $errors['username'] = 'نام کاربری ۳ تا ۳۰ حرف لاتین، عدد، نقطه، خط تیره یا زیرخط باشد.';
        } elseif ($this->db->value('SELECT 1 FROM users WHERE username = ?', [$username])) {
            $errors['username'] = 'این نام کاربری قبلاً ثبت شده است.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'ایمیل معتبر نیست.';
        }
        if ($e = self::validatePassword($password)) {
            $errors['password'] = $e;
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = 'تکرار رمز عبور یکسان نیست.';
        }
        return $errors;
    }

    public function registrationsFromIp(string $ip): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'register' AND ip = ? AND created_at > ?",
            [$ip, date('Y-m-d H:i:s', time() - 86400)]);
    }

    public function createUser(string $username, string $password, string $displayName, bool $admin,
                               string $email = '', string $status = self::ACTIVE): int
    {
        return $this->db->transaction(function (Database $db) use ($username, $password, $displayName, $admin, $email, $status): int {
            $id = $db->insert('users', [
                'username' => self::normalizeUsername($username),
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => $displayName !== '' ? $displayName : $username,
                'is_admin' => $admin ? 1 : 0,
                'status' => $status,
                'link_code' => bin2hex(random_bytes(8)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $db->insert('portfolios', ['user_id' => $id, 'name' => 'سبد اصلی', 'created_at' => date('Y-m-d H:i:s')]);
            return $id;
        });
    }

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    public function changePassword(int $userId, string $current, string $new): ?string
    {
        $hash = (string) $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!password_verify($current, $hash)) {
            return 'رمز عبور فعلی اشتباه است.';
        }
        if ($error = self::validatePassword($new)) {
            return $error;
        }
        $this->db->update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], ['id' => $userId]);
        return null;
    }
}

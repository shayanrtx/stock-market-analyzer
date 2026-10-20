<?php

declare(strict_types=1);

namespace Sabadgardan\Core;

/** Security-relevant events (logins, registrations, admin actions) for the admin panel. */
final class AuditLog
{
    public const LABELS = [
        'login' => 'ورود', 'login_failed' => 'ورود ناموفق', 'logout' => 'خروج', 'register' => 'ثبت‌نام',
        'password_change' => 'تغییر رمز', 'admin_user_status' => 'تغییر وضعیت کاربر',
        'admin_user_role' => 'تغییر نقش کاربر', 'admin_password_reset' => 'بازنشانی رمز توسط مدیر',
        'admin_user_delete' => 'حذف کاربر', 'admin_settings' => 'تغییر تنظیمات سیستم', 'messenger_link' => 'اتصال پیام‌رسان',
        'install' => 'نصب برنامه',
    ];

    public function __construct(private Database $db)
    {
    }

    public function record(?int $userId, string $action, string $detail = '', string $ip = ''): void
    {
        $this->db->insert('audit_log', ['user_id' => $userId, 'action' => $action, 'detail' => mb_substr($detail, 0, 255),
                                        'ip' => $ip, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 100): array
    {
        return $this->db->all('SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                               ORDER BY a.id DESC LIMIT ' . max(1, min(500, $limit)));
    }

    /** Housekeeping: keep the log bounded. */
    public function prune(int $days = 180): void
    {
        $this->db->run('DELETE FROM audit_log WHERE created_at < ?', [date('Y-m-d H:i:s', time() - $days * 86400)]);
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Core;

/**
 * Versioned schema. Each migration runs once, in order, inside a transaction;
 * applied versions are recorded in ``schema_migrations``. Never edit a released
 * migration: add a new one.
 */
final class Migrations
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<int, callable(Database): void> */
    private function migrations(): array
    {
        $pk = $this->db->pk();
        $sfx = $this->db->tableSuffix();
        $text = $this->db->driver === 'mysql' ? 'VARCHAR(191)' : 'TEXT';

        return [
            1 => static function (Database $db) use ($pk, $sfx, $text): void {
                // status: active | pending (awaiting admin approval) | suspended
                $db->pdo->exec("CREATE TABLE users (
                    id $pk,
                    username $text NOT NULL UNIQUE,
                    email VARCHAR(190) NOT NULL DEFAULT '',
                    password_hash VARCHAR(255) NOT NULL,
                    display_name VARCHAR(100) NOT NULL DEFAULT '',
                    is_admin SMALLINT NOT NULL DEFAULT 0,
                    status VARCHAR(10) NOT NULL DEFAULT 'active',
                    link_code VARCHAR(32) NOT NULL DEFAULT '',
                    created_at VARCHAR(19) NOT NULL,
                    last_login_at VARCHAR(19)
                )$sfx");
                $db->pdo->exec("CREATE TABLE login_attempts (
                    id $pk,
                    ip VARCHAR(45) NOT NULL,
                    username VARCHAR(100) NOT NULL,
                    attempted_at INTEGER NOT NULL
                )$sfx");
                $db->pdo->exec('CREATE INDEX idx_login_ip ON login_attempts (ip, attempted_at)');
                $db->pdo->exec("CREATE TABLE portfolios (
                    id $pk,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    name VARCHAR(100) NOT NULL,
                    created_at VARCHAR(19) NOT NULL
                )$sfx");
                $db->pdo->exec("CREATE TABLE instruments (
                    ins_code VARCHAR(25) PRIMARY KEY,
                    symbol VARCHAR(50) NOT NULL,
                    name VARCHAR(150) NOT NULL DEFAULT '',
                    isin VARCHAR(20) NOT NULL DEFAULT '',
                    market VARCHAR(50) NOT NULL DEFAULT '',
                    sector VARCHAR(150) NOT NULL DEFAULT '',
                    updated_at VARCHAR(19) NOT NULL
                )$sfx");
                // type: buy | sell | deposit | withdraw | dividend
                $db->pdo->exec("CREATE TABLE transactions (
                    id $pk,
                    portfolio_id INTEGER NOT NULL REFERENCES portfolios(id) ON DELETE CASCADE,
                    type VARCHAR(10) NOT NULL,
                    ins_code VARCHAR(25),
                    symbol VARCHAR(50),
                    quantity DECIMAL(20,4) NOT NULL DEFAULT 0,
                    price DECIMAL(20,4) NOT NULL DEFAULT 0,
                    fee DECIMAL(20,4) NOT NULL DEFAULT 0,
                    amount DECIMAL(24,4) NOT NULL DEFAULT 0,
                    trade_date VARCHAR(10) NOT NULL,
                    note VARCHAR(255) NOT NULL DEFAULT '',
                    created_at VARCHAR(19) NOT NULL
                )$sfx");
                $db->pdo->exec('CREATE INDEX idx_tx_portfolio ON transactions (portfolio_id, trade_date)');
                $db->pdo->exec("CREATE TABLE watchlist (
                    id $pk,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    ins_code VARCHAR(25) NOT NULL,
                    symbol VARCHAR(50) NOT NULL,
                    created_at VARCHAR(19) NOT NULL,
                    UNIQUE (user_id, ins_code)
                )$sfx");
                // user_id 0 holds system-wide settings managed by admins.
                $db->pdo->exec("CREATE TABLE settings (
                    user_id INTEGER NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    value TEXT NOT NULL,
                    PRIMARY KEY (user_id, name)
                )$sfx");
                $db->pdo->exec("CREATE TABLE live_snapshots (
                    id $pk,
                    day VARCHAR(8) NOT NULL,
                    ins_code VARCHAR(25) NOT NULL,
                    taken_at INTEGER NOT NULL,
                    payload TEXT NOT NULL
                )$sfx");
                $db->pdo->exec('CREATE INDEX idx_snap ON live_snapshots (day, ins_code, taken_at)');
                $db->pdo->exec("CREATE TABLE live_insights (
                    id $pk,
                    day VARCHAR(8) NOT NULL,
                    created_at INTEGER NOT NULL,
                    ins_code VARCHAR(25) NOT NULL,
                    symbol VARCHAR(50) NOT NULL,
                    rule VARCHAR(40) NOT NULL,
                    direction SMALLINT NOT NULL,
                    severity SMALLINT NOT NULL,
                    title VARCHAR(150) NOT NULL,
                    detail TEXT NOT NULL,
                    metrics TEXT NOT NULL
                )$sfx");
                $db->pdo->exec('CREATE INDEX idx_insight_day ON live_insights (day, created_at)');
                $db->pdo->exec("CREATE TABLE signals (
                    id $pk,
                    day VARCHAR(8) NOT NULL,
                    created_at INTEGER NOT NULL,
                    ins_code VARCHAR(25) NOT NULL,
                    symbol VARCHAR(50) NOT NULL,
                    side VARCHAR(4) NOT NULL,
                    entry DECIMAL(20,4) NOT NULL,
                    stop DECIMAL(20,4),
                    target DECIMAL(20,4),
                    probability DECIMAL(6,4),
                    samples INTEGER NOT NULL DEFAULT 0,
                    score DECIMAL(6,2),
                    reasons TEXT NOT NULL,
                    notified SMALLINT NOT NULL DEFAULT 0
                )$sfx");
                $db->pdo->exec('CREATE INDEX idx_signal_day ON signals (day, ins_code)');
                // Messenger links: one chat per user and channel (bale | telegram).
                $db->pdo->exec("CREATE TABLE notification_channels (
                    id $pk,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    channel VARCHAR(10) NOT NULL,
                    chat_id VARCHAR(40) NOT NULL,
                    enabled SMALLINT NOT NULL DEFAULT 1,
                    linked_at VARCHAR(19) NOT NULL,
                    UNIQUE (user_id, channel)
                )$sfx");
                $db->pdo->exec("CREATE TABLE notifications (
                    id $pk,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    signal_id INTEGER,
                    channel VARCHAR(10) NOT NULL,
                    status VARCHAR(10) NOT NULL,
                    error VARCHAR(255) NOT NULL DEFAULT '',
                    created_at INTEGER NOT NULL
                )$sfx");
                $db->pdo->exec('CREATE INDEX idx_notif_user ON notifications (user_id, created_at)');
                $db->pdo->exec("CREATE TABLE audit_log (
                    id $pk,
                    user_id INTEGER,
                    action VARCHAR(50) NOT NULL,
                    detail VARCHAR(255) NOT NULL DEFAULT '',
                    ip VARCHAR(45) NOT NULL DEFAULT '',
                    created_at VARCHAR(19) NOT NULL
                )$sfx");
                $db->pdo->exec('CREATE INDEX idx_audit_time ON audit_log (created_at)');
            },
            // 2: instrument classification inputs (TSE sub-sector and trading board).
            2 => static function (Database $db): void {
                $db->pdo->exec("ALTER TABLE instruments ADD COLUMN sub_sector VARCHAR(150) NOT NULL DEFAULT ''");
                $db->pdo->exec("ALTER TABLE instruments ADD COLUMN board VARCHAR(100) NOT NULL DEFAULT ''");
            },
        ];
    }

    public function current(): int
    {
        $this->db->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, applied_at VARCHAR(19) NOT NULL)');
        return (int) ($this->db->value('SELECT MAX(version) FROM schema_migrations') ?? 0);
    }

    /** @return int number of migrations applied */
    public function migrate(): int
    {
        $current = $this->current();
        $applied = 0;
        foreach ($this->migrations() as $version => $fn) {
            if ($version <= $current) {
                continue;
            }
            // MySQL DDL auto-commits, so wrap only for SQLite where it is transactional.
            if ($this->db->driver === 'sqlite') {
                $this->db->transaction(static fn (Database $db) => $fn($db));
            } else {
                $fn($this->db);
            }
            $this->db->insert('schema_migrations', ['version' => $version, 'applied_at' => date('Y-m-d H:i:s')]);
            $applied++;
        }
        return $applied;
    }

    public function pending(): bool
    {
        return $this->current() < max(array_keys($this->migrations()));
    }
}

<?php

declare(strict_types=1);

namespace Sabadgardan\Core;

use PDO;
use PDOStatement;

/** Thin PDO wrapper: prepared statements only, SQLite or MySQL. */
final class Database
{
    public function __construct(public readonly PDO $pdo, public readonly string $driver)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public static function fromConfig(Config $config, string $root): self
    {
        if ($config->get('db.driver') === 'mysql') {
            $m = $config->get('db.mysql');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $m['host'], (int) $m['port'], $m['database'], $m['charset'] ?? 'utf8mb4');
            return new self(new PDO($dsn, $m['username'], $m['password']), 'mysql');
        }
        $path = (string) $config->get('db.sqlite_path');
        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            $path = $root . '/' . $path;
        }
        return new self(new PDO('sqlite:' . $path), 'sqlite');
    }

    public static function memory(): self
    {
        return new self(new PDO('sqlite::memory:'), 'sqlite');
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?')));
        $this->run($sql, array_values($row));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $row, array $where): int
    {
        $set = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($row)));
        $cond = implode(' AND ', array_map(static fn ($c) => "$c = ?", array_keys($where)));
        return $this->run("UPDATE $table SET $set WHERE $cond", [...array_values($row), ...array_values($where)])
            ->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Column type for an auto-increment primary key, per driver. */
    public function pk(): string
    {
        return $this->driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    public function tableSuffix(): string
    {
        return $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    }
}

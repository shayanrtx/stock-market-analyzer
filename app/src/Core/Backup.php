<?php

declare(strict_types=1);

namespace Sabadgardan\Core;

/**
 * Daily database backups in storage/backups, newest KEEP kept. SQLite is copied consistently with
 * ``VACUUM INTO`` (a ready-to-use database file: replace the old one to restore); MySQL is dumped as
 * SQL that phpMyAdmin or the mysql client can import. Both are gzip-compressed when zlib is present.
 * The folder is closed to the web by .htaccess; admins download through the panel.
 */
final class Backup
{
    public const KEEP = 7;
    public const EVERY = 20 * 3600;   // "daily", with slack so a late cron run doesn't skip a day
    private const NAME = '/^backup-\d{4}-\d{2}-\d{2}-\d{6}\.(sqlite|sql)(\.gz)?$/';

    public function __construct(private Database $db, private string $dir)
    {
    }

    public function due(int $now): bool
    {
        $latest = $this->list()[0] ?? null;
        return !$latest || $now - $latest['time'] >= self::EVERY;
    }

    /** @return string file name of the new backup */
    public function create(int $now): string
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true)) {
            throw new \RuntimeException('پوشه‌ی پشتیبان قابل ساخت نیست: ' . $this->dir);
        }
        $stamp = date('Y-m-d-His', $now);
        if ($this->db->driver === 'sqlite') {
            $raw = "{$this->dir}/backup-$stamp.sqlite";
            $this->db->pdo->exec('VACUUM INTO ' . $this->db->pdo->quote($raw));
        } else {
            $raw = "{$this->dir}/backup-$stamp.sql";
            $this->dumpMysql($raw);
        }
        $file = $this->compress($raw);
        $this->prune();
        return basename($file);
    }

    /** @return list<array{name: string, size: int, time: int}> newest first */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->dir . '/backup-*') ?: [] as $path) {
            if (preg_match(self::NAME, basename($path))) {
                $out[] = ['name' => basename($path), 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
            }
        }
        usort($out, static fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Full path of a backup by name, or null for anything that isn't one (no path tricks). */
    public function path(string $name): ?string
    {
        $path = $this->dir . '/' . $name;
        return preg_match(self::NAME, $name) && is_file($path) ? $path : null;
    }

    public function prune(): void
    {
        foreach (array_slice($this->list(), self::KEEP) as $old) {
            @unlink($this->dir . '/' . $old['name']);
        }
    }

    private function compress(string $raw): string
    {
        if (!function_exists('gzopen')) {
            return $raw;
        }
        $in = fopen($raw, 'rb');
        $out = gzopen("$raw.gz", 'wb6');
        if (!$in || !$out) {
            return $raw;
        }
        while (!feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        unlink($raw);
        return "$raw.gz";
    }

    private function dumpMysql(string $file): void
    {
        $pdo = $this->db->pdo;
        $fh = fopen($file, 'wb');
        if (!$fh) {
            throw new \RuntimeException('فایل پشتیبان قابل نوشتن نیست.');
        }
        fwrite($fh, "-- Sabadgardan backup " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n$create;\n\n");
            $rows = $pdo->query("SELECT * FROM `$table`", \PDO::FETCH_ASSOC);
            $batch = [];
            foreach ($rows as $row) {
                $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) === 200) {
                    fwrite($fh, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($fh, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
    }
}

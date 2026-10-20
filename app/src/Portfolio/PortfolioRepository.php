<?php

declare(strict_types=1);

namespace Sabadgardan\Portfolio;

use Sabadgardan\Core\Database;

/**
 * Persistence for portfolios, transactions and watchlists. Every method takes the owner's
 * user id and filters by it, so one user can never read or change another user's data.
 */
final class PortfolioRepository
{
    public function __construct(private Database $db)
    {
    }

    // ------------------------------------------------------------- portfolios

    /** @return list<array<string, mixed>> */
    public function portfolios(int $userId): array
    {
        return $this->db->all('SELECT p.*, (SELECT COUNT(*) FROM transactions t WHERE t.portfolio_id = p.id) AS tx_count
                               FROM portfolios p WHERE p.user_id = ? ORDER BY p.id', [$userId]);
    }

    /** @return array<string, mixed>|null */
    public function portfolio(int $userId, int $portfolioId): ?array
    {
        return $this->db->one('SELECT * FROM portfolios WHERE id = ? AND user_id = ?', [$portfolioId, $userId]);
    }

    /** @return array<string, mixed> the user's first portfolio, created if missing */
    public function defaultPortfolio(int $userId): array
    {
        $p = $this->db->one('SELECT * FROM portfolios WHERE user_id = ? ORDER BY id LIMIT 1', [$userId]);
        if ($p) {
            return $p;
        }
        $id = $this->createPortfolio($userId, 'سبد اصلی');
        return (array) $this->portfolio($userId, $id);
    }

    public function createPortfolio(int $userId, string $name): int
    {
        return $this->db->insert('portfolios', ['user_id' => $userId, 'name' => self::cleanName($name),
                                                'created_at' => date('Y-m-d H:i:s')]);
    }

    public function renamePortfolio(int $userId, int $portfolioId, string $name): bool
    {
        return $this->db->run('UPDATE portfolios SET name = ? WHERE id = ? AND user_id = ?',
            [self::cleanName($name), $portfolioId, $userId])->rowCount() > 0;
    }

    public function deletePortfolio(int $userId, int $portfolioId): bool
    {
        if (count($this->portfolios($userId)) <= 1) {
            throw new \DomainException('حداقل یک سبد باید باقی بماند.');
        }
        return $this->db->transaction(function (Database $db) use ($userId, $portfolioId): bool {
            if (!$this->portfolio($userId, $portfolioId)) {
                return false;
            }
            $db->run('DELETE FROM transactions WHERE portfolio_id = ?', [$portfolioId]);
            return $db->run('DELETE FROM portfolios WHERE id = ? AND user_id = ?', [$portfolioId, $userId])->rowCount() > 0;
        });
    }

    private static function cleanName(string $name): string
    {
        $name = trim(mb_substr($name, 0, 100));
        return $name !== '' ? $name : 'سبد بدون نام';
    }

    // ----------------------------------------------------------- transactions

    /** @return list<Transaction> */
    public function transactions(int $userId, int $portfolioId): array
    {
        $rows = $this->db->all('SELECT t.* FROM transactions t JOIN portfolios p ON p.id = t.portfolio_id
                                WHERE t.portfolio_id = ? AND p.user_id = ? ORDER BY t.trade_date, t.id',
            [$portfolioId, $userId]);
        return array_map([Transaction::class, 'fromRow'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function transactionRow(int $userId, int $txId): ?array
    {
        return $this->db->one('SELECT t.* FROM transactions t JOIN portfolios p ON p.id = t.portfolio_id
                               WHERE t.id = ? AND p.user_id = ?', [$txId, $userId]);
    }

    /** Validates against the ledger (no overselling) and saves; returns the id. */
    public function saveTransaction(int $userId, int $portfolioId, Transaction $t): int
    {
        if (!$this->portfolio($userId, $portfolioId)) {
            throw new \DomainException('سبد پیدا نشد.');
        }
        Ledger::validateSave($this->transactions($userId, $portfolioId), $t);
        $row = [
            'type' => $t->type, 'ins_code' => $t->insCode, 'symbol' => $t->symbol, 'quantity' => $t->quantity,
            'price' => $t->price, 'fee' => $t->fee, 'amount' => $t->amount, 'trade_date' => $t->date,
            'note' => mb_substr($t->note, 0, 255),
        ];
        if ($t->id !== null) {
            if (!$this->transactionRow($userId, $t->id)) {
                throw new \DomainException('تراکنش پیدا نشد.');
            }
            $this->db->update('transactions', $row, ['id' => $t->id, 'portfolio_id' => $portfolioId]);
            return $t->id;
        }
        return $this->db->insert('transactions', $row + ['portfolio_id' => $portfolioId,
                                                         'created_at' => date('Y-m-d H:i:s')]);
    }

    public function deleteTransaction(int $userId, int $txId): bool
    {
        $row = $this->transactionRow($userId, $txId);
        if (!$row) {
            return false;
        }
        Ledger::validateDelete($this->transactions($userId, (int) $row['portfolio_id']), $txId);
        return $this->db->run('DELETE FROM transactions WHERE id = ?', [$txId])->rowCount() > 0;
    }

    // -------------------------------------------------------------- watchlist

    /** @return list<array<string, mixed>> */
    public function watchlist(int $userId): array
    {
        return $this->db->all('SELECT w.*, i.name FROM watchlist w LEFT JOIN instruments i ON i.ins_code = w.ins_code
                               WHERE w.user_id = ? ORDER BY w.id', [$userId]);
    }

    public function watch(int $userId, string $insCode, string $symbol): void
    {
        if (!$this->db->value('SELECT 1 FROM watchlist WHERE user_id = ? AND ins_code = ?', [$userId, $insCode])) {
            $this->db->insert('watchlist', ['user_id' => $userId, 'ins_code' => $insCode, 'symbol' => $symbol,
                                            'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    public function unwatch(int $userId, string $insCode): void
    {
        $this->db->run('DELETE FROM watchlist WHERE user_id = ? AND ins_code = ?', [$userId, $insCode]);
    }

    // ------------------------------------------------------- live monitoring

    /**
     * Symbols to monitor for every active user: open positions (held) and watchlist entries.
     *
     * @return array<string, array{symbol: string, holders: list<int>, watchers: list<int>}>
     */
    public function monitoredSymbols(): array
    {
        $out = [];
        $users = array_column($this->db->all("SELECT id FROM users WHERE status = 'active'"), 'id');
        foreach ($users as $uid) {
            foreach ($this->portfolios((int) $uid) as $p) {
                foreach ((new Ledger($this->transactions((int) $uid, (int) $p['id'])))->openPositions() as $pos) {
                    $out[$pos['ins_code']]['symbol'] = (string) $pos['symbol'];
                    $out[$pos['ins_code']]['holders'][] = (int) $uid;
                }
            }
            foreach ($this->watchlist((int) $uid) as $w) {
                $out[$w['ins_code']]['symbol'] ??= (string) $w['symbol'];
                $out[$w['ins_code']]['watchers'][] = (int) $uid;
            }
        }
        foreach ($out as &$row) {
            $row['holders'] = array_values(array_unique($row['holders'] ?? []));
            $row['watchers'] = array_values(array_unique($row['watchers'] ?? []));
        }
        return $out;
    }
}

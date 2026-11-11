<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/**
 * Store on PDO, for the tables in schema/. Tested on SQLite. On MySQL and PostgreSQL each
 * withLock() call is one transaction at the default isolation level; see the README.
 */
final class PdoStore implements Store
{
    private const COLUMNS = 'entry_key, type, referrer_id, referee_id, earning_id, level, amount, base_amount, uncollected, occurred_at, available_at';

    private int $savepoint = 0;

    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function getReferral(string $refereeId): ?array
    {
        $st = $this->pdo->prepare('SELECT referee_id, referrer_id, attributed_at, code FROM referrals WHERE referee_id = ?');
        $st->execute([$refereeId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return ['refereeId' => (string)$r['referee_id'], 'referrerId' => (string)$r['referrer_id'], 'attributedAt' => (int)$r['attributed_at'], 'code' => $r['code'] === null ? null : (string)$r['code']];
    }

    public function insertReferral(array $referral): bool
    {
        return $this->atomic(function () use ($referral): bool {
            $this->pdo->prepare('INSERT INTO referrals (referee_id, referrer_id, attributed_at, code) VALUES (?, ?, ?, ?)')
                ->execute([$referral['refereeId'], $referral['referrerId'], $referral['attributedAt'], $referral['code']]);
            return true;
        });
    }

    public function listEntries(array $filter = []): array
    {
        $where = [];
        $args = [];
        foreach (['referrerId' => 'referrer_id', 'refereeId' => 'referee_id', 'earningId' => 'earning_id'] as $key => $column) {
            if (isset($filter[$key])) {
                $where[] = "{$column} = ?";
                $args[] = (string)$filter[$key];
            }
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM referral_ledger' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id';
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'key' => (string)$r['entry_key'],
                'type' => (string)$r['type'],
                'referrerId' => (string)$r['referrer_id'],
                'refereeId' => $r['referee_id'] === null ? null : (string)$r['referee_id'],
                'earningId' => $r['earning_id'] === null ? null : (string)$r['earning_id'],
                'level' => $r['level'] === null ? null : (int)$r['level'],
                'amount' => (int)$r['amount'],
                'baseAmount' => (int)$r['base_amount'],
                'uncollected' => (int)$r['uncollected'],
                'at' => (int)$r['occurred_at'],
                'availableAt' => (int)$r['available_at'],
            ];
        }
        return $out;
    }

    public function appendEntries(array $entries): bool
    {
        return $this->atomic(function () use ($entries): bool {
            $st = $this->pdo->prepare('INSERT INTO referral_ledger (' . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($entries as $e) {
                $st->execute([$e['key'], $e['type'], $e['referrerId'], $e['refereeId'], $e['earningId'], $e['level'], $e['amount'], $e['baseAmount'], $e['uncollected'], $e['at'], $e['availableAt']]);
            }
            return true;
        });
    }

    public function withLock(string $key, callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Runs $fn all-or-nothing and turns a unique-key violation into false. */
    private function atomic(callable $fn): bool
    {
        $nested = $this->pdo->inTransaction();
        $name = 'referral_' . ++$this->savepoint;
        $nested ? $this->pdo->exec("SAVEPOINT {$name}") : $this->pdo->beginTransaction();
        try {
            $fn();
            $nested ? $this->pdo->exec("RELEASE SAVEPOINT {$name}") : $this->pdo->commit();
            return true;
        } catch (\PDOException $e) {
            $nested ? $this->pdo->exec("ROLLBACK TO SAVEPOINT {$name}") : $this->pdo->rollBack();
            if (in_array((string)$e->getCode(), ['23000', '23505'], true)) {
                return false;
            }
            throw $e;
        }
    }
}

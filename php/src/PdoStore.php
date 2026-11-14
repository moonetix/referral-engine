<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/**
 * Store on PDO, for the tables in schema/. Tested on SQLite, which allows one writer at a
 * time. On PostgreSQL and MySQL withLock() also takes a lock named after its key, because a
 * transaction alone lets two workers read the same balance; see the README.
 */
final class PdoStore implements Store
{
    private const COLUMNS = 'entry_key, type, referrer_id, referee_id, earning_id, level, amount, base_amount, uncollected, occurred_at, available_at';
    private const LOCK_WAIT_SECONDS = 10;

    private int $savepoint = 0;
    private string $driver;
    private int $depth = 0;
    /** @var list<string> MySQL lock names, released when the outermost withLock() returns */
    private array $held = [];

    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->driver = (string)$this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
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
        $outer = !$this->pdo->inTransaction();
        $this->depth++;
        try {
            if ($this->driver === 'mysql') {
                // Before the transaction starts, so that its first read sees what the last holder committed.
                $this->lockMysql($key);
            }
            if ($outer) {
                $this->pdo->beginTransaction();
            }
            if ($this->driver === 'pgsql') {
                $this->lockPgsql($key);
            }
            $result = $fn();
            if ($outer) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($outer && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            if (--$this->depth === 0) {
                $this->unlockMysql();
            }
        }
    }

    /** A named lock per key. It outlives transactions, so unlockMysql() releases it after the commit or rollback. */
    private function lockMysql(string $key): void
    {
        $name = 'referral:' . sha1($key);
        if (in_array($name, $this->held, true)) {
            return;
        }
        $st = $this->pdo->prepare('SELECT GET_LOCK(?, ' . self::LOCK_WAIT_SECONDS . ')');
        $st->execute([$name]);
        $got = (int)$st->fetchColumn();
        $st->closeCursor();
        if ($got !== 1) {
            Util::fail('conflict', "could not lock {$key} within " . self::LOCK_WAIT_SECONDS . ' seconds; retry the call');
        }
        $this->held[] = $name;
    }

    private function unlockMysql(): void
    {
        foreach (array_splice($this->held, 0) as $name) {
            $st = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $st->execute([$name]);
            $st->closeCursor();
        }
    }

    /** An advisory lock per key, held until the surrounding transaction ends. */
    private function lockPgsql(string $key): void
    {
        $hash = sha1($key);
        $this->pdo->prepare('SELECT pg_advisory_xact_lock(CAST(? AS integer), CAST(? AS integer))')
            ->execute([hexdec(substr($hash, 0, 7)), hexdec(substr($hash, 7, 7))]);
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

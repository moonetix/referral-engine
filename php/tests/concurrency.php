<?php

declare(strict_types=1);

// Parallel payout test on a real database: php php/tests/concurrency.php
//
// Point REFERRAL_TEST_DSN (with REFERRAL_TEST_USER and REFERRAL_TEST_PASSWORD) at a throwaway
// mysql:, pgsql: or sqlite: database. The script DROPS and recreates the referral tables there
// from schema/, then starts worker processes, each with its own connection, that call
// recordPayout at the same moment. One balance must be paid exactly once, and payouts for
// different referrers must not get in each other's way. Without a DSN the test is skipped.
//
// REFERRAL_TEST_WORKERS (default 8) and REFERRAL_TEST_ROUNDS (default 5) change the load.
// REFERRAL_TEST_LOCK is a control for the test itself: "transaction" gives the workers a store
// that opens a transaction but takes no lock, "none" a store that does neither. With either one
// the run is expected to FAIL on PostgreSQL and MySQL, which shows that the checks below notice a
// missing lock. SQLite refuses a second writer by itself, so only "none" fails there.

require __DIR__ . '/../autoload.php';

use Moonetix\ReferralEngine\PdoStore;
use Moonetix\ReferralEngine\ReferralEngine;
use Moonetix\ReferralEngine\ReferralException;
use Moonetix\ReferralEngine\Store;

const EARNING = 5000;
const PAYOUT = 500; // the 10% commission on one earning: exactly one payout fits the balance
const START_DELAY_SECONDS = 1.0;

/** Control only: PdoStore's reads and writes without its lock, with the gap before the insert held open. */
final class WithoutLock implements Store
{
    public function __construct(private Store $inner, private PDO $pdo, private bool $transaction)
    {
    }

    public function getReferral(string $refereeId): ?array
    {
        return $this->inner->getReferral($refereeId);
    }

    public function insertReferral(array $referral): bool
    {
        return $this->inner->insertReferral($referral);
    }

    public function listEntries(array $filter = []): array
    {
        return $this->inner->listEntries($filter);
    }

    public function appendEntries(array $entries): bool
    {
        usleep(200000);
        return $this->inner->appendEntries($entries);
    }

    public function withLock(string $key, callable $fn): mixed
    {
        if (!$this->transaction || $this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}

function connect(): PDO
{
    $user = getenv('REFERRAL_TEST_USER');
    $password = getenv('REFERRAL_TEST_PASSWORD');
    return new PDO((string)getenv('REFERRAL_TEST_DSN'), $user === false ? null : $user, $password === false ? null : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function engine(PDO $pdo, string $control = ''): ReferralEngine
{
    $store = new PdoStore($pdo);
    if ($control !== '') {
        $store = new WithoutLock($store, $pdo, $control === 'transaction');
    }
    return new ReferralEngine(['store' => $store, 'plan' => ['type' => 'percentage', 'rateBps' => 1000]]);
}

// ---------------------------------------------------------------- worker: one payout, one line of JSON

if (($argv[1] ?? '') === 'worker') {
    $result = ['outcome' => 'error', 'detail' => '', 'busy' => 0];
    try {
        $pdo = connect();
        $engine = engine($pdo, (string)getenv('REFERRAL_TEST_LOCK'));
        $wait = (float)$argv[4] - microtime(true);
        if ($wait > 0) {
            usleep((int)($wait * 1000000));
        }
        while (true) {
            try {
                $result['outcome'] = $engine->recordPayout(['payoutId' => $argv[3], 'referrerId' => $argv[2], 'amount' => PAYOUT, 'at' => 10])['status'];
                break;
            } catch (ReferralException $e) {
                $result['outcome'] = $e->errorCode;
                break;
            } catch (PDOException $e) {
                // SQLite does not queue a second writer, it refuses it. Call again, as an application would.
                $busy = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($e->getMessage(), 'database is locked');
                if (!$busy || ++$result['busy'] > 200) {
                    throw $e;
                }
                usleep(random_int(1000, 20000));
            }
        }
    } catch (Throwable $e) {
        $result['detail'] = get_class($e) . ': ' . $e->getMessage();
    }
    echo json_encode($result), "\n";
    exit(0);
}

// ---------------------------------------------------------------- coordinator

if ((string)getenv('REFERRAL_TEST_DSN') === '') {
    echo "skipped: set REFERRAL_TEST_DSN to a throwaway mysql:, pgsql: or sqlite: database to run the parallel payout test\n";
    exit(0);
}

/**
 * Starts one worker per job at the same instant and returns what each one printed.
 *
 * @param list<array{string, string}> $jobs referrer ID and payout ID per worker
 * @return list<array{outcome: string, detail: string, busy: int}>
 */
function runWorkers(array $jobs): array
{
    $startAt = sprintf('%.6F', microtime(true) + START_DELAY_SECONDS);
    $running = [];
    foreach ($jobs as [$referrer, $payoutId]) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $referrer, $payoutId, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $running[] = [$process, $pipes];
    }
    $results = [];
    foreach ($running as [$process, $pipes]) {
        $stdout = trim((string)stream_get_contents($pipes[1]));
        $stderr = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $result = json_decode($stdout, true);
        $results[] = is_array($result) && $code === 0 && $stderr === ''
            ? $result
            : ['outcome' => 'error', 'detail' => "worker exit {$code}: {$stdout} {$stderr}", 'busy' => 0];
    }
    return $results;
}

/** "1 recorded, 7 insufficient_balance", plus the first unexpected error and the SQLite retries. */
function describe(array $results): string
{
    $parts = [];
    foreach (array_count_values(array_column($results, 'outcome')) as $outcome => $count) {
        $parts[] = "{$count} {$outcome}";
    }
    $text = implode(', ', $parts);
    $busy = array_sum(array_column($results, 'busy'));
    if ($busy > 0) {
        $text .= " ({$busy} retries after \"database is locked\")";
    }
    foreach ($results as $r) {
        if ($r['outcome'] === 'error') {
            return $text . '; first error: ' . $r['detail'];
        }
    }
    return $text;
}

try {
    $pdo = connect();
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $schemas = ['mysql' => 'mysql.sql', 'pgsql' => 'postgresql.sql', 'sqlite' => 'sqlite.sql'];
    if (!isset($schemas[$driver])) {
        throw new RuntimeException("no schema for the PDO driver \"{$driver}\"");
    }
    foreach (['referral_ledger', 'referrals', 'referral_codes'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }
    $schema = (string)file_get_contents(__DIR__ . '/../../schema/' . $schemas[$driver]);
    // MySQL takes one statement per call. The other two schemas have statements with inner semicolons.
    foreach ($driver === 'mysql' ? preg_split('/;\s*$/m', $schema) : [$schema] as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'setup failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(2);
}

$workers = max(2, (int)(getenv('REFERRAL_TEST_WORKERS') ?: 8));
$rounds = max(1, (int)(getenv('REFERRAL_TEST_ROUNDS') ?: 5));
$control = (string)getenv('REFERRAL_TEST_LOCK');
echo "{$driver}: {$workers} workers, {$rounds} rounds" . ($control !== '' ? ", control run without the lock ({$control}): expected to fail on PostgreSQL and MySQL" : '') . "\n";

$engine = engine($pdo);
$seed = static function (string $referrer) use ($engine): void {
    $engine->attach(['refereeId' => "friend-of-{$referrer}", 'referrerId' => $referrer, 'at' => 0]);
    $engine->recordEarning(['earningId' => "earning-of-{$referrer}", 'refereeId' => "friend-of-{$referrer}", 'amount' => EARNING, 'at' => 1]);
};
$payoutRows = static function (string $referrer) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM referral_ledger WHERE referrer_id = ? AND type = 'payout'");
    $st->execute([$referrer]);
    return (int)$st->fetchColumn();
};
$failed = 0;
$check = static function (bool $ok, string $line) use (&$failed): void {
    echo ($ok ? 'ok    ' : 'FAIL  ') . $line . "\n";
    $failed += $ok ? 0 : 1;
};

for ($round = 1; $round <= $rounds; $round++) {
    // One referrer with one payable balance. Every worker asks for all of it under its own payout ID.
    $referrer = "one-{$round}";
    $seed($referrer);
    $jobs = [];
    for ($i = 1; $i <= $workers; $i++) {
        $jobs[] = [$referrer, "payout-{$round}-{$i}"];
    }
    $results = runWorkers($jobs);
    $tally = array_count_values(array_column($results, 'outcome'));
    $refused = ($tally['insufficient_balance'] ?? 0) + ($tally['conflict'] ?? 0);
    $available = $engine->balance($referrer, 100)['available'];
    $rows = $payoutRows($referrer);
    $check(
        ($tally['recorded'] ?? 0) === 1 && $refused === $workers - 1 && $available === 0 && $rows === 1,
        "round {$round}, one referrer: " . describe($results) . "; balance {$available}; payout rows {$rows}"
    );

    // One referrer per worker. The lock for one key must not turn payouts for other keys into failures.
    $jobs = [];
    for ($i = 1; $i <= $workers; $i++) {
        $seed("many-{$round}-{$i}");
        $jobs[] = ["many-{$round}-{$i}", "payout-many-{$round}-{$i}"];
    }
    $results = runWorkers($jobs);
    $tally = array_count_values(array_column($results, 'outcome'));
    $settled = 0;
    foreach ($jobs as [$own]) {
        $settled += $engine->balance($own, 100)['available'] === 0 && $payoutRows($own) === 1 ? 1 : 0;
    }
    $check(
        ($tally['recorded'] ?? 0) === $workers && $settled === $workers,
        "round {$round}, {$workers} referrers: " . describe($results) . "; {$settled} of {$workers} balances paid once"
    );
}

$total = 2 * $rounds;
echo ($total - $failed) . "/{$total} rounds passed" . ($failed > 0 ? ", {$failed} failed" : '') . "\n";
exit($failed > 0 ? 1 : 0);

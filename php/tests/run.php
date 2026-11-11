<?php

declare(strict_types=1);

// Plain test runner: php php/tests/run.php
// Runs the shared vectors in test-vectors/vectors.json, the engine vectors again on
// PdoStore with schema/sqlite.sql when pdo_sqlite is available, and PHP-specific checks.

require __DIR__ . '/../autoload.php';

use Moonetix\ReferralEngine\MemoryStore;
use Moonetix\ReferralEngine\PdoStore;
use Moonetix\ReferralEngine\Referral as R;
use Moonetix\ReferralEngine\ReferralEngine;
use Moonetix\ReferralEngine\ReferralException;
use Moonetix\ReferralEngine\Util;

$vectors = json_decode((string)file_get_contents(__DIR__ . '/../../test-vectors/vectors.json'), true, 512, JSON_THROW_ON_ERROR);

/** Sort object keys recursively so results compare by content, like assert.deepEqual. */
function canon(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    $v = array_map('canon', $v);
    if (!array_is_list($v)) {
        ksort($v);
    }
    return $v;
}

function bytesSource(string $hex): Closure
{
    $buf = (string)hex2bin($hex);
    $pos = 0;
    return static function (int $n) use ($buf, &$pos): string {
        $out = substr($buf, $pos, $n);
        $pos += strlen($out);
        return $out;
    };
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        return;
    }
    $fail++;
    fwrite(STDERR, "FAIL {$label}" . ($detail !== '' ? ": {$detail}" : '') . "\n");
};

/** Runs one case: compares the result, or the error code when "throws" is set. */
$expect = static function (callable $run, array $c, string $label) use ($check): void {
    try {
        $got = $run();
    } catch (ReferralException $e) {
        if (isset($c['throws'])) {
            $check($e->errorCode === $c['throws'], $label, "error {$e->errorCode} !== {$c['throws']}");
        } else {
            $check(false, $label, "unexpected error {$e->errorCode}: {$e->getMessage()}");
        }
        return;
    } catch (Throwable $e) {
        $check(false, $label, get_class($e) . ': ' . $e->getMessage());
        return;
    }
    if (isset($c['throws'])) {
        $check(false, $label, "expected error {$c['throws']}");
        return;
    }
    $check(canon($got) === canon($c['out']), $label, json_encode($got) . ' !== ' . json_encode($c['out']));
};

$runners = [
    'generateCode' => fn(array $c) => R::generateCode(($c['options'] ?? []) + ['randomBytes' => bytesSource($c['bytes'])]),
    'checkCharFor' => fn(array $c) => R::checkCharFor($c['code'], $c['options'] ?? []),
    'normalizeCode' => fn(array $c) => R::normalizeCode($c['in'], $c['options'] ?? []),
    'isValidCode' => fn(array $c) => R::isValidCode($c['in'], $c['options'] ?? []),
    'formatCode' => fn(array $c) => R::formatCode($c['in'], $c['options'] ?? []),
    'ipPrefix' => fn(array $c) => R::ipPrefix($c['in'], $c['options'] ?? []),
    'attribute' => fn(array $c) => R::attribute($c['input']),
    'definePlan' => fn(array $c) => R::definePlan($c['in']),
    'commissionFor' => fn(array $c) => R::commissionFor($c['plan'], $c['amount'], $c['stats'] ?? []),
    'findRings' => fn(array $c) => R::findRings($c['edges']),
    'fraudSignals' => fn(array $c) => R::fraudSignals($c['input']),
];

$groups = array_keys(array_filter($vectors, 'is_array'));
sort($groups);
$names = array_merge(array_keys($runners), ['engine']);
sort($names);
$check($groups === $names, 'every vector group has a runner', implode(',', array_diff($groups, $names)));

foreach ($runners as $group => $run) {
    foreach ($vectors[$group] as $i => $c) {
        $expect(fn() => $run($c), $c, "{$group}[{$i}]" . (isset($c['name']) ? ' ' . $c['name'] : ''));
    }
}

$runEngine = static function (string $storeName, callable $makeStore) use ($vectors, $expect): void {
    foreach ($vectors['engine'] as $c) {
        $label = "engine {$storeName}: {$c['name']}";
        if (isset($c['throws'])) {
            $expect(fn() => new ReferralEngine($c['config'] + ['store' => $makeStore()]), $c, $label);
            continue;
        }
        $engine = new ReferralEngine($c['config'] + ['store' => $makeStore()]);
        foreach ($c['steps'] as $i => $s) {
            $run = match ($s['op']) {
                'attach' => fn() => $engine->attach($s['input']),
                'recordEarning' => fn() => $engine->recordEarning($s['input']),
                'reverseEarning' => fn() => $engine->reverseEarning($s['input']),
                'recordPayout' => fn() => $engine->recordPayout($s['input']),
                'recordAdjustment' => fn() => $engine->recordAdjustment($s['input']),
                'balance' => fn() => $engine->balance($s['referrerId'], $s['at']),
                'ledger' => fn() => $engine->ledger($s['referrerId']),
            };
            $expect($run, $s, "{$label} step {$i} {$s['op']}");
        }
    }
};

$runEngine('MemoryStore', fn() => new MemoryStore());

$hasSqlite = in_array('sqlite', PDO::getAvailableDrivers(), true);
$schema = (string)file_get_contents(__DIR__ . '/../../schema/sqlite.sql');
$freshPdo = static function () use ($schema): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec($schema);
    return $pdo;
};
if ($hasSqlite) {
    $runEngine('PdoStore', fn() => new PdoStore($freshPdo()));

    $pdo = $freshPdo();
    $engine = new ReferralEngine(['store' => new PdoStore($pdo), 'plan' => ['type' => 'percentage', 'rateBps' => 1000]]);
    $engine->attach(['refereeId' => 'b', 'referrerId' => 'a', 'at' => 0]);
    $engine->recordEarning(['earningId' => 'e1', 'refereeId' => 'b', 'amount' => 1000, 'at' => 1]);
    foreach (['UPDATE referral_ledger SET amount = 1', 'DELETE FROM referral_ledger'] as $sql) {
        try {
            $pdo->exec($sql);
            $check(false, "ledger refuses: {$sql}");
        } catch (PDOException $e) {
            $check(str_contains($e->getMessage(), 'append-only'), "ledger refuses: {$sql}", $e->getMessage());
        }
    }
} else {
    fwrite(STDERR, "skip: pdo_sqlite not available, PdoStore not tested\n");
}

// PHP-specific checks.
$check(Util::mulDiv(9007199254740991, 9007199254740991, 9007199254740991) === 9007199254740991, 'mulDiv beyond 64-bit products');
$check(Util::mulDiv(9007199254740991, 9007199254740990, 9007199254740991) === 9007199254740990, 'mulDiv beyond 64-bit products, near max');
$check(Util::mulDiv(123456789012345, 98765, 1000003) === 12193173187284, 'mulDiv against a BigInt result');
try {
    Util::mulDiv(9007199254740991, 3, 2);
    $check(false, 'mulDiv overflow');
} catch (ReferralException $e) {
    $check($e->errorCode === 'overflow', 'mulDiv overflow');
}

for ($i = 0; $i < 200; $i++) {
    $code = R::generateCode(['checkChar' => true]);
    $check(preg_match('/^[23456789ABCDEFGHJKMNPQRSTVWXY]{9}$/', $code) === 1 && R::isValidCode($code, ['checkChar' => true]), "random code {$code}");
}

$calls = 0;
$code = R::createUniqueCode(function (string $c) use (&$calls): bool {
    return ++$calls < 3;
});
$check($calls === 3 && strlen($code) === 8, 'createUniqueCode retries');
try {
    R::createUniqueCode(fn() => true, ['maxAttempts' => 4]);
    $check(false, 'createUniqueCode gives up');
} catch (ReferralException $e) {
    $check($e->errorCode === 'code_space_exhausted', 'createUniqueCode gives up');
}

$custom = R::attribute([
    'signup' => ['userId' => 'u1', 'at' => 100],
    'touches' => [['referrerId' => 'a', 'at' => 150, 'source' => 'typed']],
    'rules' => ['checks' => [R::selfReferral(), fn(array $ctx) => $ctx['touch']['source'] === 'typed' ? ['code' => 'typed_code', 'action' => 'review', 'message' => 'Code typed by hand.'] : null]],
]);
$check($custom['decision'] === 'review' && $custom['reasons'][0]['code'] === 'typed_code', 'custom check callable');

$wallet = ['a' => 30];
$engine = new ReferralEngine([
    'store' => new MemoryStore(),
    'plan' => ['type' => 'percentage', 'rateBps' => 1000],
    'allowNegativeBalance' => false,
    'availableBalance' => fn(string $id) => $wallet[$id] ?? 0,
]);
$engine->attach(['refereeId' => 'b', 'referrerId' => 'a', 'at' => 0]);
$engine->recordEarning(['earningId' => 'tx', 'refereeId' => 'b', 'amount' => 1000, 'at' => 1]);
$r = $engine->reverseEarning(['earningId' => 'tx', 'at' => 2]);
$check($r['clawbacks'] === [['level' => 1, 'referrerId' => 'a', 'amount' => 30, 'uncollected' => 70]], 'availableBalance hook');

$total = $pass + $fail;
echo "{$pass}/{$total} passed" . ($fail > 0 ? ", {$fail} failed" : '') . "\n";
exit($fail > 0 ? 1 : 0);

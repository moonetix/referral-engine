<?php

require __DIR__ . '/../php/autoload.php';

use Moonetix\ReferralEngine\PdoStore;
use Moonetix\ReferralEngine\ReferralEngine;

$pdo = new PDO('sqlite::memory:'); // or mysql:/pgsql: with schema/mysql.sql or schema/postgresql.sql
$pdo->exec(file_get_contents(__DIR__ . '/../schema/sqlite.sql'));

$engine = new ReferralEngine(['store' => new PdoStore($pdo), 'plan' => ['type' => 'percentage', 'rateBps' => 500]]);
$engine->attach(['refereeId' => 'u_2001', 'referrerId' => 'u_1001', 'at' => 1767225600]);

// The same postback delivered twice accrues once.
foreach ([1, 2] as $attempt) {
    $r = $engine->recordEarning(['earningId' => 'tx_9001', 'refereeId' => 'u_2001', 'amount' => 4000, 'at' => 1767229200]);
    echo $r['status'], "\n";
}
// -> accrued
// -> duplicate
echo $pdo->query('SELECT SUM(amount) FROM referral_ledger')->fetchColumn(), "\n"; // -> 200

<?php

require __DIR__ . '/../php/autoload.php';

use Moonetix\ReferralEngine\MemoryStore;
use Moonetix\ReferralEngine\Referral;
use Moonetix\ReferralEngine\ReferralEngine;

const DAY = 86400;
$engine = new ReferralEngine([
    'store' => new MemoryStore(),
    'plan' => ['type' => 'percentage', 'rateBps' => 1000, 'durationSeconds' => 365 * DAY, 'perRefereeCap' => 50000],
    'holdSeconds' => 14 * DAY,
]);

// 1. Every user gets a short code to share. Store it with a UNIQUE constraint.
$code = Referral::generateCode();
echo strlen(Referral::formatCode($code)), "\n"; // -> 9

// 2. At sign-up, pick the referrer from the touches you recorded (link visits, typed codes).
$at = 1767225600; // 2026-01-01 00:00 UTC
$result = Referral::attribute([
    'signup' => ['userId' => 'u_2001', 'at' => $at, 'deviceIds' => ['dev_b'], 'ips' => ['198.51.100.23']],
    'touches' => [['referrerId' => 'u_1001', 'code' => 'WPEAJ39T', 'at' => $at - 3600]],
    'referrers' => ['u_1001' => ['deviceIds' => ['dev_a'], 'ips' => ['203.0.113.5']]],
]);
echo $result['decision'], ' ', $result['referrerId'], "\n"; // -> attributed u_1001
if ($result['referrerId'] !== null) {
    $engine->attach(['refereeId' => 'u_2001', 'referrerId' => $result['referrerId'], 'at' => $at]);
}

// 3. The friend's own reward is confirmed (for example a credited postback): 10% on top.
$earned = $engine->recordEarning(['earningId' => 'tx_9001', 'refereeId' => 'u_2001', 'amount' => 2500, 'at' => $at + DAY]);
echo json_encode($earned['commissions']), "\n"; // -> [{"level":1,"referrerId":"u_1001","amount":250,"availableAt":1768521600}]
echo json_encode($engine->balance('u_1001', $at + DAY)), "\n"; // -> {"pending":250,"available":0,"total":250,"uncollected":0}

// 4. The reward is reversed before the hold ends: the commission is cancelled, once.
$reversed = $engine->reverseEarning(['earningId' => 'tx_9001', 'at' => $at + 5 * DAY]);
echo json_encode($reversed['clawbacks']), "\n"; // -> [{"level":1,"referrerId":"u_1001","amount":250,"uncollected":0}]
echo json_encode($engine->balance('u_1001', $at + 30 * DAY)), "\n"; // -> {"pending":0,"available":0,"total":0,"uncollected":0}

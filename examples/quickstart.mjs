import { MemoryStore, ReferralEngine, attribute, formatCode, generateCode } from 'referral-engine';

const DAY = 86400;
const engine = new ReferralEngine({
  store: new MemoryStore(),
  plan: { type: 'percentage', rateBps: 1000, durationSeconds: 365 * DAY, perRefereeCap: 50000 },
  holdSeconds: 14 * DAY,
});

// 1. Every user gets a short code to share. Store it with a UNIQUE constraint.
const code = generateCode();
console.log(formatCode(code).length); // -> 9

// 2. At sign-up, pick the referrer from the touches you recorded (link visits, typed codes).
const at = 1767225600; // 2026-01-01 00:00 UTC
const result = attribute({
  signup: { userId: 'u_2001', at, deviceIds: ['dev_b'], ips: ['198.51.100.23'] },
  touches: [{ referrerId: 'u_1001', code: 'WPEAJ39T', at: at - 3600 }],
  referrers: { u_1001: { deviceIds: ['dev_a'], ips: ['203.0.113.5'] } },
});
console.log(result.decision, result.referrerId); // -> attributed u_1001
if (result.referrerId) await engine.attach({ refereeId: 'u_2001', referrerId: result.referrerId, at });

// 3. The friend's own reward is confirmed (for example a credited postback): 10% on top.
const earned = await engine.recordEarning({ earningId: 'tx_9001', refereeId: 'u_2001', amount: 2500, at: at + DAY });
console.log(JSON.stringify(earned.commissions)); // -> [{"level":1,"referrerId":"u_1001","amount":250,"availableAt":1768521600}]
console.log(JSON.stringify(await engine.balance('u_1001', at + DAY))); // -> {"pending":250,"available":0,"total":250,"uncollected":0}

// 4. The reward is reversed before the hold ends: the commission is cancelled, once.
const reversed = await engine.reverseEarning({ earningId: 'tx_9001', at: at + 5 * DAY });
console.log(JSON.stringify(reversed.clawbacks)); // -> [{"level":1,"referrerId":"u_1001","amount":250,"uncollected":0}]
console.log(JSON.stringify(await engine.balance('u_1001', at + 30 * DAY))); // -> {"pending":0,"available":0,"total":0,"uncollected":0}

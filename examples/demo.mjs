// A short walkthrough of a referral program, printed as plain text: `npm run demo`.
// Everything below calls the public API of the package. Amounts are cents.
import { MemoryStore, ReferralEngine, attribute, formatCode, generateCode } from '@moonetix/referral-engine';

const DAY = 86400;
const START = 1767225600; // 2026-01-01 00:00 UTC
const day = (n) => START + n * DAY;
const date = (at) => new Date(at * 1000).toISOString().slice(0, 10);
const usd = (cents) => `${cents < 0 ? '-' : ''}$${(Math.abs(cents) / 100).toFixed(2)}`;
const pct = (bps) => `${(bps / 100).toFixed(2)}%`;
const show = (b) => `pending ${usd(b.pending)}, available ${usd(b.available)}, total ${usd(b.total)}`;

const plan = {
  type: 'tiered',
  basis: 'referees',
  tiers: [
    { from: 0, rateBps: 1000 },
    { from: 2, rateBps: 1500 },
    { from: 10, rateBps: 2000 },
  ],
  durationSeconds: 365 * DAY,
  perRefereeCap: 50000,
};
const engine = new ReferralEngine({ store: new MemoryStore(), plan, holdSeconds: 14 * DAY });

console.log('referral-engine demo');
console.log('====================');
console.log('');
console.log('1. The plan: tiered by referees who have earned, paid on top of their earnings');
engine.plan.tiers.forEach((t, i) => console.log(`   tier ${i}: from ${t.from} referees, ${pct(t.rateBps)}`));
console.log(`   hold before payout: 14 days, cap per referee: ${usd(engine.plan.perRefereeCap)}`);

// Fixed random bytes so the code is the same on every run. Leave randomBytes out in real use.
const code = generateCode({ randomBytes: (n) => new Uint8Array(n).map((_, i) => 40 + i * 23) });
console.log('');
console.log(`2. Ana (u_ana) shares her referral code ${formatCode(code)}`);

console.log('');
console.log('3. Four people sign up with it');
const referrers = { u_ana: { deviceIds: ['dev_ana'], ips: ['203.0.113.5'] } };
const signups = [
  { userId: 'u_ben', deviceIds: ['dev_ben'], ips: ['198.51.100.23'] },
  { userId: 'u_cleo', deviceIds: ['dev_cleo'], ips: ['192.0.2.77'] },
  { userId: 'u_dan', deviceIds: ['dev_dan'], ips: ['198.51.100.140'] },
  { userId: 'u_eve', deviceIds: ['dev_ana'], ips: ['198.51.100.201'] },
];
for (const s of signups) {
  const result = attribute({
    signup: { ...s, at: day(0) },
    touches: [{ referrerId: 'u_ana', code, at: day(0) - 3600 }],
    referrers,
  });
  if (result.referrerId) {
    await engine.attach({ refereeId: s.userId, referrerId: result.referrerId, at: day(0), code });
    console.log(`   ${s.userId.padEnd(7)} ${result.decision}, referred by ${result.referrerId}`);
  } else {
    console.log(`   ${s.userId.padEnd(7)} ${result.decision}: ${result.reasons[0].code} (${result.reasons[0].message})`);
  }
}

console.log('');
console.log("4. Referred users earn; Ana's commission is added on top");
const earnings = [
  { earningId: 'tx_1', refereeId: 'u_ben', amount: 2000, at: day(1) },
  { earningId: 'tx_2', refereeId: 'u_cleo', amount: 3000, at: day(2) },
  { earningId: 'tx_3', refereeId: 'u_dan', amount: 4000, at: day(3) },
  { earningId: 'tx_4', refereeId: 'u_ben', amount: 1000, at: day(4) },
];
for (const e of earnings) {
  const result = await engine.recordEarning(e);
  const c = result.commissions[0];
  console.log(`   ${date(e.at)} ${e.refereeId.padEnd(7)} earns ${usd(e.amount).padStart(6)} -> Ana gets ${usd(c.amount).padStart(5)} (${pct((c.amount * 10000) / e.amount)}), available ${date(c.availableAt)}`);
}
const eve = await engine.recordEarning({ earningId: 'tx_5', refereeId: 'u_eve', amount: 5000, at: day(4) });
console.log(`   ${date(day(4))} u_eve   earns ${usd(5000).padStart(6)} -> no commission (${eve.reason})`);
console.log(`   balance on ${date(day(10))}: ${show(await engine.balance('u_ana', day(10)))}`);
console.log(`   balance on ${date(day(20))}: ${show(await engine.balance('u_ana', day(20)))}`);

console.log('');
console.log("5. Chargeback: Dan's $40.00 is reversed after the hold");
const reversed = await engine.reverseEarning({ earningId: 'tx_3', at: day(21) });
console.log(`   ${date(day(21))} tx_3 reversed -> ${usd(reversed.clawbacks[0].amount)} clawed back from Ana`);
console.log(`   balance on ${date(day(21))}: ${show(await engine.balance('u_ana', day(21)))}`);

console.log('');
console.log('6. Payouts never take more than the balance');
const tryPayout = async (payoutId, amount, at, note = '') => {
  try {
    await engine.recordPayout({ payoutId, referrerId: 'u_ana', amount, at });
    console.log(`   ${date(at)} payout ${usd(amount).padStart(6)}${note} -> recorded`);
  } catch (err) {
    console.log(`   ${date(at)} payout ${usd(amount).padStart(6)}${note} -> refused, ${err.code}: ${err.message}`);
  }
};
await tryPayout('po_1', 1500, day(25));
await tryPayout('po_2', 600, day(25));
await tryPayout('po_3', 500, day(22), ' dated earlier');
const asOf = await engine.balance('u_ana', day(22));
console.log(`   (the balance as of ${date(day(22))} reads ${usd(asOf.available)}, but the later $6.00 payout counts too)`);
console.log(`   balance on ${date(day(25))}: ${show(await engine.balance('u_ana', day(25)))}`);

console.log('');
console.log("Ana's ledger:");
for (const e of await engine.ledger('u_ana')) {
  console.log(`   ${date(e.at)} ${e.type.padEnd(8)} ${usd(e.amount).padStart(6)}  ${e.earningId ?? e.key}`);
}

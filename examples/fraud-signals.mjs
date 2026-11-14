import { fraudSignals } from '@moonetix/referral-engine';

const DAY = 86400;
const report = fraudSignals({
  referrer: { id: 'u_1001', deviceIds: ['dev_a'], ips: ['203.0.113.5'], payoutDestinations: ['paypal:5f1c'] },
  referees: [
    { id: 'u_2001', deviceIds: ['dev_b'], ips: ['198.51.100.23'], payoutDestinations: ['paypal:5f1c'], signedUpAt: 0, firstEarningAt: 3600, lastActiveAt: 7200, earned: 3000, reversed: 3000 },
    { id: 'u_2002', deviceIds: ['dev_c'], ips: ['198.51.100.24'], payoutDestinations: ['paypal:91aa'], signedUpAt: 600, firstEarningAt: 4000, lastActiveAt: 8000, earned: 2800, reversed: 1400 },
    { id: 'u_2003', deviceIds: ['dev_d'], ips: ['192.0.2.77'], payoutDestinations: ['paypal:0c3e'], signedUpAt: 20 * DAY, firstEarningAt: 22 * DAY, lastActiveAt: 40 * DAY, earned: 4000 },
  ],
  now: 40 * DAY,
});
console.log(report.score, report.level); // -> 75 high
for (const s of report.signals) console.log(`${s.code}: ${s.message}`);
// -> shared_payout_with_referrer: 1 referred account cashes out to a payout destination the referrer also uses.
// -> earn_then_vanish: 2 referred accounts earned within 3 days of signing up and have been inactive for 14 days or more.
// -> high_reversal_rate: Reversals are 44.89% of referred earnings (4400 of 9800).

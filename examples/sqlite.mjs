import { readFileSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
import { ReferralEngine } from 'referral-engine';
import { SqliteStore } from 'referral-engine/sqlite';

const db = new DatabaseSync(':memory:'); // a file path in production
db.exec(readFileSync(new URL(import.meta.resolve('referral-engine/schema/sqlite.sql')), 'utf8'));

const engine = new ReferralEngine({ store: new SqliteStore(db), plan: { type: 'percentage', rateBps: 500 } });
await engine.attach({ refereeId: 'u_2001', referrerId: 'u_1001', at: 1767225600 });

// The same postback delivered three times at once still accrues once.
const results = await Promise.all([1, 2, 3].map(() => engine.recordEarning({ earningId: 'tx_9001', refereeId: 'u_2001', amount: 4000, at: 1767229200 })));
console.log(results.map((r) => r.status).join(' ')); // -> accrued duplicate duplicate
console.log(db.prepare('SELECT SUM(amount) AS total FROM referral_ledger').get()?.total); // -> 200

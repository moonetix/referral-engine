import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { SqliteStore } from '../src/sqlite-store.js';
import { ReferralEngine } from '../src/index.js';
import { runEngineVectors } from './helpers.js';

let DatabaseSync = null;
try {
  ({ DatabaseSync } = await import('node:sqlite'));
} catch {
  // node:sqlite arrived in Node 22.5; on older versions these tests are skipped.
}
const schema = readFileSync(new URL('../schema/sqlite.sql', import.meta.url), 'utf8');

function freshDb() {
  const db = new DatabaseSync(':memory:');
  db.exec(schema);
  return db;
}

test('engine vectors pass on SqliteStore and schema/sqlite.sql', { skip: !DatabaseSync }, () => runEngineVectors(() => new SqliteStore(freshDb())));

test('the ledger table refuses updates and deletes', { skip: !DatabaseSync }, async () => {
  const db = freshDb();
  const engine = new ReferralEngine({ store: new SqliteStore(db), plan: { type: 'percentage', rateBps: 1000 } });
  await engine.attach({ refereeId: 'b', referrerId: 'a', at: 0 });
  await engine.recordEarning({ earningId: 'e1', refereeId: 'b', amount: 1000, at: 1 });
  assert.throws(() => db.exec('UPDATE referral_ledger SET amount = 1'), /append-only/);
  assert.throws(() => db.exec('DELETE FROM referral_ledger'), /append-only/);
});

test('concurrent duplicates and reversals stay exact on SQLite', { skip: !DatabaseSync }, async () => {
  const engine = new ReferralEngine({ store: new SqliteStore(freshDb()), plan: { type: 'percentage', rateBps: 1000 } });
  await engine.attach({ refereeId: 'b', referrerId: 'a', at: 0 });
  const results = await Promise.all(Array.from({ length: 5 }, () => engine.recordEarning({ earningId: 'e1', refereeId: 'b', amount: 1000, at: 1 })));
  assert.deepEqual(results.map((r) => r.status).sort(), ['accrued', 'duplicate', 'duplicate', 'duplicate', 'duplicate']);
  await Promise.all(['r1', 'r2', 'r3', 'r4'].map((id) => engine.reverseEarning({ earningId: 'e1', reversalId: id, amount: 400, at: 2 })));
  assert.deepEqual(await engine.balance('a', 3), { pending: 0, available: 0, total: 0, uncollected: 0 });
});

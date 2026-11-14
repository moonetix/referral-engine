import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as R from '../src/index.js';

const ALPHABET = R.DEFAULT_ALPHABET;

test('random codes use only the alphabet and carry a valid check character', () => {
  for (let i = 0; i < 200; i++) {
    const code = R.generateCode({ checkChar: true });
    assert.match(code, /^[23456789ABCDEFGHJKMNPQRSTVWXY]{9}$/);
    assert.ok(R.isValidCode(code, { checkChar: true }), code);
  }
});

test('the check character catches every single substitution and every neighbor swap', () => {
  const code = R.generateCode({ length: 10, checkChar: true, randomBytes: () => new Uint8Array([3, 14, 15, 9, 26, 5, 3, 5, 8, 9]) });
  for (let i = 0; i < code.length; i++) {
    for (const ch of ALPHABET) {
      if (ch === code[i]) continue;
      const bad = code.slice(0, i) + ch + code.slice(i + 1);
      assert.equal(R.isValidCode(bad, { length: 10, checkChar: true }), false, bad);
    }
    if (i + 1 < code.length && code[i] !== code[i + 1]) {
      const swapped = code.slice(0, i) + code[i + 1] + code[i] + code.slice(i + 2);
      assert.equal(R.isValidCode(swapped, { length: 10, checkChar: true }), false, swapped);
    }
  }
});

test('createUniqueCode retries on collisions and gives up after maxAttempts', async () => {
  const taken = new Set();
  let calls = 0;
  const code = await R.createUniqueCode(async (c) => {
    calls++;
    if (calls < 3) {
      taken.add(c);
      return true;
    }
    return taken.has(c);
  });
  assert.equal(calls, 3);
  assert.equal(code.length, 8);
  await assert.rejects(R.createUniqueCode(() => true, { maxAttempts: 4 }), (e) => e.code === 'code_space_exhausted');
});

test('custom attribution checks receive the context and can ask for review', () => {
  const seen = [];
  const newAccountsOnly = (ctx) => {
    seen.push(ctx);
    return ctx.touch.source === 'typed' ? { code: 'typed_code', action: 'review', message: 'Code typed by hand.' } : null;
  };
  const result = R.attribute({
    signup: { userId: 'u1', at: 100 },
    touches: [{ referrerId: 'a', at: 150, source: 'typed', code: 'WPEAJ39T' }],
    rules: { checks: [R.selfReferral(), newAccountsOnly] },
  });
  assert.equal(result.decision, 'review');
  assert.equal(seen[0].referee.userId, 'u1');
  assert.equal(seen[0].referrerId, 'a');
  assert.throws(() => R.attribute({ signup: { userId: 'u1', at: 1 }, touches: [{ referrerId: 'a', at: 1 }], rules: { checks: [() => ({ code: 'x', action: 'ban' })] } }), (e) => e.code === 'invalid_argument');
});

test('concurrent duplicate postbacks accrue once on MemoryStore', async () => {
  const engine = new R.ReferralEngine({ store: new R.MemoryStore(), plan: { type: 'percentage', rateBps: 1000 } });
  await engine.attach({ refereeId: 'b', referrerId: 'a', at: 0 });
  const results = await Promise.all(Array.from({ length: 10 }, () => engine.recordEarning({ earningId: 'tx', refereeId: 'b', amount: 1000, at: 1 })));
  assert.equal(results.filter((r) => r.status === 'accrued').length, 1);
  assert.deepEqual(await engine.balance('a', 1), { pending: 0, available: 100, total: 100, uncollected: 0 });
});

test('a store without withLock is still queued per key inside one process', async () => {
  const inner = new R.MemoryStore();
  const store = {
    getReferral: (id) => inner.getReferral(id),
    insertReferral: (r) => inner.insertReferral(r),
    listEntries: (f) => inner.listEntries(f),
    appendEntries: (e) => inner.appendEntries(e),
  };
  const engine = new R.ReferralEngine({ store, plan: { type: 'percentage', rateBps: 1000, perRefereeCap: 600 } });
  await engine.attach({ refereeId: 'b', referrerId: 'a', at: 0 });
  const earned = await Promise.all(['e1', 'e2', 'e3'].map((id) => engine.recordEarning({ earningId: id, refereeId: 'b', amount: 5000, at: 1 })));
  assert.deepEqual(earned.map((r) => r.commissions[0].amount), [500, 100, 0], 'the cap holds for earnings recorded at once');
  const paid = await Promise.allSettled(['p1', 'p2', 'p3'].map((id) => engine.recordPayout({ payoutId: id, referrerId: 'a', amount: 600, at: 2 })));
  assert.deepEqual(paid.map((r) => (r.status === 'fulfilled' ? r.value.status : r.reason.code)), ['recorded', 'insufficient_balance', 'insufficient_balance']);
  assert.deepEqual(await engine.balance('a', 3), { pending: 0, available: 0, total: 0, uncollected: 0 });
});

test('a payout dated before a recorded payout or clawback cannot overdraw', async () => {
  const engine = new R.ReferralEngine({ store: new R.MemoryStore(), plan: { type: 'percentage', rateBps: 1000 } });
  await engine.attach({ refereeId: 'b', referrerId: 'a', at: 0 });
  await engine.recordEarning({ earningId: 'e1', refereeId: 'b', amount: 5000, at: 1 });
  await engine.recordEarning({ earningId: 'e2', refereeId: 'b', amount: 5000, at: 1 });
  await engine.recordPayout({ payoutId: 'p1', referrerId: 'a', amount: 500, at: 200 });
  await engine.reverseEarning({ earningId: 'e2', at: 200 });
  await assert.rejects(engine.recordPayout({ payoutId: 'p2', referrerId: 'a', amount: 500, at: 150 }), (e) => e.code === 'insufficient_balance' && e.message.startsWith('available balance is 0,'));
  assert.deepEqual(await engine.balance('a', 300), { pending: 0, available: 0, total: 0, uncollected: 0 });
});

test('availableBalance hook limits clawbacks when commission was moved to a wallet', async () => {
  const wallet = { a: 30 };
  const engine = new R.ReferralEngine({
    store: new R.MemoryStore(),
    plan: { type: 'percentage', rateBps: 1000 },
    allowNegativeBalance: false,
    availableBalance: async (referrerId) => wallet[referrerId] ?? 0,
  });
  await engine.attach({ refereeId: 'b', referrerId: 'a', at: 0 });
  await engine.recordEarning({ earningId: 'tx', refereeId: 'b', amount: 1000, at: 1 });
  const r = await engine.reverseEarning({ earningId: 'tx', at: 2 });
  assert.deepEqual(r.clawbacks, [{ level: 1, referrerId: 'a', amount: 30, uncollected: 70 }]);
});

test('bad input is rejected with a code', async () => {
  assert.throws(() => new R.ReferralEngine({ store: {}, plan: { type: 'percentage', rateBps: 1 } }), (e) => e.code === 'invalid_argument');
  const engine = new R.ReferralEngine({ store: new R.MemoryStore(), plan: { type: 'percentage', rateBps: 1 } });
  await assert.rejects(engine.recordEarning({ earningId: 'x', refereeId: 'b', amount: 0.5, at: 1 }), (e) => e.code === 'invalid_argument');
  await assert.rejects(engine.recordEarning({ earningId: 'x', refereeId: 'b', amount: 10, at: Number.MAX_SAFE_INTEGER + 1 }), (e) => e.code === 'invalid_argument');
  await assert.rejects(engine.attach({ refereeId: 'x'.repeat(192), referrerId: 'a', at: 1 }), (e) => e.code === 'invalid_argument');
  assert.throws(() => R.ipPrefix('1.2.3.4', { ipv4Prefix: 33 }), (e) => e.code === 'invalid_argument');
});

test('ledger entries have the documented fields and nothing else', async () => {
  const engine = new R.ReferralEngine({ store: new R.MemoryStore(), plan: { type: 'percentage', rateBps: 1000 } });
  await engine.attach({ refereeId: 'b', referrerId: 'a', at: 0 });
  await engine.recordEarning({ earningId: 'tx', refereeId: 'b', amount: 1000, at: 1 });
  for (const e of await engine.ledger('a')) assert.deepEqual(Object.keys(e), R.ENTRY_FIELDS);
});

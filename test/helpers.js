import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as R from '../src/index.js';

export const vectors = JSON.parse(readFileSync(new URL('../test-vectors/vectors.json', import.meta.url), 'utf8'));

export function bytesSource(hex) {
  const buf = Buffer.from(hex, 'hex');
  let pos = 0;
  return (n) => {
    const out = buf.subarray(pos, pos + n);
    pos += out.length;
    return new Uint8Array(out);
  };
}

const ops = {
  attach: (e, s) => e.attach(s.input),
  recordEarning: (e, s) => e.recordEarning(s.input),
  reverseEarning: (e, s) => e.reverseEarning(s.input),
  recordPayout: (e, s) => e.recordPayout(s.input),
  recordAdjustment: (e, s) => e.recordAdjustment(s.input),
  balance: (e, s) => e.balance(s.referrerId, s.at),
  ledger: (e, s) => e.ledger(s.referrerId),
};

/** Runs every engine scenario against stores made by makeStore(). Exported for the SQLite test. */
export async function runEngineVectors(makeStore) {
  for (const c of vectors.engine) {
    if (c.throws) {
      assert.throws(() => new R.ReferralEngine({ ...c.config, store: makeStore() }), (e) => e.code === c.throws, c.name);
      continue;
    }
    const engine = new R.ReferralEngine({ ...c.config, store: makeStore() });
    for (const [i, s] of c.steps.entries()) {
      const label = `${c.name} step ${i} ${s.op}`;
      if (s.throws) await assert.rejects(ops[s.op](engine, s), (e) => e.code === s.throws, label);
      else assert.deepEqual(await ops[s.op](engine, s), s.out, label);
    }
  }
}


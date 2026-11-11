import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as R from '../src/index.js';
import { bytesSource, runEngineVectors, vectors } from './helpers.js';

const runners = {
  generateCode: (c) => R.generateCode({ ...(c.options ?? {}), randomBytes: bytesSource(c.bytes) }),
  checkCharFor: (c) => R.checkCharFor(c.code, c.options ?? {}),
  normalizeCode: (c) => R.normalizeCode(c.in, c.options ?? {}),
  isValidCode: (c) => R.isValidCode(c.in, c.options ?? {}),
  formatCode: (c) => R.formatCode(c.in, c.options ?? {}),
  ipPrefix: (c) => R.ipPrefix(c.in, c.options ?? {}),
  attribute: (c) => R.attribute(c.input),
  definePlan: (c) => R.definePlan(c.in),
  commissionFor: (c) => R.commissionFor(c.plan, c.amount, c.stats ?? {}),
  findRings: (c) => R.findRings(c.edges),
  fraudSignals: (c) => R.fraudSignals(c.input),
};

for (const [group, run] of Object.entries(runners)) {
  test(`vectors: ${group}`, () => {
    const cases = vectors[group];
    assert.ok(Array.isArray(cases) && cases.length > 0, `no vectors for ${group}`);
    cases.forEach((c, i) => {
      const label = `${group}[${i}]${c.name ? ` ${c.name}` : ''}`;
      if (c.throws) assert.throws(() => run(c), (e) => e instanceof R.ReferralError && e.code === c.throws, label);
      else assert.deepEqual(run(c), c.out, label);
    });
  });
}

test('vectors: engine (MemoryStore)', () => runEngineVectors(() => new R.MemoryStore()));

test('every vector group has a runner', () => {
  const groups = Object.keys(vectors).filter((k) => Array.isArray(vectors[k]));
  assert.deepEqual(groups.sort(), [...Object.keys(runners), 'engine'].sort());
});

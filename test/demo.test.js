import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// `npm run demo` must keep running and keep showing each step of the walkthrough.
test('examples/demo.mjs runs and prints the walkthrough', () => {
  const file = fileURLToPath(new URL('../examples/demo.mjs', import.meta.url));
  const run = spawnSync(process.execPath, ['--no-warnings', file], { encoding: 'utf8' });
  assert.equal(run.status, 0, run.stderr);
  assert.equal(run.stderr, '');
  for (const line of [
    '   tier 1: from 2 referees, 15.00%',
    '   u_ben   attributed, referred by u_ana',
    '   u_eve   blocked: same_device',
    '   2026-01-02 u_ben   earns $20.00 -> Ana gets $2.00 (10.00%), available 2026-01-16',
    '   2026-01-04 u_dan   earns $40.00 -> Ana gets $6.00 (15.00%), available 2026-01-18',
    '   balance on 2026-01-21: pending $0.00, available $12.50, total $12.50',
    '   2026-01-22 tx_3 reversed -> $6.00 clawed back from Ana',
    '   2026-01-26 payout $15.00 -> refused, insufficient_balance: available balance is 650, payout is 1500',
    '   2026-01-26 payout  $6.00 -> recorded',
    '   2026-01-23 payout  $5.00 dated earlier -> refused, insufficient_balance: available balance is 50, payout is 500',
    '   balance on 2026-01-26: pending $0.00, available $0.50, total $0.50',
  ]) {
    assert.ok(run.stdout.includes(line), `missing: ${line}`);
  }
});

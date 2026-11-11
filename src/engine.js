import { BPS, MAX_AMOUNT, fail, id, int, mulDiv } from './util.js';
import { asPlan, commissionFor } from './plan.js';

export const ENTRY_FIELDS = ['key', 'type', 'referrerId', 'refereeId', 'earningId', 'level', 'amount', 'baseAmount', 'uncollected', 'at', 'availableAt'];

function accrualKey(earningId, level) {
  return `accrual:${level}:${earningId}`;
}

function clawbackKey(earningId, level, reversalId) {
  return `clawback:${level}:${earningId.length}:${earningId}:${reversalId}`;
}

function entry(fields) {
  const e = { refereeId: null, earningId: null, level: null, baseAmount: 0, uncollected: 0, ...fields, availableAt: fields.availableAt ?? fields.at };
  return Object.fromEntries(ENTRY_FIELDS.map((f) => [f, e[f]]));
}

function addTime(at, seconds) {
  if (at + seconds > MAX_AMOUNT) fail('overflow', 'release time exceeds 2^53 - 1');
  return at + seconds;
}

/** Accrual entries with what later clawbacks took from them. */
function accrualStates(entries) {
  const states = new Map();
  for (const e of entries) {
    if (e.type === 'accrual') states.set(`${e.level}:${e.earningId}`, { entry: e, taken: 0, uncollected: 0, reversedBase: 0 });
  }
  for (const e of entries) {
    if (e.type !== 'clawback') continue;
    const s = states.get(`${e.level}:${e.earningId}`);
    if (!s) continue;
    s.taken += -e.amount;
    s.uncollected += e.uncollected;
    s.reversedBase += -e.baseAmount;
  }
  return states;
}

function commissionView(e) {
  return { level: e.level, referrerId: e.referrerId, amount: e.amount, availableAt: e.availableAt };
}

export class ReferralEngine {
  #store;
  #hold;
  #allowNegative;
  #levels;
  #availableHook;

  constructor({ store, plan, holdSeconds = 0, allowNegativeBalance = true, multiLevel = null, availableBalance = null } = {}) {
    for (const m of ['getReferral', 'insertReferral', 'listEntries', 'appendEntries']) {
      if (!store || typeof store[m] !== 'function') fail('invalid_argument', `store must implement ${m}()`);
    }
    this.#store = store;
    this.plan = asPlan(plan);
    this.#hold = int(holdSeconds, 'holdSeconds');
    if (typeof allowNegativeBalance !== 'boolean') fail('invalid_argument', 'allowNegativeBalance must be a boolean');
    this.#allowNegative = allowNegativeBalance;
    this.#levels = [];
    if (multiLevel !== null && multiLevel !== undefined) {
      if (multiLevel.acknowledgeRegulatoryRisk !== true) {
        fail('multi_level_not_acknowledged', 'multi-level commissions are regulated in many places; set multiLevel.acknowledgeRegulatoryRisk to true after legal review');
      }
      if (!Array.isArray(multiLevel.levels) || multiLevel.levels.length < 1 || multiLevel.levels.length > 4) fail('invalid_argument', 'multiLevel.levels must list 1 to 4 rates in basis points');
      this.#levels = multiLevel.levels.map((bps, i) => int(bps, `multiLevel.levels[${i}]`, 0, BPS));
    }
    if (availableBalance !== null && typeof availableBalance !== 'function') fail('invalid_argument', 'availableBalance must be a function');
    this.#availableHook = availableBalance;
  }

  async #locked(key, fn) {
    return typeof this.#store.withLock === 'function' ? this.#store.withLock(key, fn) : fn();
  }

  async attach({ refereeId, referrerId, at, code = null } = {}) {
    const referee = id(refereeId, 'refereeId');
    const referrer = id(referrerId, 'referrerId');
    int(at, 'at');
    if (code !== null && typeof code !== 'string') fail('invalid_argument', 'code must be a string');
    if (referee === referrer) fail('self_referral', 'a user cannot refer themselves');
    return this.#locked(`referee:${referee}`, async () => {
      let current = referrer;
      for (let i = 0; i < 1000; i++) {
        const up = await this.#store.getReferral(current);
        if (!up) break;
        if (up.referrerId === referee) fail('referral_cycle', `${referee} is already upstream of ${referrer}`);
        current = up.referrerId;
      }
      const referral = { refereeId: referee, referrerId: referrer, attributedAt: at, code };
      for (let attempt = 0; attempt < 2; attempt++) {
        const existing = await this.#store.getReferral(referee);
        if (existing) {
          if (existing.referrerId !== referrer) fail('already_referred', `${referee} was already referred by ${existing.referrerId}`);
          return { status: 'existing', referral: existing };
        }
        if (await this.#store.insertReferral(referral)) return { status: 'attached', referral };
      }
      fail('already_referred', `${referee} was referred concurrently`);
    });
  }

  async recordEarning({ earningId, refereeId, amount, at } = {}) {
    const earning = id(earningId, 'earningId');
    const referee = id(refereeId, 'refereeId');
    int(amount, 'amount');
    int(at, 'at');
    return this.#locked(`earning:${earning}`, async () => {
      const dup = await this.#duplicateAccrual(earning);
      if (dup) return dup;
      const none = (reason) => ({ status: 'none', reason, capped: [], commissions: [] });
      const referral = await this.#store.getReferral(referee);
      if (!referral) return none('no_referrer');
      if (at < referral.attributedAt) return none('before_referral');
      if (this.plan.durationSeconds !== null && at >= referral.attributedAt + this.plan.durationSeconds) return none('expired');

      const referrer = referral.referrerId;
      return this.#locked(`referrer:${referrer}`, () => this.#accrue(earning, referee, amount, at, referrer));
    });
  }

  async #accrue(earning, referee, amount, at, referrer) {
    const states = accrualStates(await this.#store.listEntries({ referrerId: referrer }));
    const qualified = new Set();
    let volume = 0;
    let refereeNet = 0;
    let paidBefore = false;
    let periodNet = 0;
    const cap = this.plan.perReferrerCap;
    for (const s of states.values()) {
      const e = s.entry;
      const net = e.amount - s.taken;
      if (cap && Math.floor(e.at / cap.periodSeconds) === Math.floor(at / cap.periodSeconds)) periodNet += net;
      if (e.level !== 1) continue;
      const base = e.baseAmount - s.reversedBase;
      volume += base;
      if (base > 0) qualified.add(e.refereeId);
      if (e.refereeId === referee) {
        refereeNet += net;
        if (net > 0) paidBefore = true;
      }
    }
    const c = commissionFor(this.plan, amount, { referees: qualified.size, volume: Math.min(volume, MAX_AMOUNT), paidBefore });
    if (c.reason === 'below_minimum') return { status: 'none', reason: 'below_minimum', capped: [], commissions: [] };

    let value = c.amount;
    const capped = [];
    const limit = (room, code) => {
      if (value > room) {
        value = Math.max(0, room);
        capped.push(code);
      }
    };
    if (this.plan.perEarningCap !== null) limit(this.plan.perEarningCap, 'per_earning_cap');
    if (this.plan.perRefereeCap !== null) limit(this.plan.perRefereeCap - refereeNet, 'per_referee_cap');
    if (cap) limit(cap.amount - periodNet, 'per_referrer_cap');

    const availableAt = addTime(at, this.#hold);
    const entries = [entry({ key: accrualKey(earning, 1), type: 'accrual', referrerId: referrer, refereeId: referee, earningId: earning, level: 1, amount: value, baseAmount: amount, at, availableAt })];
    const seen = new Set([referee, referrer]);
    let current = referrer;
    for (let i = 0; i < this.#levels.length; i++) {
      const up = await this.#store.getReferral(current);
      if (!up || seen.has(up.referrerId)) break;
      seen.add(up.referrerId);
      entries.push(entry({ key: accrualKey(earning, i + 2), type: 'accrual', referrerId: up.referrerId, refereeId: referee, earningId: earning, level: i + 2, amount: mulDiv(amount, this.#levels[i], BPS), baseAmount: amount, at, availableAt }));
      current = up.referrerId;
    }
    if (!(await this.#store.appendEntries(entries))) {
      return (await this.#duplicateAccrual(earning)) ?? fail('conflict', 'ledger rejected the entries');
    }
    return { status: 'accrued', reason: c.reason, capped, commissions: entries.map(commissionView) };
  }

  async #duplicateAccrual(earningId) {
    const accruals = (await this.#store.listEntries({ earningId })).filter((e) => e.type === 'accrual');
    if (accruals.length === 0) return null;
    accruals.sort((a, b) => a.level - b.level);
    return { status: 'duplicate', reason: null, capped: [], commissions: accruals.map(commissionView) };
  }

  async reverseEarning({ earningId, reversalId, amount = null, at } = {}) {
    const earning = id(earningId, 'earningId');
    const reversal = reversalId === undefined || reversalId === null ? earning : id(reversalId, 'reversalId');
    if (amount !== null) int(amount, 'amount', 1);
    int(at, 'at');
    return this.#locked(`earning:${earning}`, async () => {
      const entries = await this.#store.listEntries({ earningId: earning });
      const accruals = entries.filter((e) => e.type === 'accrual').sort((a, b) => a.level - b.level);
      if (accruals.length === 0) return { status: 'none', reason: 'unknown_earning', clawbacks: [] };
      const keys = new Set(accruals.map((a) => clawbackKey(earning, a.level, reversal)));
      const prior = entries.filter((e) => keys.has(e.key));
      if (prior.length > 0) return { status: 'duplicate', reason: null, clawbacks: prior.map((e) => ({ level: e.level, referrerId: e.referrerId, amount: -e.amount, uncollected: e.uncollected })) };

      const states = accrualStates(entries);
      const base = accruals[0].baseAmount;
      const reversedSoFar = states.get(`1:${earning}`)?.reversedBase ?? 0;
      const remaining = base - reversedSoFar;
      if (remaining <= 0) return { status: 'none', reason: 'already_reversed', clawbacks: [] };
      const part = amount === null ? remaining : Math.min(amount, remaining);
      const cumulative = reversedSoFar + part;

      const out = [];
      for (const a of accruals) {
        const s = states.get(`${a.level}:${earning}`);
        const due = Math.max(0, mulDiv(a.amount, cumulative, base) - s.taken - s.uncollected);
        let taken = due;
        if (!this.#allowNegative && a.availableAt <= at && due > 0) {
          taken = Math.min(due, Math.max(0, await this.#available(a.referrerId, at)));
        }
        out.push(entry({ key: clawbackKey(earning, a.level, reversal), type: 'clawback', referrerId: a.referrerId, refereeId: a.refereeId, earningId: earning, level: a.level, amount: -taken, baseAmount: -part, uncollected: due - taken, at }));
      }
      if (!(await this.#store.appendEntries(out))) fail('conflict', 'ledger rejected the clawback; retry the reversal');
      return {
        status: 'reversed',
        reason: amount !== null && amount > remaining ? 'capped_at_remaining' : null,
        clawbacks: out.map((e) => ({ level: e.level, referrerId: e.referrerId, amount: -e.amount, uncollected: e.uncollected })),
      };
    });
  }

  async #available(referrerId, at) {
    if (this.#availableHook) return int(await this.#availableHook(referrerId, at), 'availableBalance()', -MAX_AMOUNT);
    return (await this.balance(referrerId, at)).available;
  }

  async recordPayout({ payoutId, referrerId, amount, at } = {}) {
    const payout = id(payoutId, 'payoutId');
    const referrer = id(referrerId, 'referrerId');
    int(amount, 'amount', 1);
    int(at, 'at');
    return this.#single(referrer, entry({ key: `payout:${payout}`, type: 'payout', referrerId: referrer, amount: -amount, at }), async () => {
      const { available } = await this.balance(referrer, at);
      if (amount > available) fail('insufficient_balance', `available balance is ${available}, payout is ${amount}`);
    });
  }

  async recordAdjustment({ adjustmentId, referrerId, amount, at } = {}) {
    const adjustment = id(adjustmentId, 'adjustmentId');
    const referrer = id(referrerId, 'referrerId');
    int(amount, 'amount', -MAX_AMOUNT);
    if (amount === 0) fail('invalid_argument', 'amount must not be 0');
    int(at, 'at');
    return this.#single(referrer, entry({ key: `adjustment:${adjustment}`, type: 'adjustment', referrerId: referrer, amount, at }), null);
  }

  async #single(referrer, e, guard) {
    return this.#locked(`referrer:${referrer}`, async () => {
      const prior = (await this.#store.listEntries({ referrerId: referrer })).find((x) => x.key === e.key);
      if (prior) return { status: 'duplicate', entry: prior };
      if (guard) await guard();
      if (!(await this.#store.appendEntries([e]))) fail('conflict', `ledger already has ${e.key}`);
      return { status: 'recorded', entry: e };
    });
  }

  async balance(referrerId, at) {
    const referrer = id(referrerId, 'referrerId');
    int(at, 'at');
    const entries = (await this.#store.listEntries({ referrerId: referrer })).filter((e) => e.at <= at);
    let pending = 0;
    let available = 0;
    let uncollected = 0;
    for (const s of accrualStates(entries).values()) {
      const net = s.entry.amount - s.taken;
      if (s.entry.availableAt > at) pending += net;
      else available += net;
    }
    for (const e of entries) {
      if (e.type === 'payout' || e.type === 'adjustment') available += e.amount;
      if (e.type === 'clawback') uncollected += e.uncollected;
    }
    return { pending, available, total: pending + available, uncollected };
  }

  async ledger(referrerId) {
    return this.#store.listEntries({ referrerId: id(referrerId, 'referrerId') });
  }
}

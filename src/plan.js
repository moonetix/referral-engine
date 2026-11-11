import { BPS, fail, int, mulDiv } from './util.js';

function obj(v, name) {
  if (!v || typeof v !== 'object' || Array.isArray(v)) fail('invalid_plan', `${name} must be an object`);
  return v;
}

function planInt(value, name, min, max, fallback) {
  if (value === undefined || value === null) {
    if (fallback === undefined) fail('invalid_plan', `${name} is required`);
    return fallback;
  }
  if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < min || value > max) fail('invalid_plan', `${name} must be an integer from ${min} to ${max}`);
  return value;
}

/** Validate a commission plan and return it with every option filled in. */
export function definePlan(config) {
  obj(config, 'plan');
  const type = config.type;
  if (!['percentage', 'flat', 'tiered'].includes(type)) fail('invalid_plan', 'plan.type must be "percentage", "flat" or "tiered"');
  const plan = { type };
  if (type === 'percentage') plan.rateBps = planInt(config.rateBps, 'rateBps', 0, BPS);
  if (type === 'flat') {
    plan.amount = planInt(config.amount, 'amount', 1, Number.MAX_SAFE_INTEGER);
    if (config.trigger != null && !['first', 'every'].includes(config.trigger)) fail('invalid_plan', 'trigger must be "first" or "every"');
    plan.trigger = config.trigger ?? 'first';
  }
  if (type === 'tiered') {
    if (config.basis != null && !['referees', 'volume'].includes(config.basis)) fail('invalid_plan', 'basis must be "referees" or "volume"');
    plan.basis = config.basis ?? 'referees';
    if (!Array.isArray(config.tiers) || config.tiers.length === 0) fail('invalid_plan', 'tiers must be a non-empty array');
    plan.tiers = config.tiers.map((t, i) => {
      obj(t, `tiers[${i}]`);
      return { from: planInt(t.from, `tiers[${i}].from`, 0, Number.MAX_SAFE_INTEGER), rateBps: planInt(t.rateBps, `tiers[${i}].rateBps`, 0, BPS) };
    });
    if (plan.tiers[0].from !== 0) fail('invalid_plan', 'the first tier must start at 0');
    for (let i = 1; i < plan.tiers.length; i++) {
      if (plan.tiers[i].from <= plan.tiers[i - 1].from) fail('invalid_plan', 'tiers must be sorted by "from", without repeats');
    }
  }
  plan.minEarning = planInt(config.minEarning, 'minEarning', 1, Number.MAX_SAFE_INTEGER, 1);
  plan.durationSeconds = planInt(config.durationSeconds, 'durationSeconds', 1, Number.MAX_SAFE_INTEGER, null);
  plan.perEarningCap = planInt(config.perEarningCap, 'perEarningCap', 0, Number.MAX_SAFE_INTEGER, null);
  plan.perRefereeCap = planInt(config.perRefereeCap, 'perRefereeCap', 0, Number.MAX_SAFE_INTEGER, null);
  if (config.perReferrerCap === undefined || config.perReferrerCap === null) {
    plan.perReferrerCap = null;
  } else {
    obj(config.perReferrerCap, 'perReferrerCap');
    plan.perReferrerCap = {
      amount: planInt(config.perReferrerCap.amount, 'perReferrerCap.amount', 0, Number.MAX_SAFE_INTEGER),
      periodSeconds: planInt(config.perReferrerCap.periodSeconds, 'perReferrerCap.periodSeconds', 1, Number.MAX_SAFE_INTEGER),
    };
  }
  return plan;
}

export function asPlan(plan) {
  return plan && plan.minEarning !== undefined && plan.durationSeconds !== undefined ? plan : definePlan(plan);
}

/**
 * Commission for one earning before caps. `referees` and `volume` describe the referrer
 * before this earning; `paidBefore` says whether this referee already produced a paid commission.
 */
export function commissionFor(planInput, amount, stats = {}) {
  const plan = asPlan(planInput);
  int(amount, 'amount');
  const referees = int(stats.referees ?? 0, 'referees');
  const volume = int(stats.volume ?? 0, 'volume');
  const paidBefore = stats.paidBefore === true;
  if (amount < plan.minEarning) return { amount: 0, rateBps: null, tier: null, reason: 'below_minimum' };
  if (plan.type === 'flat') {
    if (plan.trigger === 'first' && paidBefore) return { amount: 0, rateBps: null, tier: null, reason: 'flat_already_paid' };
    return { amount: plan.amount, rateBps: null, tier: null, reason: null };
  }
  if (plan.type === 'percentage') return { amount: mulDiv(amount, plan.rateBps, BPS), rateBps: plan.rateBps, tier: null, reason: null };
  const measure = plan.basis === 'volume' ? volume : referees;
  let tier = 0;
  plan.tiers.forEach((t, i) => {
    if (measure >= t.from) tier = i;
  });
  const rateBps = plan.tiers[tier].rateBps;
  return { amount: mulDiv(amount, rateBps, BPS), rateBps, tier, reason: null };
}


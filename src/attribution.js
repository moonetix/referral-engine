import { fail, id, int, oneOf, strList } from './util.js';
import { ipPrefix } from './ip.js';

const ACTIONS = ['block', 'review'];

function profile(p, name) {
  if (p === undefined || p === null) return { deviceIds: [], ips: [], payoutDestinations: [] };
  if (typeof p !== 'object') fail('invalid_argument', `${name} must be an object`);
  return {
    deviceIds: strList(p.deviceIds, `${name}.deviceIds`),
    ips: strList(p.ips, `${name}.ips`),
    payoutDestinations: strList(p.payoutDestinations, `${name}.payoutDestinations`),
  };
}

function shared(a, b) {
  const set = new Set(b);
  return [...new Set(a.filter((x) => set.has(x)))];
}

export function selfReferral(opts = {}) {
  const action = oneOf(opts.action, 'action', ACTIONS, 'block');
  return (ctx) => (ctx.referrerId === ctx.referee.userId ? { code: 'self_referral', action, message: 'The referrer and the new account are the same user.' } : null);
}

export function sameDevice(opts = {}) {
  const action = oneOf(opts.action, 'action', ACTIONS, 'block');
  return (ctx) => {
    const n = shared(ctx.referee.deviceIds, ctx.referrer.deviceIds).length;
    if (n === 0) return null;
    return { code: 'same_device', action, message: `The new account signed up on a device the referrer has used (${n} shared).` };
  };
}

export function sameIp(opts = {}) {
  const action = oneOf(opts.action, 'action', ACTIONS, 'review');
  const prefixes = { ipv4Prefix: int(opts.ipv4Prefix ?? 32, 'ipv4Prefix', 0, 32), ipv6Prefix: int(opts.ipv6Prefix ?? 64, 'ipv6Prefix', 0, 128) };
  return (ctx) => {
    const nets = shared(
      ctx.referee.ips.map((ip) => ipPrefix(ip, prefixes)),
      ctx.referrer.ips.map((ip) => ipPrefix(ip, prefixes)),
    );
    if (nets.length === 0) return null;
    return { code: 'same_ip', action, message: `The new account and the referrer share the network ${nets.join(', ')}.` };
  };
}

export function samePayoutDestination(opts = {}) {
  const action = oneOf(opts.action, 'action', ACTIONS, 'block');
  return (ctx) => {
    if (shared(ctx.referee.payoutDestinations, ctx.referrer.payoutDestinations).length === 0) return null;
    return { code: 'same_payout_destination', action, message: 'The new account and the referrer use the same payout destination.' };
  };
}

const BUILT_IN = { selfReferral, sameDevice, sameIp, samePayoutDestination };

export function defaultChecks() {
  return [selfReferral(), sameDevice(), sameIp(), samePayoutDestination()];
}

function toCheck(c, i) {
  if (typeof c === 'function') return c;
  if (c && typeof c === 'object' && typeof c.check === 'string' && BUILT_IN[c.check]) return BUILT_IN[c.check](c);
  fail('invalid_argument', `rules.checks[${i}] must be a function or { check: "selfReferral" | "sameDevice" | "sameIp" | "samePayoutDestination" }`);
}

function checkReason(r, i) {
  if (r === null || r === undefined || r === false) return null;
  if (typeof r !== 'object' || typeof r.code !== 'string' || !ACTIONS.includes(r.action)) fail('invalid_argument', `check ${i} must return null or { code, action: "block" | "review", message }`);
  return { code: r.code, action: r.action, message: typeof r.message === 'string' ? r.message : r.code };
}

/**
 * Pick the referrer for a new account from the referral touches recorded for it.
 * Candidates are tried in model order; a candidate with any "block" reason is skipped.
 */
export function attribute({ signup, touches = [], referrers = {}, rules = {} } = {}) {
  if (!signup || typeof signup !== 'object') fail('invalid_argument', 'signup is required');
  const referee = { userId: id(signup.userId, 'signup.userId'), at: int(signup.at, 'signup.at'), ...profile(signup, 'signup') };
  const model = oneOf(rules.model, 'rules.model', ['first', 'last'], 'last');
  const windowSeconds = int(rules.windowSeconds ?? 2_592_000, 'rules.windowSeconds', 0);
  const grace = int(rules.typedCodeGraceSeconds ?? 604_800, 'rules.typedCodeGraceSeconds', 0);
  if (rules.checks !== undefined && !Array.isArray(rules.checks)) fail('invalid_argument', 'rules.checks must be an array');
  const checks = rules.checks === undefined ? defaultChecks() : rules.checks.map(toCheck);
  if (!Array.isArray(touches)) fail('invalid_argument', 'touches must be an array');
  if (referrers === null || typeof referrers !== 'object') fail('invalid_argument', 'referrers must be an object keyed by referrer ID');

  const eligible = [];
  touches.forEach((t, i) => {
    if (!t || typeof t !== 'object') fail('invalid_argument', `touches[${i}] must be an object`);
    const touch = { referrerId: id(t.referrerId, `touches[${i}].referrerId`), at: int(t.at, `touches[${i}].at`), source: oneOf(t.source, `touches[${i}].source`, ['link', 'typed'], 'link') };
    if (t.code !== undefined) touch.code = t.code;
    const ok = touch.at <= referee.at ? referee.at - touch.at <= windowSeconds : touch.source === 'typed' && touch.at - referee.at <= grace;
    if (ok) eligible.push({ touch, i });
  });

  if (eligible.length === 0) {
    return { decision: 'none', referrerId: null, touch: null, reasons: [{ code: 'no_eligible_touch', action: 'info', message: 'No referral touch falls inside the attribution window.' }] };
  }

  eligible.sort((a, b) => (model === 'first' ? a.touch.at - b.touch.at || a.i - b.i : b.touch.at - a.touch.at || b.i - a.i));

  const reasons = [];
  const tried = new Set();
  for (const { touch } of eligible) {
    if (tried.has(touch.referrerId)) continue;
    tried.add(touch.referrerId);
    const ctx = { referee, referrerId: touch.referrerId, referrer: profile(referrers[touch.referrerId], `referrers.${touch.referrerId}`), touch };
    const found = [];
    checks.forEach((check, i) => {
      const r = checkReason(check(ctx), i);
      if (r) found.push({ ...r, referrerId: touch.referrerId });
    });
    reasons.push(...found);
    if (!found.some((r) => r.action === 'block')) {
      return { decision: found.length > 0 ? 'review' : 'attributed', referrerId: touch.referrerId, touch, reasons };
    }
  }
  return { decision: 'blocked', referrerId: null, touch: null, reasons };
}

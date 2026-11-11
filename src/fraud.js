import { BPS, duration, fail, id, int, joinList, mulDiv, optInt, percentFromBps, plural, strList } from './util.js';
import { ipPrefix } from './ip.js';

export const DEFAULT_WEIGHTS = Object.freeze({
  sharedPayoutWithReferrer: 40,
  sharedDeviceWithReferrer: 30,
  sharedNetworkWithReferrer: 10,
  sharedPayout: 30,
  sharedDevice: 20,
  sharedNetwork: 10,
  signupBurst: 15,
  earnThenVanish: 15,
  highReversalRate: 20,
  referralRing: 40,
});

/** Groups of accounts that refer each other in a loop (strongly connected parts of the referral graph). */
export function findRings(edges) {
  if (!Array.isArray(edges)) fail('invalid_argument', 'edges must be an array of [referrerId, refereeId] pairs');
  const order = new Map();
  const out = new Map();
  const inn = new Map();
  const selfLoop = new Set();
  const node = (v) => {
    if (!order.has(v)) {
      order.set(v, order.size);
      out.set(v, []);
      inn.set(v, []);
    }
  };
  edges.forEach((e, i) => {
    if (!Array.isArray(e) || e.length !== 2) fail('invalid_argument', `edges[${i}] must be a [referrerId, refereeId] pair`);
    const a = id(e[0], `edges[${i}][0]`);
    const b = id(e[1], `edges[${i}][1]`);
    node(a);
    node(b);
    out.get(a).push(b);
    inn.get(b).push(a);
    if (a === b) selfLoop.add(a);
  });
  const finished = [];
  const visited = new Set();
  for (const start of order.keys()) {
    if (visited.has(start)) continue;
    visited.add(start);
    const stack = [[start, 0]];
    while (stack.length) {
      const top = stack[stack.length - 1];
      const next = out.get(top[0])[top[1]++];
      if (next === undefined) {
        finished.push(top[0]);
        stack.pop();
      } else if (!visited.has(next)) {
        visited.add(next);
        stack.push([next, 0]);
      }
    }
  }
  const assigned = new Set();
  const rings = [];
  for (let k = finished.length - 1; k >= 0; k--) {
    const root = finished[k];
    if (assigned.has(root)) continue;
    const members = [];
    const stack = [root];
    assigned.add(root);
    while (stack.length) {
      const v = stack.pop();
      members.push(v);
      for (const u of inn.get(v)) {
        if (!assigned.has(u)) {
          assigned.add(u);
          stack.push(u);
        }
      }
    }
    if (members.length > 1 || selfLoop.has(root)) rings.push(members.sort((x, y) => order.get(x) - order.get(y)));
  }
  return rings.sort((x, y) => order.get(x[0]) - order.get(y[0]));
}

function account(r, i) {
  if (!r || typeof r !== 'object') fail('invalid_argument', `referees[${i}] must be an object`);
  return {
    id: id(r.id, `referees[${i}].id`),
    deviceIds: strList(r.deviceIds, `referees[${i}].deviceIds`),
    ips: strList(r.ips, `referees[${i}].ips`),
    payoutDestinations: strList(r.payoutDestinations, `referees[${i}].payoutDestinations`),
    signedUpAt: int(r.signedUpAt, `referees[${i}].signedUpAt`),
    firstEarningAt: optInt(r.firstEarningAt, `referees[${i}].firstEarningAt`),
    lastActiveAt: optInt(r.lastActiveAt, `referees[${i}].lastActiveAt`),
    earned: int(r.earned ?? 0, `referees[${i}].earned`),
    reversed: int(r.reversed ?? 0, `referees[${i}].reversed`),
  };
}

function clusters(referees, valuesOf, minSize) {
  const byValue = new Map();
  referees.forEach((r, i) => {
    for (const v of new Set(valuesOf(r))) {
      if (!byValue.has(v)) byValue.set(v, []);
      byValue.get(v).push(i);
    }
  });
  const groups = [...byValue.entries()].filter(([, members]) => members.length >= minSize);
  const members = new Set(groups.flatMap(([, m]) => m));
  return { values: groups.map(([v]) => v), ids: referees.filter((_, i) => members.has(i)).map((r) => r.id) };
}

/** Explainable fraud signals for one referrer and the accounts they referred. Never a verdict on its own. */
export function fraudSignals({ referrer = {}, referees = [], edges = [], now, options = {} } = {}) {
  int(now, 'now');
  if (!Array.isArray(referees)) fail('invalid_argument', 'referees must be an array');
  const o = {
    ipv4Prefix: int(options.ipv4Prefix ?? 24, 'ipv4Prefix', 0, 32),
    ipv6Prefix: int(options.ipv6Prefix ?? 64, 'ipv6Prefix', 0, 128),
    networkClusterSize: int(options.networkClusterSize ?? 3, 'networkClusterSize', 2),
    burstSize: int(options.burstSize ?? 5, 'burstSize', 2),
    burstWindowSeconds: int(options.burstWindowSeconds ?? 3600, 'burstWindowSeconds', 1),
    vanishMinCount: int(options.vanishMinCount ?? 2, 'vanishMinCount', 1),
    vanishEarnWithinSeconds: int(options.vanishEarnWithinSeconds ?? 259_200, 'vanishEarnWithinSeconds', 0),
    vanishInactiveSeconds: int(options.vanishInactiveSeconds ?? 1_209_600, 'vanishInactiveSeconds', 1),
    reversalRateBps: int(options.reversalRateBps ?? 3000, 'reversalRateBps', 1, BPS),
    reversalMinEarned: int(options.reversalMinEarned ?? 1, 'reversalMinEarned', 1),
    reviewAt: int(options.reviewAt ?? 30, 'reviewAt', 1),
    highAt: int(options.highAt ?? 60, 'highAt', 1),
  };
  const weights = { ...DEFAULT_WEIGHTS };
  for (const [k, v] of Object.entries(options.weights ?? {})) {
    if (!(k in DEFAULT_WEIGHTS)) fail('invalid_argument', `unknown weight "${k}"`);
    weights[k] = int(v, `weights.${k}`, 0, 100);
  }
  const ref = {
    id: referrer.id == null ? null : id(referrer.id, 'referrer.id'),
    deviceIds: strList(referrer.deviceIds, 'referrer.deviceIds'),
    ips: strList(referrer.ips, 'referrer.ips'),
    payoutDestinations: strList(referrer.payoutDestinations, 'referrer.payoutDestinations'),
  };
  const list = referees.map(account);
  const net = (ips) => ips.map((ip) => ipPrefix(ip, o));
  const accounts = (n) => plural(n, 'referred account', 'referred accounts');
  const signals = [];
  const add = (code, weightKey, message, accountIds) => signals.push({ code, weight: weights[weightKey], message, accountIds });

  const withReferrer = (valuesOf, refValues) => {
    const set = new Set(refValues);
    return list.filter((r) => valuesOf(r).some((v) => set.has(v))).map((r) => r.id);
  };
  let ids = withReferrer((r) => r.payoutDestinations, ref.payoutDestinations);
  if (ids.length) add('shared_payout_with_referrer', 'sharedPayoutWithReferrer', `${accounts(ids.length)} ${ids.length === 1 ? 'cashes' : 'cash'} out to a payout destination the referrer also uses.`, ids);
  ids = withReferrer((r) => r.deviceIds, ref.deviceIds);
  if (ids.length) add('shared_device_with_referrer', 'sharedDeviceWithReferrer', `${accounts(ids.length)} used a device the referrer also uses.`, ids);
  const refNets = [...new Set(net(ref.ips))];
  ids = withReferrer((r) => net(r.ips), refNets);
  if (ids.length) {
    const used = new Set(list.flatMap((r) => net(r.ips)));
    add('shared_network_with_referrer', 'sharedNetworkWithReferrer', `${accounts(ids.length)} used the referrer's network (${refNets.filter((n) => used.has(n)).join(', ')}).`, ids);
  }

  let c = clusters(list, (r) => r.payoutDestinations, 2);
  if (c.ids.length) add('shared_payout_among_referees', 'sharedPayout', `${accounts(c.ids.length)} share ${plural(c.values.length, 'payout destination', 'payout destinations')} with each other.`, c.ids);
  c = clusters(list, (r) => r.deviceIds, 2);
  if (c.ids.length) add('shared_device_among_referees', 'sharedDevice', `${accounts(c.ids.length)} share ${plural(c.values.length, 'device', 'devices')} with each other.`, c.ids);
  c = clusters(list, (r) => net(r.ips), o.networkClusterSize);
  if (c.ids.length) add('shared_network_among_referees', 'sharedNetwork', `${accounts(c.ids.length)} came from ${plural(c.values.length, 'shared network', 'shared networks')}: ${c.values.join(', ')}.`, c.ids);

  const byTime = list.map((r, i) => ({ t: r.signedUpAt, i })).sort((a, b) => a.t - b.t || a.i - b.i);
  let best = { size: 0, from: 0, to: 0 };
  for (let lo = 0, hi = 0; hi < byTime.length; hi++) {
    while (byTime[hi].t - byTime[lo].t >= o.burstWindowSeconds) lo++;
    if (hi - lo + 1 > best.size) best = { size: hi - lo + 1, from: lo, to: hi };
  }
  if (best.size >= o.burstSize) {
    const inBurst = new Set(byTime.slice(best.from, best.to + 1).map((x) => x.i));
    ids = list.filter((_, i) => inBurst.has(i)).map((r) => r.id);
    add('signup_burst', 'signupBurst', `${accounts(ids.length)} signed up within ${duration(o.burstWindowSeconds)}.`, ids);
  }

  ids = list
    .filter((r) => {
      if (r.earned <= 0 || r.firstEarningAt === null || r.firstEarningAt - r.signedUpAt > o.vanishEarnWithinSeconds) return false;
      return now - (r.lastActiveAt ?? r.firstEarningAt) >= o.vanishInactiveSeconds;
    })
    .map((r) => r.id);
  if (ids.length >= o.vanishMinCount) {
    add('earn_then_vanish', 'earnThenVanish', `${accounts(ids.length)} earned within ${duration(o.vanishEarnWithinSeconds)} of signing up and ${ids.length === 1 ? 'has' : 'have'} been inactive for ${duration(o.vanishInactiveSeconds)} or more.`, ids);
  }

  const earned = list.reduce((s, r) => s + r.earned, 0);
  const reversed = list.reduce((s, r) => s + r.reversed, 0);
  if (!Number.isSafeInteger(earned) || !Number.isSafeInteger(reversed)) fail('overflow', 'earned or reversed total exceeds 2^53 - 1');
  if (earned >= o.reversalMinEarned && earned > 0) {
    const rate = mulDiv(reversed, BPS, earned);
    if (rate >= o.reversalRateBps) {
      add('high_reversal_rate', 'highReversalRate', `Reversals are ${percentFromBps(rate)} of referred earnings (${reversed} of ${earned}).`, list.filter((r) => r.reversed > 0).map((r) => r.id));
    }
  }

  const involved = new Set(list.map((r) => r.id));
  if (ref.id !== null) involved.add(ref.id);
  const rings = findRings(edges).filter((ring) => ring.some((m) => involved.has(m)));
  if (rings.length) {
    const text = rings.map((ring) => (ring.length === 1 ? `Account ${ring[0]} refers itself.` : `Accounts ${joinList(ring)} refer each other in a loop.`)).join(' ');
    add('referral_ring', 'referralRing', text, [...new Set(rings.flat())]);
  }

  const score = Math.min(100, signals.reduce((s, x) => s + x.weight, 0));
  const level = score >= o.highAt ? 'high' : score >= o.reviewAt ? 'review' : 'low';
  return { score, level, signals };
}

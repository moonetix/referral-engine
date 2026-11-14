export const MAX_AMOUNT = Number.MAX_SAFE_INTEGER;
export const BPS = 10_000;

export class ReferralError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'ReferralError';
    this.code = code;
  }
}

export function fail(code, message) {
  throw new ReferralError(code, message);
}

export function int(value, name, min = 0, max = MAX_AMOUNT) {
  if (typeof value !== 'number' || !Number.isSafeInteger(value)) fail('invalid_argument', `${name} must be a safe integer`);
  if (value < min || value > max) fail('invalid_argument', `${name} must be from ${min} to ${max}`);
  return value;
}

export function optInt(value, name, min = 0, max = MAX_AMOUNT) {
  return value === undefined || value === null ? null : int(value, name, min, max);
}

const utf8 = new TextEncoder();

export function id(value, name) {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return String(value);
  if (typeof value !== 'string' || value === '' || utf8.encode(value).length > 191) fail('invalid_argument', `${name} must be a non-empty string of at most 191 bytes or an integer`);
  return value;
}

export function optId(value, name) {
  return value === undefined || value === null ? null : id(value, name);
}

export function strList(value, name) {
  if (value === undefined || value === null) return [];
  if (!Array.isArray(value)) fail('invalid_argument', `${name} must be an array of strings`);
  return value.map((v, i) => {
    if (typeof v !== 'string' || v === '') fail('invalid_argument', `${name}[${i}] must be a non-empty string`);
    return v;
  });
}

export function oneOf(value, name, allowed, fallback) {
  if (value === undefined || value === null) return fallback;
  if (!allowed.includes(value)) fail('invalid_argument', `${name} must be one of ${allowed.join(', ')}`);
  return value;
}

/** floor(a * b / d) for non-negative safe integers, exact. */
export function mulDiv(a, b, d) {
  const q = (BigInt(a) * BigInt(b)) / BigInt(d);
  if (q > BigInt(MAX_AMOUNT)) fail('overflow', 'result exceeds 2^53 - 1');
  return Number(q);
}

/** A queue per key: calls for one key run one after another, in call order, inside this process. */
export function keyLock() {
  const tails = new Map();
  return async (key, fn) => {
    const prev = tails.get(key) ?? Promise.resolve();
    let release;
    const mine = new Promise((resolve) => {
      release = resolve;
    });
    const tail = prev.then(() => mine);
    tails.set(key, tail);
    await prev;
    try {
      return await fn();
    } finally {
      release();
      if (tails.get(key) === tail) tails.delete(key);
    }
  };
}

export function plural(n, one, many) {
  return `${n} ${n === 1 ? one : many}`;
}

export function joinList(items) {
  if (items.length <= 1) return items.join('');
  return `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

export function duration(seconds) {
  if (seconds % 86400 === 0) return plural(seconds / 86400, 'day', 'days');
  if (seconds % 3600 === 0) return plural(seconds / 3600, 'hour', 'hours');
  if (seconds % 60 === 0) return plural(seconds / 60, 'minute', 'minutes');
  return plural(seconds, 'second', 'seconds');
}

export function percentFromBps(bps) {
  const whole = Math.floor(bps / 100);
  const frac = bps % 100;
  if (frac === 0) return `${whole}%`;
  return `${whole}.${String(frac).padStart(2, '0').replace(/0$/, '')}%`;
}

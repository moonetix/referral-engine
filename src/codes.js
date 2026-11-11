import { fail, int } from './util.js';

// 29 symbols: no 0 O 1 I L U Z. A prime size lets the check character catch
// every single-character error and every swap of two neighbors.
export const DEFAULT_ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXY';

const LOOKALIKES = [['O', '0'], ['I', '1'], ['L', '1']];

function isPrime(n) {
  if (n < 2) return false;
  for (let i = 2; i * i <= n; i++) if (n % i === 0) return false;
  return true;
}

function checkAlphabet(alphabet) {
  if (alphabet === undefined || alphabet === null) return DEFAULT_ALPHABET;
  if (typeof alphabet !== 'string' || !/^[0-9A-Z]{2,64}$/.test(alphabet)) fail('invalid_argument', 'alphabet must be 2 to 64 characters from 0-9 and A-Z');
  if (new Set(alphabet).size !== alphabet.length) fail('invalid_argument', 'alphabet has repeated characters');
  return alphabet;
}

function options(opts = {}) {
  const alphabet = checkAlphabet(opts.alphabet);
  const checkChar = opts.checkChar === true;
  const length = int(opts.length ?? 8, 'length', 4, 64);
  if (checkChar) {
    if (!isPrime(alphabet.length)) fail('alphabet_not_prime', `a check character needs an alphabet of prime size, got ${alphabet.length}`);
    if (length + 1 >= alphabet.length) fail('invalid_argument', `with a check character, length must be below ${alphabet.length - 1}`);
  }
  return { alphabet, checkChar, length };
}

function weightedSum(values, p) {
  let s = 0;
  values.forEach((v, i) => {
    s = (s + (i + 1) * v) % p;
  });
  return s;
}

function inverse(a, p) {
  for (let x = 1; x < p; x++) if ((a * x) % p === 1) return x;
  return 0;
}

/** Check character that makes sum((i + 1) * value[i]) divisible by the alphabet size. */
export function checkCharFor(code, opts = {}) {
  const alphabet = checkAlphabet(opts.alphabet);
  const p = alphabet.length;
  if (!isPrime(p)) fail('alphabet_not_prime', `a check character needs an alphabet of prime size, got ${p}`);
  if (typeof code !== 'string' || code.length === 0 || code.length + 1 >= p) fail('invalid_code', 'code is empty or too long for a check character');
  const values = [...code].map((ch) => {
    const v = alphabet.indexOf(ch);
    if (v < 0) fail('invalid_code', `"${ch}" is not in the alphabet`);
    return v;
  });
  const s = weightedSum(values, p);
  return alphabet[(((p - s) % p) * inverse(code.length + 1, p)) % p];
}

function defaultRandomBytes(n) {
  return globalThis.crypto.getRandomValues(new Uint8Array(n));
}

export function generateCode(opts = {}) {
  const { alphabet, checkChar, length } = options(opts);
  const randomBytes = opts.randomBytes ?? defaultRandomBytes;
  if (typeof randomBytes !== 'function') fail('invalid_argument', 'randomBytes must be a function');
  const n = alphabet.length;
  const limit = 256 - (256 % n);
  let code = '';
  while (code.length < length) {
    const bytes = randomBytes(length - code.length);
    if (!bytes || typeof bytes.length !== 'number' || bytes.length === 0) fail('invalid_argument', 'randomBytes returned no bytes');
    for (const b of bytes) {
      if (b < limit && code.length < length) code += alphabet[b % n];
    }
  }
  return checkChar ? code + checkCharFor(code, { alphabet }) : code;
}

/** Uppercase, drop spaces and hyphens, map lookalikes the alphabet lacks. Null when it cannot be a code. */
export function normalizeCode(input, opts = {}) {
  const alphabet = checkAlphabet(opts.alphabet);
  if (typeof input !== 'string') fail('invalid_argument', 'code must be a string');
  let s = input.replace(/[ \t\n\r\f\x0B-]+/g, '').replace(/[a-z]/g, (c) => c.toUpperCase());
  for (const [from, to] of LOOKALIKES) {
    if (!alphabet.includes(from) && alphabet.includes(to)) s = s.split(from).join(to);
  }
  if (s === '' || [...s].some((ch) => !alphabet.includes(ch))) return null;
  return s;
}

export function isValidCode(input, opts = {}) {
  const { alphabet, checkChar, length } = options(opts);
  const code = normalizeCode(input, { alphabet });
  if (code === null || code.length !== length + (checkChar ? 1 : 0)) return false;
  if (!checkChar) return true;
  const values = [...code].map((ch) => alphabet.indexOf(ch));
  return weightedSum(values, alphabet.length) === 0;
}

export function formatCode(code, opts = {}) {
  const groupSize = int(opts.groupSize ?? 4, 'groupSize', 1, 64);
  const separator = opts.separator ?? '-';
  if (typeof code !== 'string' || typeof separator !== 'string') fail('invalid_argument', 'code and separator must be strings');
  const parts = [];
  for (let i = 0; i < code.length; i += groupSize) parts.push(code.slice(i, i + groupSize));
  return parts.join(separator);
}

export async function createUniqueCode(exists, opts = {}) {
  if (typeof exists !== 'function') fail('invalid_argument', 'exists must be a function');
  const maxAttempts = int(opts.maxAttempts ?? 10, 'maxAttempts', 1, 1000);
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    const code = generateCode(opts);
    if (!(await exists(code))) return code;
  }
  fail('code_space_exhausted', `no free code after ${maxAttempts} attempts; use longer codes`);
}

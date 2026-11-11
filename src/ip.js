import { fail, int } from './util.js';

function parseV4(s) {
  const parts = s.split('.');
  if (parts.length !== 4) return null;
  const out = [];
  for (const p of parts) {
    if (!/^(0|[1-9]\d{0,2})$/.test(p)) return null;
    const v = Number(p);
    if (v > 255) return null;
    out.push(v);
  }
  return out;
}

function parseV6(s) {
  if (!/^[0-9a-fA-F:.]+$/.test(s)) return null;
  const halves = s.split('::');
  if (halves.length > 2) return null;
  const groups = (part) => {
    if (part === '') return [];
    const out = [];
    const items = part.split(':');
    for (let i = 0; i < items.length; i++) {
      const g = items[i];
      if (i === items.length - 1 && g.includes('.')) {
        const v4 = parseV4(g);
        if (!v4) return null;
        out.push((v4[0] << 8) | v4[1], (v4[2] << 8) | v4[3]);
      } else if (/^[0-9a-fA-F]{1,4}$/.test(g)) {
        out.push(parseInt(g, 16));
      } else {
        return null;
      }
    }
    return out;
  };
  const head = groups(halves[0]);
  const tail = halves.length === 2 ? groups(halves[1]) : [];
  if (!head || !tail) return null;
  if (halves.length === 2 && halves[0].includes('.')) return null;
  if (halves.length === 1 && head.length !== 8) return null;
  if (halves.length === 2 && head.length + tail.length > 7) return null;
  const all = halves.length === 2 ? [...head, ...new Array(8 - head.length - tail.length).fill(0), ...tail] : head;
  return all;
}

/** Parse an IP address into { version, bytes }. IPv4-mapped IPv6 addresses become IPv4. */
export function parseIp(text) {
  if (typeof text !== 'string') fail('invalid_ip', 'IP address must be a string');
  const s = text.replace(/^[ \t\n\r\f\x0B]+|[ \t\n\r\f\x0B]+$/g, '');
  const v4 = parseV4(s);
  if (v4) return { version: 4, bytes: v4 };
  const g = s.includes(':') ? parseV6(s) : null;
  if (!g) fail('invalid_ip', `not an IP address: "${text}"`);
  if (g.slice(0, 5).every((x) => x === 0) && g[5] === 0xffff) {
    return { version: 4, bytes: [g[6] >> 8, g[6] & 255, g[7] >> 8, g[7] & 255] };
  }
  const bytes = [];
  for (const x of g) bytes.push(x >> 8, x & 255);
  return { version: 6, bytes };
}

function mask(bytes, prefix) {
  return bytes.map((b, i) => {
    const bits = Math.max(0, Math.min(8, prefix - i * 8));
    return bits === 8 ? b : b & ((0xff << (8 - bits)) & 0xff);
  });
}

function formatV6(bytes) {
  const g = [];
  for (let i = 0; i < 16; i += 2) g.push((bytes[i] << 8) | bytes[i + 1]);
  let bestStart = -1;
  let bestLen = 0;
  for (let i = 0; i < 8; ) {
    if (g[i] !== 0) {
      i++;
      continue;
    }
    let j = i;
    while (j < 8 && g[j] === 0) j++;
    if (j - i > bestLen && j - i >= 2) {
      bestStart = i;
      bestLen = j - i;
    }
    i = j;
  }
  const hex = g.map((x) => x.toString(16));
  if (bestStart < 0) return hex.join(':');
  return `${hex.slice(0, bestStart).join(':')}::${hex.slice(bestStart + bestLen).join(':')}`;
}

/** The network an address belongs to, as CIDR text: "203.0.113.0/24", "2001:db8:1:2::/64". */
export function ipPrefix(ip, opts = {}) {
  const v4 = int(opts.ipv4Prefix ?? 24, 'ipv4Prefix', 0, 32);
  const v6 = int(opts.ipv6Prefix ?? 64, 'ipv6Prefix', 0, 128);
  const { version, bytes } = parseIp(ip);
  if (version === 4) return `${mask(bytes, v4).join('.')}/${v4}`;
  return `${formatV6(mask(bytes, v6))}/${v6}`;
}

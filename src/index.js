// referral-engine: referral codes, attribution, commission plans and an append-only
// commission ledger. Amounts are integers in your smallest unit; instants are Unix seconds.

export { ReferralError, MAX_AMOUNT } from './util.js';
export { DEFAULT_ALPHABET, generateCode, normalizeCode, isValidCode, checkCharFor, formatCode, createUniqueCode } from './codes.js';
export { parseIp, ipPrefix } from './ip.js';
export { attribute, defaultChecks, selfReferral, sameDevice, sameIp, samePayoutDestination } from './attribution.js';
export { definePlan, commissionFor } from './plan.js';
export { ReferralEngine, ENTRY_FIELDS } from './engine.js';
export { MemoryStore } from './memory-store.js';
export { fraudSignals, findRings, DEFAULT_WEIGHTS } from './fraud.js';

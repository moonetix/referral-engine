/** Amounts are integers in your smallest unit (cents, points, micro-dollars), at most 2^53 - 1. */
export type Amount = number;
/** Unix time in seconds (UTC). */
export type Seconds = number;
/** User, earning and payout IDs. Integers are accepted and returned as strings. */
export type Id = string | number;

export declare const MAX_AMOUNT: number;
export declare const DEFAULT_ALPHABET: string;

export type ErrorCode =
  | 'invalid_argument'
  | 'invalid_plan'
  | 'invalid_code'
  | 'invalid_ip'
  | 'alphabet_not_prime'
  | 'code_space_exhausted'
  | 'self_referral'
  | 'already_referred'
  | 'referral_cycle'
  | 'multi_level_not_acknowledged'
  | 'insufficient_balance'
  | 'overflow'
  | 'conflict';

export declare class ReferralError extends Error {
  readonly code: ErrorCode;
  constructor(code: ErrorCode, message: string);
}

// Codes

export interface CodeOptions {
  /** Characters before the check character. Default 8, from 4 to 64. */
  length?: number;
  /** Default DEFAULT_ALPHABET (29 symbols, no 0 O 1 I L U Z). Uppercase A-Z and 0-9 only. */
  alphabet?: string;
  /** Append a check character. Needs an alphabet of prime size. */
  checkChar?: boolean;
}

export interface GenerateCodeOptions extends CodeOptions {
  /** Source of random bytes. Default crypto.getRandomValues. Inject one for tests. */
  randomBytes?: (n: number) => Uint8Array;
}

export declare function generateCode(options?: GenerateCodeOptions): string;
export declare function normalizeCode(input: string, options?: { alphabet?: string }): string | null;
export declare function isValidCode(input: string, options?: CodeOptions): boolean;
export declare function checkCharFor(code: string, options?: { alphabet?: string }): string;
export declare function formatCode(code: string, options?: { groupSize?: number; separator?: string }): string;
export declare function createUniqueCode(
  exists: (code: string) => boolean | Promise<boolean>,
  options?: GenerateCodeOptions & { maxAttempts?: number },
): Promise<string>;

// IP helpers

export declare function parseIp(ip: string): { version: 4 | 6; bytes: number[] };
export declare function ipPrefix(ip: string, options?: { ipv4Prefix?: number; ipv6Prefix?: number }): string;

// Attribution

export interface Profile {
  deviceIds?: string[];
  ips?: string[];
  /** Normalized and hashed payout destinations, such as "paypal:" + sha256(lowercased email). */
  payoutDestinations?: string[];
}

export interface Signup extends Profile {
  userId: Id;
  at: Seconds;
}

export interface Touch {
  referrerId: Id;
  at: Seconds;
  /** "typed" codes may arrive after sign-up, inside typedCodeGraceSeconds. Default "link". */
  source?: 'link' | 'typed';
  code?: string;
}

export interface Reason {
  code: string;
  action: 'block' | 'review';
  message: string;
  referrerId: string;
}

export interface CheckContext {
  referee: Required<Profile> & { userId: string; at: Seconds };
  referrerId: string;
  referrer: Required<Profile>;
  touch: { referrerId: string; at: Seconds; source: 'link' | 'typed'; code?: string };
}

export type Check = (ctx: CheckContext) => { code: string; action: 'block' | 'review'; message?: string } | null | undefined | false;

export interface CheckOptions {
  action?: 'block' | 'review';
}

export type CheckDescriptor =
  | ({ check: 'selfReferral' | 'sameDevice' | 'samePayoutDestination' } & CheckOptions)
  | ({ check: 'sameIp'; ipv4Prefix?: number; ipv6Prefix?: number } & CheckOptions);

export interface AttributionRules {
  /** Default "last". */
  model?: 'first' | 'last';
  /** How old a touch may be at sign-up. Default 2,592,000 (30 days). */
  windowSeconds?: Seconds;
  /** How long after sign-up a typed code still counts. Default 604,800 (7 days). */
  typedCodeGraceSeconds?: Seconds;
  /** Default: selfReferral, sameDevice, sameIp, samePayoutDestination with their default actions. */
  checks?: Array<Check | CheckDescriptor>;
}

export interface AttributionResult {
  decision: 'attributed' | 'review' | 'blocked' | 'none';
  referrerId: string | null;
  touch: { referrerId: string; at: Seconds; source: 'link' | 'typed'; code?: string } | null;
  reasons: Array<Reason | { code: 'no_eligible_touch'; action: 'info'; message: string }>;
}

export declare function attribute(input: {
  signup: Signup;
  touches?: Touch[];
  referrers?: Record<string, Profile>;
  rules?: AttributionRules;
}): AttributionResult;

export declare function defaultChecks(): Check[];
export declare function selfReferral(options?: CheckOptions): Check;
export declare function sameDevice(options?: CheckOptions): Check;
export declare function sameIp(options?: CheckOptions & { ipv4Prefix?: number; ipv6Prefix?: number }): Check;
export declare function samePayoutDestination(options?: CheckOptions): Check;

// Plans

interface PlanCommon {
  /** Earnings below this produce no commission and do not count as qualifying. Default 1. */
  minEarning?: Amount;
  /** Commission stops this many seconds after attribution. Default null (lifetime). */
  durationSeconds?: Seconds | null;
  perEarningCap?: Amount | null;
  /** Lifetime cap on commission from one referee, net of clawbacks. */
  perRefereeCap?: Amount | null;
  /** Cap per referrer per fixed UTC window of periodSeconds. */
  perReferrerCap?: { amount: Amount; periodSeconds: Seconds } | null;
}

export type PlanConfig =
  | (PlanCommon & { type: 'percentage'; rateBps: number })
  | (PlanCommon & { type: 'flat'; amount: Amount; trigger?: 'first' | 'every' })
  | (PlanCommon & { type: 'tiered'; basis?: 'referees' | 'volume'; tiers: Array<{ from: number; rateBps: number }> });

export type Plan = Required<PlanCommon> &
  (
    | { type: 'percentage'; rateBps: number }
    | { type: 'flat'; amount: Amount; trigger: 'first' | 'every' }
    | { type: 'tiered'; basis: 'referees' | 'volume'; tiers: Array<{ from: number; rateBps: number }> }
  );

export declare function definePlan(config: PlanConfig): Plan;

export interface Commission {
  amount: Amount;
  rateBps: number | null;
  tier: number | null;
  reason: null | 'below_minimum' | 'flat_already_paid';
}

/** Commission for one earning before caps. Stats describe the referrer before this earning. */
export declare function commissionFor(plan: PlanConfig | Plan, amount: Amount, stats?: { referees?: number; volume?: Amount; paidBefore?: boolean }): Commission;

// Ledger

export interface Referral {
  refereeId: string;
  referrerId: string;
  attributedAt: Seconds;
  code: string | null;
}

export interface LedgerEntry {
  /** Idempotency key, unique in the ledger. */
  key: string;
  type: 'accrual' | 'clawback' | 'payout' | 'adjustment';
  referrerId: string;
  refereeId: string | null;
  earningId: string | null;
  level: number | null;
  /** Signed: accruals and positive adjustments add, clawbacks and payouts subtract. */
  amount: Amount;
  /** The referee earning behind an accrual (positive) or the part reversed (negative). */
  baseAmount: Amount;
  /** Clawbacks only: what could not be taken back because the floor was on. */
  uncollected: Amount;
  at: Seconds;
  availableAt: Seconds;
}

export declare const ENTRY_FIELDS: ReadonlyArray<keyof LedgerEntry>;

type MaybePromise<T> = T | Promise<T>;

export interface Store {
  getReferral(refereeId: string): MaybePromise<Referral | null>;
  /** Resolve false when the referee already has a referral. */
  insertReferral(referral: Referral): MaybePromise<boolean>;
  /** Entries matching every given field, in insertion order. */
  listEntries(filter: { referrerId?: string; refereeId?: string; earningId?: string }): MaybePromise<LedgerEntry[]>;
  /** Write all entries or none. Resolve false, writing nothing, when any key already exists. */
  appendEntries(entries: LedgerEntry[]): MaybePromise<boolean>;
  /** Optional: run fn without interleaving other writes for the same key. */
  withLock?<T>(key: string, fn: () => Promise<T>): Promise<T>;
}

export declare class MemoryStore implements Store {
  getReferral(refereeId: string): Referral | null;
  insertReferral(referral: Referral): boolean;
  listEntries(filter?: { referrerId?: string; refereeId?: string; earningId?: string }): LedgerEntry[];
  appendEntries(entries: LedgerEntry[]): boolean;
  withLock<T>(key: string, fn: () => Promise<T>): Promise<T>;
}

export interface EngineOptions {
  store: Store;
  plan: PlanConfig | Plan;
  /** Seconds before an accrual becomes available. Default 0. */
  holdSeconds?: Seconds;
  /** When false, a clawback of released commission takes at most the available balance. Default true. */
  allowNegativeBalance?: boolean;
  /** Available balance to use for that floor when commission is moved to another wallet. */
  availableBalance?: (referrerId: string, at: Seconds) => MaybePromise<Amount>;
  /** Off by default. See the README before turning it on. */
  multiLevel?: { levels: number[]; acknowledgeRegulatoryRisk: true } | null;
}

export interface CommissionLine {
  level: number;
  referrerId: string;
  amount: Amount;
  availableAt: Seconds;
}

export interface EarningResult {
  status: 'accrued' | 'duplicate' | 'none';
  reason: null | 'no_referrer' | 'before_referral' | 'expired' | 'below_minimum' | 'flat_already_paid';
  capped: Array<'per_earning_cap' | 'per_referee_cap' | 'per_referrer_cap'>;
  commissions: CommissionLine[];
}

export interface ReversalResult {
  status: 'reversed' | 'duplicate' | 'none';
  reason: null | 'unknown_earning' | 'already_reversed' | 'capped_at_remaining';
  clawbacks: Array<{ level: number; referrerId: string; amount: Amount; uncollected: Amount }>;
}

export interface Balance {
  pending: Amount;
  available: Amount;
  total: Amount;
  uncollected: Amount;
}

export declare class ReferralEngine {
  readonly plan: Plan;
  constructor(options: EngineOptions);
  attach(input: { refereeId: Id; referrerId: Id; at: Seconds; code?: string | null }): Promise<{ status: 'attached' | 'existing'; referral: Referral }>;
  recordEarning(input: { earningId: Id; refereeId: Id; amount: Amount; at: Seconds }): Promise<EarningResult>;
  /** amount: the part of the referee's earning that was reversed. Omit it for the rest of the earning. */
  reverseEarning(input: { earningId: Id; reversalId?: Id; amount?: Amount | null; at: Seconds }): Promise<ReversalResult>;
  recordPayout(input: { payoutId: Id; referrerId: Id; amount: Amount; at: Seconds }): Promise<{ status: 'recorded' | 'duplicate'; entry: LedgerEntry }>;
  recordAdjustment(input: { adjustmentId: Id; referrerId: Id; amount: Amount; at: Seconds }): Promise<{ status: 'recorded' | 'duplicate'; entry: LedgerEntry }>;
  balance(referrerId: Id, at: Seconds): Promise<Balance>;
  ledger(referrerId: Id): Promise<LedgerEntry[]>;
}

// Fraud signals

export interface FraudWeights {
  sharedPayoutWithReferrer: number;
  sharedDeviceWithReferrer: number;
  sharedNetworkWithReferrer: number;
  sharedPayout: number;
  sharedDevice: number;
  sharedNetwork: number;
  signupBurst: number;
  earnThenVanish: number;
  highReversalRate: number;
  referralRing: number;
}

export declare const DEFAULT_WEIGHTS: Readonly<FraudWeights>;

export interface RefereeActivity extends Profile {
  id: Id;
  signedUpAt: Seconds;
  firstEarningAt?: Seconds | null;
  lastActiveAt?: Seconds | null;
  earned?: Amount;
  reversed?: Amount;
}

export interface FraudOptions {
  ipv4Prefix?: number;
  ipv6Prefix?: number;
  networkClusterSize?: number;
  burstSize?: number;
  burstWindowSeconds?: Seconds;
  vanishMinCount?: number;
  vanishEarnWithinSeconds?: Seconds;
  vanishInactiveSeconds?: Seconds;
  reversalRateBps?: number;
  reversalMinEarned?: Amount;
  reviewAt?: number;
  highAt?: number;
  weights?: Partial<FraudWeights>;
}

export type SignalCode =
  | 'shared_payout_with_referrer'
  | 'shared_device_with_referrer'
  | 'shared_network_with_referrer'
  | 'shared_payout_among_referees'
  | 'shared_device_among_referees'
  | 'shared_network_among_referees'
  | 'signup_burst'
  | 'earn_then_vanish'
  | 'high_reversal_rate'
  | 'referral_ring';

export interface FraudReport {
  score: number;
  level: 'low' | 'review' | 'high';
  signals: Array<{ code: SignalCode; weight: number; message: string; accountIds: string[] }>;
}

export declare function fraudSignals(input: {
  referrer?: Profile & { id?: Id };
  referees: RefereeActivity[];
  /** [referrerId, refereeId] pairs, for loop detection. */
  edges?: Array<[Id, Id]>;
  now: Seconds;
  options?: FraudOptions;
}): FraudReport;

export declare function findRings(edges: Array<[Id, Id]>): string[][];

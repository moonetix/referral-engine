import type { LedgerEntry, Referral, Store } from './index.js';

/** A synchronous SQLite handle: node:sqlite DatabaseSync or better-sqlite3. */
export interface SqliteHandle {
  exec(sql: string): unknown;
  prepare(sql: string): {
    run(...params: unknown[]): { changes: number | bigint };
    get(...params: unknown[]): unknown;
    all(...params: unknown[]): unknown[];
  };
}

export declare class SqliteStore implements Store {
  constructor(db: SqliteHandle);
  getReferral(refereeId: string): Referral | null;
  insertReferral(referral: Referral): boolean;
  listEntries(filter?: { referrerId?: string; refereeId?: string; earningId?: string }): LedgerEntry[];
  appendEntries(entries: LedgerEntry[]): boolean;
  withLock<T>(key: string, fn: () => Promise<T>): Promise<T>;
}

import { AsyncLocalStorage } from 'node:async_hooks';

const COLUMNS = 'entry_key, type, referrer_id, referee_id, earning_id, level, amount, base_amount, uncollected, occurred_at, available_at';

function toEntry(r) {
  return {
    key: r.entry_key,
    type: r.type,
    referrerId: r.referrer_id,
    refereeId: r.referee_id,
    earningId: r.earning_id,
    level: r.level,
    amount: Number(r.amount),
    baseAmount: Number(r.base_amount),
    uncollected: Number(r.uncollected),
    at: Number(r.occurred_at),
    availableAt: Number(r.available_at),
  };
}

/**
 * Store on a synchronous SQLite handle: node:sqlite DatabaseSync (Node 22.5+) or better-sqlite3.
 * Create the tables from schema/sqlite.sql first. One store serializes its own writes.
 */
export class SqliteStore {
  #db;
  #inside = new AsyncLocalStorage();
  #queue = Promise.resolve();
  #savepoint = 0;

  constructor(db) {
    if (!db || typeof db.prepare !== 'function' || typeof db.exec !== 'function') throw new TypeError('SqliteStore needs a SQLite handle with prepare() and exec()');
    this.#db = db;
  }

  getReferral(refereeId) {
    const r = this.#db.prepare('SELECT referee_id, referrer_id, attributed_at, code FROM referrals WHERE referee_id = ?').get(String(refereeId));
    return r ? { refereeId: r.referee_id, referrerId: r.referrer_id, attributedAt: Number(r.attributed_at), code: r.code ?? null } : null;
  }

  insertReferral(r) {
    const res = this.#db.prepare('INSERT OR IGNORE INTO referrals (referee_id, referrer_id, attributed_at, code) VALUES (?, ?, ?, ?)').run(r.refereeId, r.referrerId, r.attributedAt, r.code);
    return Number(res.changes) === 1;
  }

  listEntries(filter = {}) {
    const where = [];
    const args = [];
    for (const [key, column] of [['referrerId', 'referrer_id'], ['refereeId', 'referee_id'], ['earningId', 'earning_id']]) {
      if (filter[key] !== undefined) {
        where.push(`${column} = ?`);
        args.push(String(filter[key]));
      }
    }
    const sql = `SELECT ${COLUMNS} FROM referral_ledger${where.length ? ` WHERE ${where.join(' AND ')}` : ''} ORDER BY id`;
    return this.#db.prepare(sql).all(...args).map(toEntry);
  }

  appendEntries(entries) {
    const name = `ledger_${++this.#savepoint}`;
    const insert = this.#db.prepare(`INSERT OR IGNORE INTO referral_ledger (${COLUMNS}) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
    this.#db.exec(`SAVEPOINT ${name}`);
    try {
      for (const e of entries) {
        const res = insert.run(e.key, e.type, e.referrerId, e.refereeId, e.earningId, e.level, e.amount, e.baseAmount, e.uncollected, e.at, e.availableAt);
        if (Number(res.changes) !== 1) {
          this.#db.exec(`ROLLBACK TO ${name}`);
          this.#db.exec(`RELEASE ${name}`);
          return false;
        }
      }
      this.#db.exec(`RELEASE ${name}`);
      return true;
    } catch (err) {
      this.#db.exec(`ROLLBACK TO ${name}`);
      this.#db.exec(`RELEASE ${name}`);
      throw err;
    }
  }

  async withLock(key, fn) {
    if (this.#inside.getStore()) return fn();
    const prev = this.#queue;
    let release;
    this.#queue = new Promise((resolve) => {
      release = resolve;
    });
    await prev;
    try {
      this.#db.exec('BEGIN IMMEDIATE');
      try {
        const result = await this.#inside.run(true, fn);
        this.#db.exec('COMMIT');
        return result;
      } catch (err) {
        this.#db.exec('ROLLBACK');
        throw err;
      }
    } finally {
      release();
    }
  }
}

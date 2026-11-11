const FILTERS = ['referrerId', 'refereeId', 'earningId'];

/** In-memory store for tests and prototypes. State is lost when the process exits. */
export class MemoryStore {
  #referrals = new Map();
  #entries = [];
  #keys = new Set();
  #locks = new Map();

  getReferral(refereeId) {
    const r = this.#referrals.get(String(refereeId));
    return r ? { ...r } : null;
  }

  insertReferral(referral) {
    if (this.#referrals.has(referral.refereeId)) return false;
    this.#referrals.set(referral.refereeId, { ...referral });
    return true;
  }

  listEntries(filter = {}) {
    return this.#entries.filter((e) => FILTERS.every((f) => filter[f] === undefined || e[f] === String(filter[f]))).map((e) => ({ ...e }));
  }

  appendEntries(entries) {
    const keys = entries.map((e) => e.key);
    if (new Set(keys).size !== keys.length || keys.some((k) => this.#keys.has(k))) return false;
    for (const e of entries) {
      this.#keys.add(e.key);
      this.#entries.push({ ...e });
    }
    return true;
  }

  async withLock(key, fn) {
    const prev = this.#locks.get(key) ?? Promise.resolve();
    let release;
    const mine = new Promise((resolve) => {
      release = resolve;
    });
    const tail = prev.then(() => mine);
    this.#locks.set(key, tail);
    await prev;
    try {
      return await fn();
    } finally {
      release();
      if (this.#locks.get(key) === tail) this.#locks.delete(key);
    }
  }
}

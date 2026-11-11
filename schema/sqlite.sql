-- referral-engine reference schema for SQLite 3.
-- Instants are Unix seconds. Amounts are integers in your smallest unit.

CREATE TABLE IF NOT EXISTS referral_codes (
  user_id    TEXT    NOT NULL PRIMARY KEY,
  code       TEXT    NOT NULL UNIQUE,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS referrals (
  referee_id    TEXT    NOT NULL PRIMARY KEY,
  referrer_id   TEXT    NOT NULL,
  attributed_at INTEGER NOT NULL,
  code          TEXT
);
CREATE INDEX IF NOT EXISTS referrals_referrer ON referrals (referrer_id);

CREATE TABLE IF NOT EXISTS referral_ledger (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  entry_key    TEXT    NOT NULL UNIQUE,
  type         TEXT    NOT NULL CHECK (type IN ('accrual', 'clawback', 'payout', 'adjustment')),
  referrer_id  TEXT    NOT NULL,
  referee_id   TEXT,
  earning_id   TEXT,
  level        INTEGER,
  amount       INTEGER NOT NULL,
  base_amount  INTEGER NOT NULL DEFAULT 0,
  uncollected  INTEGER NOT NULL DEFAULT 0,
  occurred_at  INTEGER NOT NULL,
  available_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS referral_ledger_referrer ON referral_ledger (referrer_id, id);
CREATE INDEX IF NOT EXISTS referral_ledger_earning ON referral_ledger (earning_id);
CREATE INDEX IF NOT EXISTS referral_ledger_referee ON referral_ledger (referee_id);

CREATE TRIGGER IF NOT EXISTS referral_ledger_no_update BEFORE UPDATE ON referral_ledger
BEGIN
  SELECT RAISE(ABORT, 'referral_ledger is append-only');
END;

CREATE TRIGGER IF NOT EXISTS referral_ledger_no_delete BEFORE DELETE ON referral_ledger
BEGIN
  SELECT RAISE(ABORT, 'referral_ledger is append-only');
END;

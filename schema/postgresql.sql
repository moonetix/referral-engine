-- referral-engine reference schema for PostgreSQL 12 or later.
-- Instants are Unix seconds. Amounts are integers in your smallest unit.

CREATE TABLE IF NOT EXISTS referral_codes (
  user_id    VARCHAR(191) NOT NULL PRIMARY KEY,
  code       VARCHAR(64)  NOT NULL UNIQUE,
  created_at BIGINT       NOT NULL
);

CREATE TABLE IF NOT EXISTS referrals (
  referee_id    VARCHAR(191) NOT NULL PRIMARY KEY,
  referrer_id   VARCHAR(191) NOT NULL,
  attributed_at BIGINT       NOT NULL,
  code          VARCHAR(64)
);
CREATE INDEX IF NOT EXISTS referrals_referrer ON referrals (referrer_id);

CREATE TABLE IF NOT EXISTS referral_ledger (
  id           BIGSERIAL    PRIMARY KEY,
  entry_key    VARCHAR(500) NOT NULL UNIQUE,
  type         VARCHAR(16)  NOT NULL CHECK (type IN ('accrual', 'clawback', 'payout', 'adjustment')),
  referrer_id  VARCHAR(191) NOT NULL,
  referee_id   VARCHAR(191),
  earning_id   VARCHAR(191),
  level        SMALLINT,
  amount       BIGINT       NOT NULL,
  base_amount  BIGINT       NOT NULL DEFAULT 0,
  uncollected  BIGINT       NOT NULL DEFAULT 0,
  occurred_at  BIGINT       NOT NULL,
  available_at BIGINT       NOT NULL
);
CREATE INDEX IF NOT EXISTS referral_ledger_referrer ON referral_ledger (referrer_id, id);
CREATE INDEX IF NOT EXISTS referral_ledger_earning ON referral_ledger (earning_id);
CREATE INDEX IF NOT EXISTS referral_ledger_referee ON referral_ledger (referee_id);

CREATE OR REPLACE FUNCTION referral_ledger_append_only() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'referral_ledger is append-only';
END;
$$;

DROP TRIGGER IF EXISTS referral_ledger_append_only ON referral_ledger;
CREATE TRIGGER referral_ledger_append_only
  BEFORE UPDATE OR DELETE ON referral_ledger
  FOR EACH ROW EXECUTE FUNCTION referral_ledger_append_only();

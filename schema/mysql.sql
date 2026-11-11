-- referral-engine reference schema for MySQL 8.0 or later (InnoDB, utf8mb4).
-- Instants are Unix seconds. Amounts are integers in your smallest unit.
-- utf8mb4_bin keeps IDs and codes case-sensitive, as the library compares them.

CREATE TABLE IF NOT EXISTS referral_codes (
  user_id    VARCHAR(191) NOT NULL,
  code       VARCHAR(64)  NOT NULL,
  created_at BIGINT       NOT NULL,
  PRIMARY KEY (user_id),
  UNIQUE KEY referral_codes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS referrals (
  referee_id    VARCHAR(191) NOT NULL,
  referrer_id   VARCHAR(191) NOT NULL,
  attributed_at BIGINT       NOT NULL,
  code          VARCHAR(64)  NULL,
  PRIMARY KEY (referee_id),
  KEY referrals_referrer (referrer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS referral_ledger (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_key    VARCHAR(500)    NOT NULL,
  type         ENUM('accrual', 'clawback', 'payout', 'adjustment') NOT NULL,
  referrer_id  VARCHAR(191)    NOT NULL,
  referee_id   VARCHAR(191)    NULL,
  earning_id   VARCHAR(191)    NULL,
  level        SMALLINT        NULL,
  amount       BIGINT          NOT NULL,
  base_amount  BIGINT          NOT NULL DEFAULT 0,
  uncollected  BIGINT          NOT NULL DEFAULT 0,
  occurred_at  BIGINT          NOT NULL,
  available_at BIGINT          NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY referral_ledger_key (entry_key),
  KEY referral_ledger_referrer (referrer_id, id),
  KEY referral_ledger_earning (earning_id),
  KEY referral_ledger_referee (referee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TRIGGER referral_ledger_no_update BEFORE UPDATE ON referral_ledger
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'referral_ledger is append-only';

CREATE TRIGGER referral_ledger_no_delete BEFORE DELETE ON referral_ledger
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'referral_ledger is append-only';

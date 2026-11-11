# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-11

### Added

- JavaScript (ESM) and PHP 8.1+ implementations with the same API, results and error codes.
- Referral codes: `generateCode` with a 29-symbol alphabet and rejection sampling, an optional check character that catches every single-character error and neighbor swap, `normalizeCode`, `isValidCode`, `formatCode` and `createUniqueCode`.
- Attribution: `attribute` with first or last touch, an attribution window, a grace period for typed codes, and guard checks `selfReferral`, `sameDevice`, `sameIp` (IPv4 and IPv6 prefixes) and `samePayoutDestination`, plus custom checks.
- Commission plans: flat (first or every qualifying earning), percentage and tiered by qualified referees or confirmed volume, with lifetime or time-limited duration and caps per earning, per referee and per referrer per period.
- `ReferralEngine` on an append-only ledger: idempotent accruals per earning, holds and release dates, proportional clawbacks that never exceed the commission, an optional floor at a zero balance with the remainder reported as uncollected, payouts and adjustments.
- Multi-level commissions as an explicit opt-in that requires `acknowledgeRegulatoryRisk: true`.
- `fraudSignals` and `findRings`: a score with readable reasons for shared devices, networks and payout destinations, sign-up bursts, earn-then-vanish accounts, reversal rate and referral loops.
- Stores: `MemoryStore` (both), `SqliteStore` for `node:sqlite` and better-sqlite3, `PdoStore` for PHP.
- Reference schemas for PostgreSQL, MySQL and SQLite with append-only triggers.
- 152 shared test vectors in `test-vectors/vectors.json`, run by both suites, with the ledger scenarios also run on SQLite.

[1.0.0]: https://github.com/moonetix/referral-engine/releases/tag/v1.0.0

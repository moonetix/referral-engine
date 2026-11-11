<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/**
 * Persistence used by ReferralEngine. Referrals are keyed by referee ID. Ledger entries are
 * append-only and keyed by `key`; appendEntries() writes all entries or none.
 */
interface Store
{
    /** @return array{refereeId: string, referrerId: string, attributedAt: int, code: ?string}|null */
    public function getReferral(string $refereeId): ?array;

    /** Returns false when the referee already has a referral. */
    public function insertReferral(array $referral): bool;

    /** @param array{referrerId?: string, refereeId?: string, earningId?: string} $filter @return list<array> in insertion order */
    public function listEntries(array $filter = []): array;

    /** Returns false, writing nothing, when any entry key already exists. */
    public function appendEntries(array $entries): bool;

    /** Runs $fn so that no other write for the same key interleaves (a transaction or a lock). */
    public function withLock(string $key, callable $fn): mixed;
}

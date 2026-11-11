<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/** In-memory store for tests and prototypes. State is lost when the request ends. */
final class MemoryStore implements Store
{
    /** @var array<string, array> */
    private array $referrals = [];
    /** @var list<array> */
    private array $entries = [];
    /** @var array<string, true> */
    private array $keys = [];

    public function getReferral(string $refereeId): ?array
    {
        return $this->referrals['k' . $refereeId] ?? null;
    }

    public function insertReferral(array $referral): bool
    {
        $k = 'k' . $referral['refereeId'];
        if (isset($this->referrals[$k])) {
            return false;
        }
        $this->referrals[$k] = $referral;
        return true;
    }

    public function listEntries(array $filter = []): array
    {
        $out = [];
        foreach ($this->entries as $e) {
            foreach (['referrerId', 'refereeId', 'earningId'] as $f) {
                if (isset($filter[$f]) && $e[$f] !== (string)$filter[$f]) {
                    continue 2;
                }
            }
            $out[] = $e;
        }
        return $out;
    }

    public function appendEntries(array $entries): bool
    {
        $keys = array_column($entries, 'key');
        if (count(array_unique($keys)) !== count($keys)) {
            return false;
        }
        foreach ($keys as $k) {
            if (isset($this->keys[$k])) {
                return false;
            }
        }
        foreach ($entries as $e) {
            $this->keys[$e['key']] = true;
            $this->entries[] = $e;
        }
        return true;
    }

    public function withLock(string $key, callable $fn): mixed
    {
        return $fn();
    }
}

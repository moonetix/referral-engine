<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/** Commission accrual on an append-only ledger. Same behavior as the JavaScript ReferralEngine. */
final class ReferralEngine
{
    public array $plan;
    private Store $store;
    private int $hold;
    private bool $allowNegative;
    /** @var list<int> */
    private array $levels = [];
    /** @var callable|null */
    private $availableHook;

    public function __construct(array $options)
    {
        $store = $options['store'] ?? null;
        if (!$store instanceof Store) {
            Util::fail('invalid_argument', 'store must implement ' . Store::class);
        }
        $this->store = $store;
        $this->plan = Plan::asPlan($options['plan'] ?? null);
        $this->hold = Util::int($options['holdSeconds'] ?? 0, 'holdSeconds');
        $allow = $options['allowNegativeBalance'] ?? true;
        if (!is_bool($allow)) {
            Util::fail('invalid_argument', 'allowNegativeBalance must be a boolean');
        }
        $this->allowNegative = $allow;
        $ml = $options['multiLevel'] ?? null;
        if ($ml !== null) {
            if (($ml['acknowledgeRegulatoryRisk'] ?? false) !== true) {
                Util::fail('multi_level_not_acknowledged', 'multi-level commissions are regulated in many places; set multiLevel.acknowledgeRegulatoryRisk to true after legal review');
            }
            $levels = $ml['levels'] ?? null;
            if (!is_array($levels) || !array_is_list($levels) || count($levels) < 1 || count($levels) > 4) {
                Util::fail('invalid_argument', 'multiLevel.levels must list 1 to 4 rates in basis points');
            }
            foreach ($levels as $i => $bps) {
                $this->levels[] = Util::int($bps, "multiLevel.levels[{$i}]", 0, Util::BPS);
            }
        }
        $hook = $options['availableBalance'] ?? null;
        if ($hook !== null && !is_callable($hook)) {
            Util::fail('invalid_argument', 'availableBalance must be callable');
        }
        $this->availableHook = $hook;
    }

    private static function entry(array $fields): array
    {
        $e = $fields + ['refereeId' => null, 'earningId' => null, 'level' => null, 'baseAmount' => 0, 'uncollected' => 0];
        $e['availableAt'] = $fields['availableAt'] ?? $fields['at'];
        return [
            'key' => $e['key'], 'type' => $e['type'], 'referrerId' => $e['referrerId'], 'refereeId' => $e['refereeId'],
            'earningId' => $e['earningId'], 'level' => $e['level'], 'amount' => $e['amount'], 'baseAmount' => $e['baseAmount'],
            'uncollected' => $e['uncollected'], 'at' => $e['at'], 'availableAt' => $e['availableAt'],
        ];
    }

    private static function accrualKey(string $earningId, int $level): string
    {
        return "accrual:{$level}:{$earningId}";
    }

    private static function clawbackKey(string $earningId, int $level, string $reversalId): string
    {
        return "clawback:{$level}:" . strlen($earningId) . ":{$earningId}:{$reversalId}";
    }

    /** @return array<string, array{entry: array, taken: int, uncollected: int, reversedBase: int}> */
    private static function accrualStates(array $entries): array
    {
        $states = [];
        foreach ($entries as $e) {
            if ($e['type'] === 'accrual') {
                $states[$e['level'] . ':' . $e['earningId']] = ['entry' => $e, 'taken' => 0, 'uncollected' => 0, 'reversedBase' => 0];
            }
        }
        foreach ($entries as $e) {
            $k = $e['level'] . ':' . $e['earningId'];
            if ($e['type'] !== 'clawback' || !isset($states[$k])) {
                continue;
            }
            $states[$k]['taken'] += -$e['amount'];
            $states[$k]['uncollected'] += $e['uncollected'];
            $states[$k]['reversedBase'] += -$e['baseAmount'];
        }
        return $states;
    }

    private static function commissionView(array $e): array
    {
        return ['level' => $e['level'], 'referrerId' => $e['referrerId'], 'amount' => $e['amount'], 'availableAt' => $e['availableAt']];
    }

    private function locked(string $key, callable $fn): mixed
    {
        return $this->store->withLock($key, $fn);
    }

    public function attach(array $input): array
    {
        $referee = Util::id($input['refereeId'] ?? null, 'refereeId');
        $referrer = Util::id($input['referrerId'] ?? null, 'referrerId');
        $at = Util::int($input['at'] ?? null, 'at');
        $code = $input['code'] ?? null;
        if ($code !== null && !is_string($code)) {
            Util::fail('invalid_argument', 'code must be a string');
        }
        if ($referee === $referrer) {
            Util::fail('self_referral', 'a user cannot refer themselves');
        }
        return $this->locked("referee:{$referee}", function () use ($referee, $referrer, $at, $code): array {
            $current = $referrer;
            for ($i = 0; $i < 1000; $i++) {
                $up = $this->store->getReferral($current);
                if ($up === null) {
                    break;
                }
                if ($up['referrerId'] === $referee) {
                    Util::fail('referral_cycle', "{$referee} is already upstream of {$referrer}");
                }
                $current = $up['referrerId'];
            }
            $referral = ['refereeId' => $referee, 'referrerId' => $referrer, 'attributedAt' => $at, 'code' => $code];
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $existing = $this->store->getReferral($referee);
                if ($existing !== null) {
                    if ($existing['referrerId'] !== $referrer) {
                        Util::fail('already_referred', "{$referee} was already referred by {$existing['referrerId']}");
                    }
                    return ['status' => 'existing', 'referral' => $existing];
                }
                if ($this->store->insertReferral($referral)) {
                    return ['status' => 'attached', 'referral' => $referral];
                }
            }
            Util::fail('already_referred', "{$referee} was referred concurrently");
        });
    }

    public function recordEarning(array $input): array
    {
        $earning = Util::id($input['earningId'] ?? null, 'earningId');
        $referee = Util::id($input['refereeId'] ?? null, 'refereeId');
        $amount = Util::int($input['amount'] ?? null, 'amount');
        $at = Util::int($input['at'] ?? null, 'at');
        return $this->locked("earning:{$earning}", function () use ($earning, $referee, $amount, $at): array {
            $dup = $this->duplicateAccrual($earning);
            if ($dup !== null) {
                return $dup;
            }
            $none = static fn(string $reason): array => ['status' => 'none', 'reason' => $reason, 'capped' => [], 'commissions' => []];
            $referral = $this->store->getReferral($referee);
            if ($referral === null) {
                return $none('no_referrer');
            }
            if ($at < $referral['attributedAt']) {
                return $none('before_referral');
            }
            if ($this->plan['durationSeconds'] !== null && $at >= $referral['attributedAt'] + $this->plan['durationSeconds']) {
                return $none('expired');
            }
            $referrer = $referral['referrerId'];
            return $this->locked("referrer:{$referrer}", fn(): array => $this->accrue($earning, $referee, $amount, $at, $referrer));
        });
    }

    private function accrue(string $earning, string $referee, int $amount, int $at, string $referrer): array
    {
        $states = self::accrualStates($this->store->listEntries(['referrerId' => $referrer]));
        $qualified = [];
        $volume = 0;
        $refereeNet = 0;
        $paidBefore = false;
        $periodNet = 0;
        $cap = $this->plan['perReferrerCap'];
        foreach ($states as $s) {
            $e = $s['entry'];
            $net = $e['amount'] - $s['taken'];
            if ($cap !== null && intdiv($e['at'], $cap['periodSeconds']) === intdiv($at, $cap['periodSeconds'])) {
                $periodNet += $net;
            }
            if ($e['level'] !== 1) {
                continue;
            }
            $base = $e['baseAmount'] - $s['reversedBase'];
            $volume += $base;
            if ($base > 0) {
                $qualified['k' . $e['refereeId']] = true;
            }
            if ($e['refereeId'] === $referee) {
                $refereeNet += $net;
                if ($net > 0) {
                    $paidBefore = true;
                }
            }
        }
        $c = Plan::commissionFor($this->plan, $amount, ['referees' => count($qualified), 'volume' => min($volume, Util::MAX_AMOUNT), 'paidBefore' => $paidBefore]);
        if ($c['reason'] === 'below_minimum') {
            return ['status' => 'none', 'reason' => 'below_minimum', 'capped' => [], 'commissions' => []];
        }

        $value = $c['amount'];
        $capped = [];
        $limit = static function (int $room, string $code) use (&$value, &$capped): void {
            if ($value > $room) {
                $value = max(0, $room);
                $capped[] = $code;
            }
        };
        if ($this->plan['perEarningCap'] !== null) {
            $limit($this->plan['perEarningCap'], 'per_earning_cap');
        }
        if ($this->plan['perRefereeCap'] !== null) {
            $limit($this->plan['perRefereeCap'] - $refereeNet, 'per_referee_cap');
        }
        if ($cap !== null) {
            $limit($cap['amount'] - $periodNet, 'per_referrer_cap');
        }

        if ($at + $this->hold > Util::MAX_AMOUNT) {
            Util::fail('overflow', 'release time exceeds 2^53 - 1');
        }
        $availableAt = $at + $this->hold;
        $entries = [self::entry(['key' => self::accrualKey($earning, 1), 'type' => 'accrual', 'referrerId' => $referrer, 'refereeId' => $referee, 'earningId' => $earning, 'level' => 1, 'amount' => $value, 'baseAmount' => $amount, 'at' => $at, 'availableAt' => $availableAt])];
        $seen = ['k' . $referee => true, 'k' . $referrer => true];
        $current = $referrer;
        foreach ($this->levels as $i => $bps) {
            $up = $this->store->getReferral($current);
            if ($up === null || isset($seen['k' . $up['referrerId']])) {
                break;
            }
            $seen['k' . $up['referrerId']] = true;
            $entries[] = self::entry(['key' => self::accrualKey($earning, $i + 2), 'type' => 'accrual', 'referrerId' => $up['referrerId'], 'refereeId' => $referee, 'earningId' => $earning, 'level' => $i + 2, 'amount' => Util::mulDiv($amount, $bps, Util::BPS), 'baseAmount' => $amount, 'at' => $at, 'availableAt' => $availableAt]);
            $current = $up['referrerId'];
        }
        if (!$this->store->appendEntries($entries)) {
            return $this->duplicateAccrual($earning) ?? Util::fail('conflict', 'ledger rejected the entries');
        }
        return ['status' => 'accrued', 'reason' => $c['reason'], 'capped' => $capped, 'commissions' => array_map([self::class, 'commissionView'], $entries)];
    }

    private function duplicateAccrual(string $earningId): ?array
    {
        $accruals = array_values(array_filter($this->store->listEntries(['earningId' => $earningId]), static fn(array $e): bool => $e['type'] === 'accrual'));
        if ($accruals === []) {
            return null;
        }
        usort($accruals, static fn(array $a, array $b): int => $a['level'] <=> $b['level']);
        return ['status' => 'duplicate', 'reason' => null, 'capped' => [], 'commissions' => array_map([self::class, 'commissionView'], $accruals)];
    }

    public function reverseEarning(array $input): array
    {
        $earning = Util::id($input['earningId'] ?? null, 'earningId');
        $reversal = ($input['reversalId'] ?? null) === null ? $earning : Util::id($input['reversalId'], 'reversalId');
        $amount = Util::optInt($input['amount'] ?? null, 'amount', 1);
        $at = Util::int($input['at'] ?? null, 'at');
        return $this->locked("earning:{$earning}", function () use ($earning, $reversal, $amount, $at): array {
            $entries = $this->store->listEntries(['earningId' => $earning]);
            $accruals = array_values(array_filter($entries, static fn(array $e): bool => $e['type'] === 'accrual'));
            usort($accruals, static fn(array $a, array $b): int => $a['level'] <=> $b['level']);
            if ($accruals === []) {
                return ['status' => 'none', 'reason' => 'unknown_earning', 'clawbacks' => []];
            }
            $keys = [];
            foreach ($accruals as $a) {
                $keys[self::clawbackKey($earning, $a['level'], $reversal)] = true;
            }
            $prior = array_values(array_filter($entries, static fn(array $e): bool => isset($keys[$e['key']])));
            $view = static fn(array $e): array => ['level' => $e['level'], 'referrerId' => $e['referrerId'], 'amount' => -$e['amount'], 'uncollected' => $e['uncollected']];
            if ($prior !== []) {
                return ['status' => 'duplicate', 'reason' => null, 'clawbacks' => array_map($view, $prior)];
            }

            $states = self::accrualStates($entries);
            $base = $accruals[0]['baseAmount'];
            $reversedSoFar = $states["1:{$earning}"]['reversedBase'] ?? 0;
            $remaining = $base - $reversedSoFar;
            if ($remaining <= 0) {
                return ['status' => 'none', 'reason' => 'already_reversed', 'clawbacks' => []];
            }
            $part = $amount === null ? $remaining : min($amount, $remaining);
            $cumulative = $reversedSoFar + $part;

            $out = [];
            foreach ($accruals as $a) {
                $s = $states["{$a['level']}:{$earning}"];
                $due = max(0, Util::mulDiv($a['amount'], $cumulative, $base) - $s['taken'] - $s['uncollected']);
                $taken = $due;
                if (!$this->allowNegative && $a['availableAt'] <= $at && $due > 0) {
                    $taken = min($due, max(0, $this->available($a['referrerId'], $at)));
                }
                $out[] = self::entry(['key' => self::clawbackKey($earning, $a['level'], $reversal), 'type' => 'clawback', 'referrerId' => $a['referrerId'], 'refereeId' => $a['refereeId'], 'earningId' => $earning, 'level' => $a['level'], 'amount' => -$taken, 'baseAmount' => -$part, 'uncollected' => $due - $taken, 'at' => $at]);
            }
            if (!$this->store->appendEntries($out)) {
                Util::fail('conflict', 'ledger rejected the clawback; retry the reversal');
            }
            return [
                'status' => 'reversed',
                'reason' => $amount !== null && $amount > $remaining ? 'capped_at_remaining' : null,
                'clawbacks' => array_map($view, $out),
            ];
        });
    }

    private function available(string $referrerId, int $at): int
    {
        if ($this->availableHook !== null) {
            return Util::int(($this->availableHook)($referrerId, $at), 'availableBalance()', -Util::MAX_AMOUNT);
        }
        return $this->balance($referrerId, $at)['available'];
    }

    public function recordPayout(array $input): array
    {
        $payout = Util::id($input['payoutId'] ?? null, 'payoutId');
        $referrer = Util::id($input['referrerId'] ?? null, 'referrerId');
        $amount = Util::int($input['amount'] ?? null, 'amount', 1);
        $at = Util::int($input['at'] ?? null, 'at');
        $e = self::entry(['key' => "payout:{$payout}", 'type' => 'payout', 'referrerId' => $referrer, 'amount' => -$amount, 'at' => $at]);
        return $this->single($referrer, $e, function () use ($referrer, $amount, $at): void {
            $available = $this->payable($referrer, $at);
            if ($amount > $available) {
                Util::fail('insufficient_balance', "available balance is {$available}, payout is {$amount}");
            }
        });
    }

    /**
     * What a payout dated $at may take: commission released by then, less every clawback, payout
     * and negative adjustment already in the ledger, even one that carries a later timestamp.
     */
    private function payable(string $referrer, int $at): int
    {
        $entries = $this->store->listEntries(['referrerId' => $referrer]);
        $payable = 0;
        foreach (self::accrualStates($entries) as $s) {
            if ($s['entry']['availableAt'] <= $at) {
                $payable += $s['entry']['amount'] - $s['taken'];
            }
        }
        foreach ($entries as $e) {
            if (($e['type'] === 'payout' || $e['type'] === 'adjustment') && ($e['amount'] < 0 || $e['at'] <= $at)) {
                $payable += $e['amount'];
            }
        }
        return $payable;
    }

    public function recordAdjustment(array $input): array
    {
        $adjustment = Util::id($input['adjustmentId'] ?? null, 'adjustmentId');
        $referrer = Util::id($input['referrerId'] ?? null, 'referrerId');
        $amount = Util::int($input['amount'] ?? null, 'amount', -Util::MAX_AMOUNT);
        if ($amount === 0) {
            Util::fail('invalid_argument', 'amount must not be 0');
        }
        $at = Util::int($input['at'] ?? null, 'at');
        return $this->single($referrer, self::entry(['key' => "adjustment:{$adjustment}", 'type' => 'adjustment', 'referrerId' => $referrer, 'amount' => $amount, 'at' => $at]), null);
    }

    private function single(string $referrer, array $e, ?callable $guard): array
    {
        return $this->locked("referrer:{$referrer}", function () use ($referrer, $e, $guard): array {
            foreach ($this->store->listEntries(['referrerId' => $referrer]) as $x) {
                if ($x['key'] === $e['key']) {
                    return ['status' => 'duplicate', 'entry' => $x];
                }
            }
            if ($guard !== null) {
                $guard();
            }
            if (!$this->store->appendEntries([$e])) {
                Util::fail('conflict', "ledger already has {$e['key']}");
            }
            return ['status' => 'recorded', 'entry' => $e];
        });
    }

    /** @return array{pending: int, available: int, total: int, uncollected: int} */
    public function balance(mixed $referrerId, mixed $at): array
    {
        $referrer = Util::id($referrerId, 'referrerId');
        $at = Util::int($at, 'at');
        $entries = array_values(array_filter($this->store->listEntries(['referrerId' => $referrer]), static fn(array $e): bool => $e['at'] <= $at));
        $pending = 0;
        $available = 0;
        $uncollected = 0;
        foreach (self::accrualStates($entries) as $s) {
            $net = $s['entry']['amount'] - $s['taken'];
            if ($s['entry']['availableAt'] > $at) {
                $pending += $net;
            } else {
                $available += $net;
            }
        }
        foreach ($entries as $e) {
            if ($e['type'] === 'payout' || $e['type'] === 'adjustment') {
                $available += $e['amount'];
            }
            if ($e['type'] === 'clawback') {
                $uncollected += $e['uncollected'];
            }
        }
        return ['pending' => $pending, 'available' => $available, 'total' => $pending + $available, 'uncollected' => $uncollected];
    }

    /** @return list<array> */
    public function ledger(mixed $referrerId): array
    {
        return $this->store->listEntries(['referrerId' => Util::id($referrerId, 'referrerId')]);
    }
}

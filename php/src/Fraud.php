<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/** @internal Use the Referral facade. */
final class Fraud
{
    public const DEFAULT_WEIGHTS = [
        'sharedPayoutWithReferrer' => 40,
        'sharedDeviceWithReferrer' => 30,
        'sharedNetworkWithReferrer' => 10,
        'sharedPayout' => 30,
        'sharedDevice' => 20,
        'sharedNetwork' => 10,
        'signupBurst' => 15,
        'earnThenVanish' => 15,
        'highReversalRate' => 20,
        'referralRing' => 40,
    ];

    /** @return list<list<string>> */
    public static function findRings(mixed $edges): array
    {
        if (!is_array($edges) || !array_is_list($edges)) {
            Util::fail('invalid_argument', 'edges must be an array of [referrerId, refereeId] pairs');
        }
        // Keys are prefixed so numeric IDs never turn into integer array keys.
        $order = [];
        $out = [];
        $in = [];
        $self = [];
        $name = [];
        $node = static function (string $v) use (&$order, &$out, &$in, &$name): string {
            $k = 'k' . $v;
            if (!isset($order[$k])) {
                $order[$k] = count($order);
                $out[$k] = [];
                $in[$k] = [];
                $name[$k] = $v;
            }
            return $k;
        };
        foreach ($edges as $i => $e) {
            if (!is_array($e) || !array_is_list($e) || count($e) !== 2) {
                Util::fail('invalid_argument', "edges[{$i}] must be a [referrerId, refereeId] pair");
            }
            $a = $node(Util::id($e[0], "edges[{$i}][0]"));
            $b = $node(Util::id($e[1], "edges[{$i}][1]"));
            $out[$a][] = $b;
            $in[$b][] = $a;
            if ($a === $b) {
                $self[$a] = true;
            }
        }
        $finished = [];
        $visited = [];
        foreach (array_keys($order) as $start) {
            if (isset($visited[$start])) {
                continue;
            }
            $visited[$start] = true;
            $stack = [[$start, 0]];
            while ($stack !== []) {
                $top = count($stack) - 1;
                [$v, $pos] = $stack[$top];
                $stack[$top][1]++;
                $next = $out[$v][$pos] ?? null;
                if ($next === null) {
                    $finished[] = $v;
                    array_pop($stack);
                } elseif (!isset($visited[$next])) {
                    $visited[$next] = true;
                    $stack[] = [$next, 0];
                }
            }
        }
        $assigned = [];
        $rings = [];
        for ($k = count($finished) - 1; $k >= 0; $k--) {
            $root = $finished[$k];
            if (isset($assigned[$root])) {
                continue;
            }
            $members = [];
            $stack = [$root];
            $assigned[$root] = true;
            while ($stack !== []) {
                $v = array_pop($stack);
                $members[] = $v;
                foreach ($in[$v] as $u) {
                    if (!isset($assigned[$u])) {
                        $assigned[$u] = true;
                        $stack[] = $u;
                    }
                }
            }
            if (count($members) > 1 || isset($self[$root])) {
                usort($members, static fn(string $x, string $y): int => $order[$x] <=> $order[$y]);
                $rings[] = $members;
            }
        }
        usort($rings, static fn(array $x, array $y): int => $order[$x[0]] <=> $order[$y[0]]);
        return array_map(static fn(array $ring): array => array_map(static fn(string $k): string => $name[$k], $ring), $rings);
    }

    private static function account(mixed $r, int $i): array
    {
        if (!is_array($r)) {
            Util::fail('invalid_argument', "referees[{$i}] must be an object");
        }
        return [
            'id' => Util::id($r['id'] ?? null, "referees[{$i}].id"),
            'deviceIds' => Util::strList($r['deviceIds'] ?? null, "referees[{$i}].deviceIds"),
            'ips' => Util::strList($r['ips'] ?? null, "referees[{$i}].ips"),
            'payoutDestinations' => Util::strList($r['payoutDestinations'] ?? null, "referees[{$i}].payoutDestinations"),
            'signedUpAt' => Util::int($r['signedUpAt'] ?? null, "referees[{$i}].signedUpAt"),
            'firstEarningAt' => Util::optInt($r['firstEarningAt'] ?? null, "referees[{$i}].firstEarningAt"),
            'lastActiveAt' => Util::optInt($r['lastActiveAt'] ?? null, "referees[{$i}].lastActiveAt"),
            'earned' => Util::int($r['earned'] ?? 0, "referees[{$i}].earned"),
            'reversed' => Util::int($r['reversed'] ?? 0, "referees[{$i}].reversed"),
        ];
    }

    /** @param list<array> $list @return array{values: list<string>, ids: list<string>} */
    private static function clusters(array $list, callable $valuesOf, int $minSize): array
    {
        $byValue = [];
        foreach ($list as $i => $r) {
            foreach (array_unique($valuesOf($r)) as $v) {
                $byValue['k' . $v][] = $i;
            }
        }
        $values = [];
        $members = [];
        foreach ($byValue as $k => $m) {
            if (count($m) >= $minSize) {
                $values[] = substr((string)$k, 1);
                foreach ($m as $i) {
                    $members[$i] = true;
                }
            }
        }
        $ids = [];
        foreach ($list as $i => $r) {
            if (isset($members[$i])) {
                $ids[] = $r['id'];
            }
        }
        return ['values' => $values, 'ids' => $ids];
    }

    public static function fraudSignals(array $input): array
    {
        $now = Util::int($input['now'] ?? null, 'now');
        $referees = $input['referees'] ?? [];
        if (!is_array($referees) || !array_is_list($referees)) {
            Util::fail('invalid_argument', 'referees must be an array');
        }
        $options = $input['options'] ?? [];
        $opt = static fn(string $k, int $def, int $min, int $max = Util::MAX_AMOUNT): int => Util::int($options[$k] ?? $def, $k, $min, $max);
        $o = [
            'ipv4Prefix' => $opt('ipv4Prefix', 24, 0, 32),
            'ipv6Prefix' => $opt('ipv6Prefix', 64, 0, 128),
            'networkClusterSize' => $opt('networkClusterSize', 3, 2),
            'burstSize' => $opt('burstSize', 5, 2),
            'burstWindowSeconds' => $opt('burstWindowSeconds', 3600, 1),
            'vanishMinCount' => $opt('vanishMinCount', 2, 1),
            'vanishEarnWithinSeconds' => $opt('vanishEarnWithinSeconds', 259200, 0),
            'vanishInactiveSeconds' => $opt('vanishInactiveSeconds', 1209600, 1),
            'reversalRateBps' => $opt('reversalRateBps', 3000, 1, Util::BPS),
            'reversalMinEarned' => $opt('reversalMinEarned', 1, 1),
            'reviewAt' => $opt('reviewAt', 30, 1),
            'highAt' => $opt('highAt', 60, 1),
        ];
        $weights = self::DEFAULT_WEIGHTS;
        foreach ($options['weights'] ?? [] as $k => $v) {
            if (!array_key_exists($k, self::DEFAULT_WEIGHTS)) {
                Util::fail('invalid_argument', "unknown weight \"{$k}\"");
            }
            $weights[$k] = Util::int($v, "weights.{$k}", 0, 100);
        }
        $referrer = $input['referrer'] ?? [];
        $ref = [
            'id' => isset($referrer['id']) ? Util::id($referrer['id'], 'referrer.id') : null,
            'deviceIds' => Util::strList($referrer['deviceIds'] ?? null, 'referrer.deviceIds'),
            'ips' => Util::strList($referrer['ips'] ?? null, 'referrer.ips'),
            'payoutDestinations' => Util::strList($referrer['payoutDestinations'] ?? null, 'referrer.payoutDestinations'),
        ];
        $list = [];
        foreach ($referees as $i => $r) {
            $list[] = self::account($r, $i);
        }
        $prefixes = ['ipv4Prefix' => $o['ipv4Prefix'], 'ipv6Prefix' => $o['ipv6Prefix']];
        $net = static fn(array $ips): array => array_map(static fn(string $ip): string => Ip::ipPrefix($ip, $prefixes), $ips);
        $accounts = static fn(int $n): string => Util::plural($n, 'referred account', 'referred accounts');
        $signals = [];
        $add = static function (string $code, string $weightKey, string $message, array $ids) use (&$signals, $weights): void {
            $signals[] = ['code' => $code, 'weight' => $weights[$weightKey], 'message' => $message, 'accountIds' => $ids];
        };
        $withReferrer = static function (callable $valuesOf, array $refValues) use ($list): array {
            $set = array_flip($refValues);
            $ids = [];
            foreach ($list as $r) {
                foreach ($valuesOf($r) as $v) {
                    if (isset($set[$v])) {
                        $ids[] = $r['id'];
                        break;
                    }
                }
            }
            return $ids;
        };

        $ids = $withReferrer(static fn(array $r): array => $r['payoutDestinations'], $ref['payoutDestinations']);
        if ($ids !== []) {
            $n = count($ids);
            $add('shared_payout_with_referrer', 'sharedPayoutWithReferrer', $accounts($n) . ($n === 1 ? ' cashes' : ' cash') . ' out to a payout destination the referrer also uses.', $ids);
        }
        $ids = $withReferrer(static fn(array $r): array => $r['deviceIds'], $ref['deviceIds']);
        if ($ids !== []) {
            $add('shared_device_with_referrer', 'sharedDeviceWithReferrer', $accounts(count($ids)) . ' used a device the referrer also uses.', $ids);
        }
        $refNets = array_values(array_unique($net($ref['ips'])));
        $ids = $withReferrer(static fn(array $r): array => $net($r['ips']), $refNets);
        if ($ids !== []) {
            $used = [];
            foreach ($list as $r) {
                foreach ($net($r['ips']) as $n) {
                    $used[$n] = true;
                }
            }
            $hit = array_values(array_filter($refNets, static fn(string $n): bool => isset($used[$n])));
            $add('shared_network_with_referrer', 'sharedNetworkWithReferrer', $accounts(count($ids)) . " used the referrer's network (" . implode(', ', $hit) . ').', $ids);
        }

        $c = self::clusters($list, static fn(array $r): array => $r['payoutDestinations'], 2);
        if ($c['ids'] !== []) {
            $add('shared_payout_among_referees', 'sharedPayout', $accounts(count($c['ids'])) . ' share ' . Util::plural(count($c['values']), 'payout destination', 'payout destinations') . ' with each other.', $c['ids']);
        }
        $c = self::clusters($list, static fn(array $r): array => $r['deviceIds'], 2);
        if ($c['ids'] !== []) {
            $add('shared_device_among_referees', 'sharedDevice', $accounts(count($c['ids'])) . ' share ' . Util::plural(count($c['values']), 'device', 'devices') . ' with each other.', $c['ids']);
        }
        $c = self::clusters($list, static fn(array $r): array => $net($r['ips']), $o['networkClusterSize']);
        if ($c['ids'] !== []) {
            $add('shared_network_among_referees', 'sharedNetwork', $accounts(count($c['ids'])) . ' came from ' . Util::plural(count($c['values']), 'shared network', 'shared networks') . ': ' . implode(', ', $c['values']) . '.', $c['ids']);
        }

        $byTime = [];
        foreach ($list as $i => $r) {
            $byTime[] = ['t' => $r['signedUpAt'], 'i' => $i];
        }
        usort($byTime, static fn(array $a, array $b): int => $a['t'] <=> $b['t'] ?: $a['i'] <=> $b['i']);
        $best = ['size' => 0, 'from' => 0, 'to' => 0];
        for ($lo = 0, $hi = 0; $hi < count($byTime); $hi++) {
            while ($byTime[$hi]['t'] - $byTime[$lo]['t'] >= $o['burstWindowSeconds']) {
                $lo++;
            }
            if ($hi - $lo + 1 > $best['size']) {
                $best = ['size' => $hi - $lo + 1, 'from' => $lo, 'to' => $hi];
            }
        }
        if ($best['size'] >= $o['burstSize']) {
            $in = [];
            foreach (array_slice($byTime, $best['from'], $best['size']) as $x) {
                $in[$x['i']] = true;
            }
            $ids = [];
            foreach ($list as $i => $r) {
                if (isset($in[$i])) {
                    $ids[] = $r['id'];
                }
            }
            $add('signup_burst', 'signupBurst', $accounts(count($ids)) . ' signed up within ' . Util::duration($o['burstWindowSeconds']) . '.', $ids);
        }

        $ids = [];
        foreach ($list as $r) {
            if ($r['earned'] <= 0 || $r['firstEarningAt'] === null || $r['firstEarningAt'] - $r['signedUpAt'] > $o['vanishEarnWithinSeconds']) {
                continue;
            }
            if ($now - ($r['lastActiveAt'] ?? $r['firstEarningAt']) >= $o['vanishInactiveSeconds']) {
                $ids[] = $r['id'];
            }
        }
        if (count($ids) >= $o['vanishMinCount']) {
            $add('earn_then_vanish', 'earnThenVanish', $accounts(count($ids)) . ' earned within ' . Util::duration($o['vanishEarnWithinSeconds'])
                . ' of signing up and ' . (count($ids) === 1 ? 'has' : 'have') . ' been inactive for ' . Util::duration($o['vanishInactiveSeconds']) . ' or more.', $ids);
        }

        $earned = array_sum(array_column($list, 'earned'));
        $reversed = array_sum(array_column($list, 'reversed'));
        if (!is_int($earned) || !is_int($reversed) || $earned > Util::MAX_AMOUNT || $reversed > Util::MAX_AMOUNT) {
            Util::fail('overflow', 'earned or reversed total exceeds 2^53 - 1');
        }
        if ($earned >= $o['reversalMinEarned'] && $earned > 0) {
            $rate = Util::mulDiv($reversed, Util::BPS, $earned);
            if ($rate >= $o['reversalRateBps']) {
                $ids = array_values(array_map(static fn(array $r): string => $r['id'], array_filter($list, static fn(array $r): bool => $r['reversed'] > 0)));
                $add('high_reversal_rate', 'highReversalRate', 'Reversals are ' . Util::percentFromBps($rate) . " of referred earnings ({$reversed} of {$earned}).", $ids);
            }
        }

        $involved = [];
        foreach ($list as $r) {
            $involved['k' . $r['id']] = true;
        }
        if ($ref['id'] !== null) {
            $involved['k' . $ref['id']] = true;
        }
        $rings = array_values(array_filter(self::findRings($input['edges'] ?? []), static function (array $ring) use ($involved): bool {
            foreach ($ring as $m) {
                if (isset($involved['k' . $m])) {
                    return true;
                }
            }
            return false;
        }));
        if ($rings !== []) {
            $text = implode(' ', array_map(static fn(array $ring): string => count($ring) === 1
                ? "Account {$ring[0]} refers itself."
                : 'Accounts ' . Util::joinList($ring) . ' refer each other in a loop.', $rings));
            $all = [];
            foreach ($rings as $ring) {
                foreach ($ring as $m) {
                    $all['k' . $m] = $m;
                }
            }
            $add('referral_ring', 'referralRing', $text, array_values($all));
        }

        $score = min(100, array_sum(array_column($signals, 'weight')));
        $level = $score >= $o['highAt'] ? 'high' : ($score >= $o['reviewAt'] ? 'review' : 'low');
        return ['score' => $score, 'level' => $level, 'signals' => $signals];
    }
}

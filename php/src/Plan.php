<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/** @internal Use the Referral facade. */
final class Plan
{
    private static function obj(mixed $v, string $name): array
    {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            Util::fail('invalid_plan', "{$name} must be an object");
        }
        return $v;
    }

    private static function planInt(mixed $value, string $name, int $min, int $max, mixed $fallback = false): ?int
    {
        if ($value === null) {
            if ($fallback === false) {
                Util::fail('invalid_plan', "{$name} is required");
            }
            return $fallback;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            Util::fail('invalid_plan', "{$name} must be an integer from {$min} to {$max}");
        }
        return $value;
    }

    public static function definePlan(mixed $config): array
    {
        self::obj($config, 'plan');
        $type = $config['type'] ?? null;
        if (!in_array($type, ['percentage', 'flat', 'tiered'], true)) {
            Util::fail('invalid_plan', 'plan.type must be "percentage", "flat" or "tiered"');
        }
        $max = Util::MAX_AMOUNT;
        $plan = ['type' => $type];
        if ($type === 'percentage') {
            $plan['rateBps'] = self::planInt($config['rateBps'] ?? null, 'rateBps', 0, Util::BPS);
        }
        if ($type === 'flat') {
            $plan['amount'] = self::planInt($config['amount'] ?? null, 'amount', 1, $max);
            $trigger = $config['trigger'] ?? null;
            if ($trigger !== null && !in_array($trigger, ['first', 'every'], true)) {
                Util::fail('invalid_plan', 'trigger must be "first" or "every"');
            }
            $plan['trigger'] = $trigger ?? 'first';
        }
        if ($type === 'tiered') {
            $basis = $config['basis'] ?? null;
            if ($basis !== null && !in_array($basis, ['referees', 'volume'], true)) {
                Util::fail('invalid_plan', 'basis must be "referees" or "volume"');
            }
            $plan['basis'] = $basis ?? 'referees';
            $tiers = $config['tiers'] ?? null;
            if (!is_array($tiers) || $tiers === [] || !array_is_list($tiers)) {
                Util::fail('invalid_plan', 'tiers must be a non-empty array');
            }
            $plan['tiers'] = [];
            foreach ($tiers as $i => $t) {
                self::obj($t, "tiers[{$i}]");
                $plan['tiers'][] = [
                    'from' => self::planInt($t['from'] ?? null, "tiers[{$i}].from", 0, $max),
                    'rateBps' => self::planInt($t['rateBps'] ?? null, "tiers[{$i}].rateBps", 0, Util::BPS),
                ];
            }
            if ($plan['tiers'][0]['from'] !== 0) {
                Util::fail('invalid_plan', 'the first tier must start at 0');
            }
            for ($i = 1; $i < count($plan['tiers']); $i++) {
                if ($plan['tiers'][$i]['from'] <= $plan['tiers'][$i - 1]['from']) {
                    Util::fail('invalid_plan', 'tiers must be sorted by "from", without repeats');
                }
            }
        }
        $plan['minEarning'] = self::planInt($config['minEarning'] ?? null, 'minEarning', 1, $max, 1);
        $plan['durationSeconds'] = self::planInt($config['durationSeconds'] ?? null, 'durationSeconds', 1, $max, null);
        $plan['perEarningCap'] = self::planInt($config['perEarningCap'] ?? null, 'perEarningCap', 0, $max, null);
        $plan['perRefereeCap'] = self::planInt($config['perRefereeCap'] ?? null, 'perRefereeCap', 0, $max, null);
        $cap = $config['perReferrerCap'] ?? null;
        if ($cap === null) {
            $plan['perReferrerCap'] = null;
        } else {
            self::obj($cap, 'perReferrerCap');
            $plan['perReferrerCap'] = [
                'amount' => self::planInt($cap['amount'] ?? null, 'perReferrerCap.amount', 0, $max),
                'periodSeconds' => self::planInt($cap['periodSeconds'] ?? null, 'perReferrerCap.periodSeconds', 1, $max),
            ];
        }
        return $plan;
    }

    public static function asPlan(mixed $plan): array
    {
        return is_array($plan) && array_key_exists('minEarning', $plan) && array_key_exists('durationSeconds', $plan) ? $plan : self::definePlan($plan);
    }

    public static function commissionFor(mixed $planInput, mixed $amount, array $stats = []): array
    {
        $plan = self::asPlan($planInput);
        Util::int($amount, 'amount');
        $referees = Util::int($stats['referees'] ?? 0, 'referees');
        $volume = Util::int($stats['volume'] ?? 0, 'volume');
        $paidBefore = ($stats['paidBefore'] ?? false) === true;
        $zero = static fn(string $reason): array => ['amount' => 0, 'rateBps' => null, 'tier' => null, 'reason' => $reason];
        if ($amount < $plan['minEarning']) {
            return $zero('below_minimum');
        }
        if ($plan['type'] === 'flat') {
            if ($plan['trigger'] === 'first' && $paidBefore) {
                return $zero('flat_already_paid');
            }
            return ['amount' => $plan['amount'], 'rateBps' => null, 'tier' => null, 'reason' => null];
        }
        if ($plan['type'] === 'percentage') {
            return ['amount' => Util::mulDiv($amount, $plan['rateBps'], Util::BPS), 'rateBps' => $plan['rateBps'], 'tier' => null, 'reason' => null];
        }
        $measure = $plan['basis'] === 'volume' ? $volume : $referees;
        $tier = 0;
        foreach ($plan['tiers'] as $i => $t) {
            if ($measure >= $t['from']) {
                $tier = $i;
            }
        }
        $rateBps = $plan['tiers'][$tier]['rateBps'];
        return ['amount' => Util::mulDiv($amount, $rateBps, Util::BPS), 'rateBps' => $rateBps, 'tier' => $tier, 'reason' => null];
    }
}

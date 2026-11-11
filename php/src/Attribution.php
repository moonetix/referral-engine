<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/** @internal Use the Referral facade. */
final class Attribution
{
    private const ACTIONS = ['block', 'review'];
    private const BUILT_IN = ['selfReferral', 'sameDevice', 'sameIp', 'samePayoutDestination'];

    /** @return array{deviceIds: list<string>, ips: list<string>, payoutDestinations: list<string>} */
    private static function profile(mixed $p, string $name): array
    {
        if ($p === null) {
            return ['deviceIds' => [], 'ips' => [], 'payoutDestinations' => []];
        }
        if (!is_array($p)) {
            Util::fail('invalid_argument', "{$name} must be an object");
        }
        return [
            'deviceIds' => Util::strList($p['deviceIds'] ?? null, "{$name}.deviceIds"),
            'ips' => Util::strList($p['ips'] ?? null, "{$name}.ips"),
            'payoutDestinations' => Util::strList($p['payoutDestinations'] ?? null, "{$name}.payoutDestinations"),
        ];
    }

    /** @param list<string> $a @param list<string> $b @return list<string> */
    private static function shared(array $a, array $b): array
    {
        $set = array_flip($b);
        return array_values(array_unique(array_values(array_filter($a, static fn(string $x): bool => isset($set[$x])))));
    }

    public static function selfReferral(array $opts = []): \Closure
    {
        $action = Util::oneOf($opts['action'] ?? null, 'action', self::ACTIONS, 'block');
        return static fn(array $ctx): ?array => $ctx['referrerId'] === $ctx['referee']['userId']
            ? ['code' => 'self_referral', 'action' => $action, 'message' => 'The referrer and the new account are the same user.']
            : null;
    }

    public static function sameDevice(array $opts = []): \Closure
    {
        $action = Util::oneOf($opts['action'] ?? null, 'action', self::ACTIONS, 'block');
        return static function (array $ctx) use ($action): ?array {
            $n = count(self::shared($ctx['referee']['deviceIds'], $ctx['referrer']['deviceIds']));
            if ($n === 0) {
                return null;
            }
            return ['code' => 'same_device', 'action' => $action, 'message' => "The new account signed up on a device the referrer has used ({$n} shared)."];
        };
    }

    public static function sameIp(array $opts = []): \Closure
    {
        $action = Util::oneOf($opts['action'] ?? null, 'action', self::ACTIONS, 'review');
        $prefixes = [
            'ipv4Prefix' => Util::int($opts['ipv4Prefix'] ?? 32, 'ipv4Prefix', 0, 32),
            'ipv6Prefix' => Util::int($opts['ipv6Prefix'] ?? 64, 'ipv6Prefix', 0, 128),
        ];
        return static function (array $ctx) use ($action, $prefixes): ?array {
            $net = static fn(array $ips): array => array_map(static fn(string $ip): string => Ip::ipPrefix($ip, $prefixes), $ips);
            $nets = self::shared($net($ctx['referee']['ips']), $net($ctx['referrer']['ips']));
            if ($nets === []) {
                return null;
            }
            return ['code' => 'same_ip', 'action' => $action, 'message' => 'The new account and the referrer share the network ' . implode(', ', $nets) . '.'];
        };
    }

    public static function samePayoutDestination(array $opts = []): \Closure
    {
        $action = Util::oneOf($opts['action'] ?? null, 'action', self::ACTIONS, 'block');
        return static function (array $ctx) use ($action): ?array {
            if (self::shared($ctx['referee']['payoutDestinations'], $ctx['referrer']['payoutDestinations']) === []) {
                return null;
            }
            return ['code' => 'same_payout_destination', 'action' => $action, 'message' => 'The new account and the referrer use the same payout destination.'];
        };
    }

    /** @return list<\Closure> */
    public static function defaultChecks(): array
    {
        return [self::selfReferral(), self::sameDevice(), self::sameIp(), self::samePayoutDestination()];
    }

    private static function toCheck(mixed $c, int $i): callable
    {
        if (is_array($c) && isset($c['check']) && in_array($c['check'], self::BUILT_IN, true)) {
            $factory = $c['check'];
            return self::$factory($c);
        }
        if (is_callable($c)) {
            return $c;
        }
        Util::fail('invalid_argument', "rules.checks[{$i}] must be callable or ['check' => 'selfReferral' | 'sameDevice' | 'sameIp' | 'samePayoutDestination']");
    }

    private static function checkReason(mixed $r, int $i): ?array
    {
        if ($r === null || $r === false) {
            return null;
        }
        if (!is_array($r) || !is_string($r['code'] ?? null) || !in_array($r['action'] ?? null, self::ACTIONS, true)) {
            Util::fail('invalid_argument', "check {$i} must return null or ['code' => ..., 'action' => 'block' | 'review', 'message' => ...]");
        }
        return ['code' => $r['code'], 'action' => $r['action'], 'message' => is_string($r['message'] ?? null) ? $r['message'] : $r['code']];
    }

    public static function attribute(array $input): array
    {
        $signup = $input['signup'] ?? null;
        if (!is_array($signup)) {
            Util::fail('invalid_argument', 'signup is required');
        }
        $touches = $input['touches'] ?? [];
        $referrers = $input['referrers'] ?? [];
        $rules = $input['rules'] ?? [];
        $referee = ['userId' => Util::id($signup['userId'] ?? null, 'signup.userId'), 'at' => Util::int($signup['at'] ?? null, 'signup.at')] + self::profile($signup, 'signup');
        $model = Util::oneOf($rules['model'] ?? null, 'rules.model', ['first', 'last'], 'last');
        $window = Util::int($rules['windowSeconds'] ?? 2592000, 'rules.windowSeconds');
        $grace = Util::int($rules['typedCodeGraceSeconds'] ?? 604800, 'rules.typedCodeGraceSeconds');
        if (array_key_exists('checks', $rules) && !is_array($rules['checks'])) {
            Util::fail('invalid_argument', 'rules.checks must be an array');
        }
        $checks = self::defaultChecks();
        if (array_key_exists('checks', $rules)) {
            $checks = [];
            foreach (array_values($rules['checks']) as $i => $c) {
                $checks[] = self::toCheck($c, $i);
            }
        }
        if (!is_array($touches) || !array_is_list($touches)) {
            Util::fail('invalid_argument', 'touches must be an array');
        }
        if (!is_array($referrers)) {
            Util::fail('invalid_argument', 'referrers must be an object keyed by referrer ID');
        }

        $eligible = [];
        foreach ($touches as $i => $t) {
            if (!is_array($t)) {
                Util::fail('invalid_argument', "touches[{$i}] must be an object");
            }
            $touch = [
                'referrerId' => Util::id($t['referrerId'] ?? null, "touches[{$i}].referrerId"),
                'at' => Util::int($t['at'] ?? null, "touches[{$i}].at"),
                'source' => Util::oneOf($t['source'] ?? null, "touches[{$i}].source", ['link', 'typed'], 'link'),
            ];
            if (array_key_exists('code', $t)) {
                $touch['code'] = $t['code'];
            }
            $ok = $touch['at'] <= $referee['at']
                ? $referee['at'] - $touch['at'] <= $window
                : $touch['source'] === 'typed' && $touch['at'] - $referee['at'] <= $grace;
            if ($ok) {
                $eligible[] = ['touch' => $touch, 'i' => $i];
            }
        }

        if ($eligible === []) {
            return ['decision' => 'none', 'referrerId' => null, 'touch' => null, 'reasons' => [
                ['code' => 'no_eligible_touch', 'action' => 'info', 'message' => 'No referral touch falls inside the attribution window.'],
            ]];
        }

        usort($eligible, static fn(array $a, array $b): int => $model === 'first'
            ? ($a['touch']['at'] <=> $b['touch']['at'] ?: $a['i'] <=> $b['i'])
            : ($b['touch']['at'] <=> $a['touch']['at'] ?: $b['i'] <=> $a['i']));

        $reasons = [];
        $tried = [];
        foreach ($eligible as ['touch' => $touch]) {
            $rid = $touch['referrerId'];
            if (isset($tried[$rid])) {
                continue;
            }
            $tried[$rid] = true;
            $ctx = ['referee' => $referee, 'referrerId' => $rid, 'referrer' => self::profile($referrers[$rid] ?? null, "referrers.{$rid}"), 'touch' => $touch];
            $found = [];
            foreach ($checks as $i => $check) {
                $r = self::checkReason($check($ctx), $i);
                if ($r !== null) {
                    $found[] = $r + ['referrerId' => $rid];
                }
            }
            array_push($reasons, ...$found);
            $blocked = array_filter($found, static fn(array $r): bool => $r['action'] === 'block') !== [];
            if (!$blocked) {
                return ['decision' => $found !== [] ? 'review' : 'attributed', 'referrerId' => $rid, 'touch' => $touch, 'reasons' => $reasons];
            }
        }
        return ['decision' => 'blocked', 'referrerId' => null, 'touch' => null, 'reasons' => $reasons];
    }
}

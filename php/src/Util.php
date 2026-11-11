<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/** @internal */
final class Util
{
    public const MAX_AMOUNT = 9007199254740991;
    public const BPS = 10000;

    public static function fail(string $code, string $message): never
    {
        throw new ReferralException($code, $message);
    }

    public static function int(mixed $value, string $name, int $min = 0, int $max = self::MAX_AMOUNT): int
    {
        if (!is_int($value) || $value > self::MAX_AMOUNT || $value < -self::MAX_AMOUNT) {
            self::fail('invalid_argument', "{$name} must be a safe integer");
        }
        if ($value < $min || $value > $max) {
            self::fail('invalid_argument', "{$name} must be from {$min} to {$max}");
        }
        return $value;
    }

    public static function optInt(mixed $value, string $name, int $min = 0, int $max = self::MAX_AMOUNT): ?int
    {
        return $value === null ? null : self::int($value, $name, $min, $max);
    }

    public static function id(mixed $value, string $name): string
    {
        if (is_int($value) && abs($value) <= self::MAX_AMOUNT) {
            return (string)$value;
        }
        if (!is_string($value) || $value === '' || strlen($value) > 191) {
            self::fail('invalid_argument', "{$name} must be a non-empty string of at most 191 bytes or an integer");
        }
        return $value;
    }

    /** @return list<string> */
    public static function strList(mixed $value, string $name): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            self::fail('invalid_argument', "{$name} must be an array of strings");
        }
        foreach ($value as $i => $v) {
            if (!is_string($v) || $v === '') {
                self::fail('invalid_argument', "{$name}[{$i}] must be a non-empty string");
            }
        }
        return $value;
    }

    /** @param list<string> $allowed */
    public static function oneOf(mixed $value, string $name, array $allowed, ?string $fallback): ?string
    {
        if ($value === null) {
            return $fallback;
        }
        if (!in_array($value, $allowed, true)) {
            self::fail('invalid_argument', "{$name} must be one of " . implode(', ', $allowed));
        }
        return $value;
    }

    /** floor(a * b / d) for non-negative integers, exact even when a * b exceeds 64 bits. */
    public static function mulDiv(int $a, int $b, int $d): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        if ($a <= intdiv(PHP_INT_MAX, $b)) {
            $q = intdiv($a * $b, $d);
        } else {
            // Base-1024 digits: the running remainder stays below d <= 2^53, so r * 1024 fits in 63 bits.
            $da = self::digits($a);
            $db = self::digits($b);
            $p = array_fill(0, count($da) + count($db) + 1, 0);
            foreach ($da as $i => $x) {
                foreach ($db as $j => $y) {
                    $p[$i + $j] += $x * $y;
                }
            }
            $carry = 0;
            foreach ($p as $k => $v) {
                $v += $carry;
                $p[$k] = $v & 1023;
                $carry = $v >> 10;
            }
            $q = 0;
            $r = 0;
            for ($k = count($p) - 1; $k >= 0; $k--) {
                $r = $r * 1024 + $p[$k];
                if ($q > intdiv(self::MAX_AMOUNT, 1024)) {
                    self::fail('overflow', 'result exceeds 2^53 - 1');
                }
                $q = $q * 1024 + intdiv($r, $d);
                $r %= $d;
            }
        }
        if ($q > self::MAX_AMOUNT) {
            self::fail('overflow', 'result exceeds 2^53 - 1');
        }
        return $q;
    }

    /** @return list<int> */
    private static function digits(int $n): array
    {
        $out = [];
        while ($n > 0) {
            $out[] = $n & 1023;
            $n >>= 10;
        }
        return $out;
    }

    public static function plural(int $n, string $one, string $many): string
    {
        return $n . ' ' . ($n === 1 ? $one : $many);
    }

    /** @param list<string> $items */
    public static function joinList(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }
        return implode(', ', array_slice($items, 0, -1)) . ' and ' . $items[count($items) - 1];
    }

    public static function duration(int $seconds): string
    {
        if ($seconds % 86400 === 0) {
            return self::plural(intdiv($seconds, 86400), 'day', 'days');
        }
        if ($seconds % 3600 === 0) {
            return self::plural(intdiv($seconds, 3600), 'hour', 'hours');
        }
        if ($seconds % 60 === 0) {
            return self::plural(intdiv($seconds, 60), 'minute', 'minutes');
        }
        return self::plural($seconds, 'second', 'seconds');
    }

    public static function percentFromBps(int $bps): string
    {
        $whole = intdiv($bps, 100);
        $frac = $bps % 100;
        if ($frac === 0) {
            return "{$whole}%";
        }
        return $whole . '.' . rtrim(str_pad((string)$frac, 2, '0', STR_PAD_LEFT), '0') . '%';
    }
}

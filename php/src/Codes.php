<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/** @internal Use the Referral facade. */
final class Codes
{
    // 29 symbols: no 0 O 1 I L U Z. A prime size lets the check character catch
    // every single-character error and every swap of two neighbors.
    public const DEFAULT_ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXY';

    private const LOOKALIKES = [['O', '0'], ['I', '1'], ['L', '1']];

    private static function isPrime(int $n): bool
    {
        if ($n < 2) {
            return false;
        }
        for ($i = 2; $i * $i <= $n; $i++) {
            if ($n % $i === 0) {
                return false;
            }
        }
        return true;
    }

    public static function alphabet(mixed $alphabet): string
    {
        if ($alphabet === null) {
            return self::DEFAULT_ALPHABET;
        }
        if (!is_string($alphabet) || preg_match('/^[0-9A-Z]{2,64}$/', $alphabet) !== 1) {
            Util::fail('invalid_argument', 'alphabet must be 2 to 64 characters from 0-9 and A-Z');
        }
        if (count(array_unique(str_split($alphabet))) !== strlen($alphabet)) {
            Util::fail('invalid_argument', 'alphabet has repeated characters');
        }
        return $alphabet;
    }

    /** @return array{0: string, 1: bool, 2: int} */
    private static function options(array $opts): array
    {
        $alphabet = self::alphabet($opts['alphabet'] ?? null);
        $checkChar = ($opts['checkChar'] ?? false) === true;
        $length = Util::int($opts['length'] ?? 8, 'length', 4, 64);
        if ($checkChar) {
            $p = strlen($alphabet);
            if (!self::isPrime($p)) {
                Util::fail('alphabet_not_prime', "a check character needs an alphabet of prime size, got {$p}");
            }
            if ($length + 1 >= $p) {
                Util::fail('invalid_argument', 'with a check character, length must be below ' . ($p - 1));
            }
        }
        return [$alphabet, $checkChar, $length];
    }

    /** @param list<int> $values */
    private static function weightedSum(array $values, int $p): int
    {
        $s = 0;
        foreach ($values as $i => $v) {
            $s = ($s + ($i + 1) * $v) % $p;
        }
        return $s;
    }

    private static function inverse(int $a, int $p): int
    {
        for ($x = 1; $x < $p; $x++) {
            if (($a * $x) % $p === 1) {
                return $x;
            }
        }
        return 0;
    }

    public static function checkCharFor(mixed $code, array $opts = []): string
    {
        $alphabet = self::alphabet($opts['alphabet'] ?? null);
        $p = strlen($alphabet);
        if (!self::isPrime($p)) {
            Util::fail('alphabet_not_prime', "a check character needs an alphabet of prime size, got {$p}");
        }
        if (!is_string($code) || $code === '' || strlen($code) + 1 >= $p) {
            Util::fail('invalid_code', 'code is empty or too long for a check character');
        }
        $values = [];
        foreach (str_split($code) as $ch) {
            $v = strpos($alphabet, $ch);
            if ($v === false) {
                Util::fail('invalid_code', "\"{$ch}\" is not in the alphabet");
            }
            $values[] = $v;
        }
        $s = self::weightedSum($values, $p);
        return $alphabet[((($p - $s) % $p) * self::inverse(strlen($code) + 1, $p)) % $p];
    }

    public static function generateCode(array $opts = []): string
    {
        [$alphabet, $checkChar, $length] = self::options($opts);
        $randomBytes = $opts['randomBytes'] ?? static fn(int $n): string => random_bytes($n);
        if (!is_callable($randomBytes)) {
            Util::fail('invalid_argument', 'randomBytes must be callable');
        }
        $n = strlen($alphabet);
        $limit = 256 - (256 % $n);
        $code = '';
        while (strlen($code) < $length) {
            $bytes = $randomBytes($length - strlen($code));
            if (!is_string($bytes) || $bytes === '') {
                Util::fail('invalid_argument', 'randomBytes returned no bytes');
            }
            foreach (unpack('C*', $bytes) as $b) {
                if ($b < $limit && strlen($code) < $length) {
                    $code .= $alphabet[$b % $n];
                }
            }
        }
        return $checkChar ? $code . self::checkCharFor($code, ['alphabet' => $alphabet]) : $code;
    }

    public static function normalizeCode(mixed $input, array $opts = []): ?string
    {
        $alphabet = self::alphabet($opts['alphabet'] ?? null);
        if (!is_string($input)) {
            Util::fail('invalid_argument', 'code must be a string');
        }
        $s = strtr((string)preg_replace('/[ \t\n\r\f\x0B-]+/', '', $input), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        foreach (self::LOOKALIKES as [$from, $to]) {
            if (!str_contains($alphabet, $from) && str_contains($alphabet, $to)) {
                $s = str_replace($from, $to, $s);
            }
        }
        if ($s === '' || strspn($s, $alphabet) !== strlen($s)) {
            return null;
        }
        return $s;
    }

    public static function isValidCode(mixed $input, array $opts = []): bool
    {
        [$alphabet, $checkChar, $length] = self::options($opts);
        $code = self::normalizeCode($input, ['alphabet' => $alphabet]);
        if ($code === null || strlen($code) !== $length + ($checkChar ? 1 : 0)) {
            return false;
        }
        if (!$checkChar) {
            return true;
        }
        $values = array_map(static fn(string $ch): int => (int)strpos($alphabet, $ch), str_split($code));
        return self::weightedSum($values, strlen($alphabet)) === 0;
    }

    public static function formatCode(mixed $code, array $opts = []): string
    {
        $groupSize = Util::int($opts['groupSize'] ?? 4, 'groupSize', 1, 64);
        $separator = $opts['separator'] ?? '-';
        if (!is_string($code) || !is_string($separator)) {
            Util::fail('invalid_argument', 'code and separator must be strings');
        }
        return $code === '' ? '' : implode($separator, str_split($code, $groupSize));
    }

    public static function createUniqueCode(callable $exists, array $opts = []): string
    {
        $maxAttempts = Util::int($opts['maxAttempts'] ?? 10, 'maxAttempts', 1, 1000);
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $code = self::generateCode($opts);
            if (!$exists($code)) {
                return $code;
            }
        }
        Util::fail('code_space_exhausted', "no free code after {$maxAttempts} attempts; use longer codes");
    }
}

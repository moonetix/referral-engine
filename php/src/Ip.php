<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/** @internal Use the Referral facade. Own parser so PHP and JavaScript accept exactly the same input. */
final class Ip
{
    /** @return list<int>|null */
    private static function parseV4(string $s): ?array
    {
        $parts = explode('.', $s);
        if (count($parts) !== 4) {
            return null;
        }
        $out = [];
        foreach ($parts as $p) {
            if (preg_match('/^(0|[1-9]\d{0,2})$/', $p) !== 1 || (int)$p > 255) {
                return null;
            }
            $out[] = (int)$p;
        }
        return $out;
    }

    /** @return list<int>|null */
    private static function groups(string $part): ?array
    {
        if ($part === '') {
            return [];
        }
        $out = [];
        $items = explode(':', $part);
        foreach ($items as $i => $g) {
            if ($i === count($items) - 1 && str_contains($g, '.')) {
                $v4 = self::parseV4($g);
                if ($v4 === null) {
                    return null;
                }
                $out[] = ($v4[0] << 8) | $v4[1];
                $out[] = ($v4[2] << 8) | $v4[3];
            } elseif (preg_match('/^[0-9a-fA-F]{1,4}$/', $g) === 1) {
                $out[] = hexdec($g);
            } else {
                return null;
            }
        }
        return $out;
    }

    /** @return list<int>|null */
    private static function parseV6(string $s): ?array
    {
        if (preg_match('/^[0-9a-fA-F:.]+$/', $s) !== 1) {
            return null;
        }
        $halves = explode('::', $s);
        if (count($halves) > 2) {
            return null;
        }
        $head = self::groups($halves[0]);
        $tail = count($halves) === 2 ? self::groups($halves[1]) : [];
        if ($head === null || $tail === null) {
            return null;
        }
        if (count($halves) === 2 && str_contains($halves[0], '.')) {
            return null;
        }
        if (count($halves) === 1) {
            return count($head) === 8 ? $head : null;
        }
        if (count($head) + count($tail) > 7) {
            return null;
        }
        return array_merge($head, array_fill(0, 8 - count($head) - count($tail), 0), $tail);
    }

    /** @return array{version: int, bytes: list<int>} */
    public static function parseIp(mixed $text): array
    {
        if (!is_string($text)) {
            Util::fail('invalid_ip', 'IP address must be a string');
        }
        $s = trim($text, " \t\n\r\f\x0B");
        $v4 = self::parseV4($s);
        if ($v4 !== null) {
            return ['version' => 4, 'bytes' => $v4];
        }
        $g = str_contains($s, ':') ? self::parseV6($s) : null;
        if ($g === null) {
            Util::fail('invalid_ip', "not an IP address: \"{$text}\"");
        }
        if ($g[0] === 0 && $g[1] === 0 && $g[2] === 0 && $g[3] === 0 && $g[4] === 0 && $g[5] === 0xffff) {
            return ['version' => 4, 'bytes' => [$g[6] >> 8, $g[6] & 255, $g[7] >> 8, $g[7] & 255]];
        }
        $bytes = [];
        foreach ($g as $x) {
            $bytes[] = $x >> 8;
            $bytes[] = $x & 255;
        }
        return ['version' => 6, 'bytes' => $bytes];
    }

    /** @param list<int> $bytes @return list<int> */
    private static function mask(array $bytes, int $prefix): array
    {
        foreach ($bytes as $i => $b) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $bytes[$i] = $bits === 8 ? $b : $b & ((0xff << (8 - $bits)) & 0xff);
        }
        return $bytes;
    }

    /** @param list<int> $bytes */
    private static function formatV6(array $bytes): string
    {
        $g = [];
        for ($i = 0; $i < 16; $i += 2) {
            $g[] = ($bytes[$i] << 8) | $bytes[$i + 1];
        }
        $bestStart = -1;
        $bestLen = 0;
        for ($i = 0; $i < 8;) {
            if ($g[$i] !== 0) {
                $i++;
                continue;
            }
            $j = $i;
            while ($j < 8 && $g[$j] === 0) {
                $j++;
            }
            if ($j - $i > $bestLen && $j - $i >= 2) {
                $bestStart = $i;
                $bestLen = $j - $i;
            }
            $i = $j;
        }
        $hex = array_map('dechex', $g);
        if ($bestStart < 0) {
            return implode(':', $hex);
        }
        return implode(':', array_slice($hex, 0, $bestStart)) . '::' . implode(':', array_slice($hex, $bestStart + $bestLen));
    }

    public static function ipPrefix(mixed $ip, array $opts = []): string
    {
        $v4 = Util::int($opts['ipv4Prefix'] ?? 24, 'ipv4Prefix', 0, 32);
        $v6 = Util::int($opts['ipv6Prefix'] ?? 64, 'ipv6Prefix', 0, 128);
        $parsed = self::parseIp($ip);
        if ($parsed['version'] === 4) {
            return implode('.', self::mask($parsed['bytes'], $v4)) . '/' . $v4;
        }
        return self::formatV6(self::mask($parsed['bytes'], $v6)) . '/' . $v6;
    }
}

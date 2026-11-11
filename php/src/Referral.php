<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/**
 * Stateless helpers: codes, attribution, plans, fraud signals. Option arrays use the same
 * keys as the JavaScript options objects, and results have the same shape.
 */
final class Referral
{
    public const DEFAULT_ALPHABET = Codes::DEFAULT_ALPHABET;
    public const MAX_AMOUNT = Util::MAX_AMOUNT;
    public const DEFAULT_WEIGHTS = Fraud::DEFAULT_WEIGHTS;

    public static function generateCode(array $opts = []): string
    {
        return Codes::generateCode($opts);
    }

    public static function normalizeCode(mixed $input, array $opts = []): ?string
    {
        return Codes::normalizeCode($input, $opts);
    }

    public static function isValidCode(mixed $input, array $opts = []): bool
    {
        return Codes::isValidCode($input, $opts);
    }

    public static function checkCharFor(mixed $code, array $opts = []): string
    {
        return Codes::checkCharFor($code, $opts);
    }

    public static function formatCode(mixed $code, array $opts = []): string
    {
        return Codes::formatCode($code, $opts);
    }

    /** @param callable(string): bool $exists */
    public static function createUniqueCode(callable $exists, array $opts = []): string
    {
        return Codes::createUniqueCode($exists, $opts);
    }

    /** @return array{version: int, bytes: list<int>} */
    public static function parseIp(mixed $ip): array
    {
        return Ip::parseIp($ip);
    }

    public static function ipPrefix(mixed $ip, array $opts = []): string
    {
        return Ip::ipPrefix($ip, $opts);
    }

    public static function attribute(array $input): array
    {
        return Attribution::attribute($input);
    }

    /** @return list<\Closure> */
    public static function defaultChecks(): array
    {
        return Attribution::defaultChecks();
    }

    public static function selfReferral(array $opts = []): \Closure
    {
        return Attribution::selfReferral($opts);
    }

    public static function sameDevice(array $opts = []): \Closure
    {
        return Attribution::sameDevice($opts);
    }

    public static function sameIp(array $opts = []): \Closure
    {
        return Attribution::sameIp($opts);
    }

    public static function samePayoutDestination(array $opts = []): \Closure
    {
        return Attribution::samePayoutDestination($opts);
    }

    public static function definePlan(mixed $config): array
    {
        return Plan::definePlan($config);
    }

    public static function commissionFor(mixed $plan, mixed $amount, array $stats = []): array
    {
        return Plan::commissionFor($plan, $amount, $stats);
    }

    public static function fraudSignals(array $input): array
    {
        return Fraud::fraudSignals($input);
    }

    /** @return list<list<string>> */
    public static function findRings(mixed $edges): array
    {
        return Fraud::findRings($edges);
    }
}

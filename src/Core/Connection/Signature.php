<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

final class Signature
{
    public static function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }

    public static function verify(
        string $secret,
        int $timestamp,
        string $rawBody,
        string $expectedSignature,
        int $now,
        int $window = 300
    ): bool {
        if (abs($now - $timestamp) > $window) {
            return false;
        }

        $calculated = self::sign($secret, $timestamp, $rawBody);
        return hash_equals($calculated, strtolower(trim($expectedSignature)));
    }
}

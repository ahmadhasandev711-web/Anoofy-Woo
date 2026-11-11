<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

final class InboundVerifier
{
    private const WINDOW_SECONDS = 300;

    public static function verify(
        string $expectedKid,
        string $secret,
        ?string $headerKid,
        ?string $headerTs,
        ?string $headerSig,
        string $rawBody,
        int $now
    ): bool {
        if ($headerKid === null || $headerKid === '' || !hash_equals($expectedKid, $headerKid)) {
            return false;
        }

        if ($headerTs === null || $headerTs === '' || !ctype_digit($headerTs)) {
            return false;
        }

        $ts = (int) $headerTs;
        if (abs($now - $ts) > self::WINDOW_SECONDS) {
            return false;
        }

        if ($headerSig === null || $headerSig === '') {
            return false;
        }

        $expectedSig = hash_hmac('sha256', $headerTs . '.' . $rawBody, $secret);

        return hash_equals($expectedSig, strtolower(trim($headerSig)));
    }
}

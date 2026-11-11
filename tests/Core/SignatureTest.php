<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Connection\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    public function testSignMatchesHmacSha256TestVector(): void
    {
        $secret = 'test_secret_key_12345';
        $timestamp = 1730721600;
        $body = '{"schema_version":1,"event_type":"connection.verify"}';

        // Expected HMAC-SHA256 of "1730721600.{"schema_version":1,"event_type":"connection.verify"}" with "test_secret_key_12345"
        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        $signature = Signature::sign($secret, $timestamp, $body);

        self::assertSame($expected, $signature);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }

    public function testVerifySucceedsWithinWindowWithValidSignature(): void
    {
        $secret = 'my_secret';
        $timestamp = 1000;
        $body = '{"event":"test"}';
        $sig = Signature::sign($secret, $timestamp, $body);

        // Within 300s window: now = 1100 (diff 100 <= 300)
        self::assertTrue(Signature::verify($secret, $timestamp, $body, $sig, now: 1100));
        // Exactly at window edge: diff 300
        self::assertTrue(Signature::verify($secret, $timestamp, $body, $sig, now: 1300));
    }

    public function testVerifyFailsWhenOutsideWindow(): void
    {
        $secret = 'my_secret';
        $timestamp = 1000;
        $body = '{"event":"test"}';
        $sig = Signature::sign($secret, $timestamp, $body);

        // Past window: diff 301 > 300
        self::assertFalse(Signature::verify($secret, $timestamp, $body, $sig, now: 1301));
        // Future window beyond 300s: diff -301
        self::assertFalse(Signature::verify($secret, $timestamp, $body, $sig, now: 699));
    }

    public function testVerifyFailsWhenSignatureIsInvalid(): void
    {
        $secret = 'my_secret';
        $timestamp = 1000;
        $body = '{"event":"test"}';

        self::assertFalse(Signature::verify($secret, $timestamp, $body, 'invalid_signature_hex', now: 1050));
    }
}

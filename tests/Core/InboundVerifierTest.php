<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Connection\InboundVerifier;
use PHPUnit\Framework\TestCase;

final class InboundVerifierTest extends TestCase
{
    private string $kid;
    private string $secret;
    private int $timestamp;
    private string $rawBody;

    protected function setUp(): void
    {
        $this->kid = '01JCEXAMPLEKID1234567890AB';
        $this->secret = '64_chars_hex_secret_abcdef0123456789abcdef0123456789abcdef01234567';
        $this->timestamp = 1730722200;
        $this->rawBody = file_get_contents(dirname(__DIR__) . '/Fixtures/engaz/order.stage_changed.json');
    }

    public function testValidSignatureAndKidPasses(): void
    {
        $signature = hash_hmac('sha256', $this->timestamp . '.' . $this->rawBody, $this->secret);

        $now = $this->timestamp + 50; // 50 seconds later, well within 300s
        $this->assertTrue(InboundVerifier::verify(
            $this->kid,
            $this->secret,
            $this->kid,
            (string) $this->timestamp,
            $signature,
            $this->rawBody,
            $now
        ));
    }

    public function testWrongSignatureFails(): void
    {
        $wrongSignature = hash_hmac('sha256', $this->timestamp . '.other_body', $this->secret);

        $now = $this->timestamp;
        $this->assertFalse(InboundVerifier::verify(
            $this->kid,
            $this->secret,
            $this->kid,
            (string) $this->timestamp,
            $wrongSignature,
            $this->rawBody,
            $now
        ));
    }

    public function testWrongKidFails(): void
    {
        $signature = hash_hmac('sha256', $this->timestamp . '.' . $this->rawBody, $this->secret);

        $now = $this->timestamp;
        $this->assertFalse(InboundVerifier::verify(
            $this->kid,
            $this->secret,
            'wrong_kid_value',
            (string) $this->timestamp,
            $signature,
            $this->rawBody,
            $now
        ));
    }

    public function testExpiredTimestampFails(): void
    {
        $signature = hash_hmac('sha256', $this->timestamp . '.' . $this->rawBody, $this->secret);

        $now = $this->timestamp + 301; // 301 seconds later -> expired
        $this->assertFalse(InboundVerifier::verify(
            $this->kid,
            $this->secret,
            $this->kid,
            (string) $this->timestamp,
            $signature,
            $this->rawBody,
            $now
        ));
    }

    public function testFutureTimestampFails(): void
    {
        $signature = hash_hmac('sha256', $this->timestamp . '.' . $this->rawBody, $this->secret);

        $now = $this->timestamp - 301; // 301 seconds into future -> rejected
        $this->assertFalse(InboundVerifier::verify(
            $this->kid,
            $this->secret,
            $this->kid,
            (string) $this->timestamp,
            $signature,
            $this->rawBody,
            $now
        ));
    }

    public function testNullOrEmptyHeadersFail(): void
    {
        $now = $this->timestamp;
        $this->assertFalse(InboundVerifier::verify($this->kid, $this->secret, null, (string) $this->timestamp, 'sig', $this->rawBody, $now));
        $this->assertFalse(InboundVerifier::verify($this->kid, $this->secret, $this->kid, null, 'sig', $this->rawBody, $now));
        $this->assertFalse(InboundVerifier::verify($this->kid, $this->secret, $this->kid, (string) $this->timestamp, null, $this->rawBody, $now));
    }

    public function testInboundVerifierDelegatesToSignatureVerifyWithoutDuplicatingHmac(): void
    {
        $file = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Connection/InboundVerifier.php');
        $this->assertStringNotContainsString('hash_hmac', $file, 'InboundVerifier must not duplicate HMAC calculation');
        $this->assertStringNotContainsString('abs(', $file, 'InboundVerifier must not duplicate timestamp window calculation');
        $this->assertStringContainsString('Signature::verify', $file, 'InboundVerifier must delegate to Signature::verify');
    }
}

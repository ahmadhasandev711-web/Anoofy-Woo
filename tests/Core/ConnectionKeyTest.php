<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Connection\ConnectionKey;
use Anoofy\Woo\Core\Connection\InvalidConnectionKeyException;
use PHPUnit\Framework\TestCase;

final class ConnectionKeyTest extends TestCase
{
    private function encodeBase64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function testParsesValidConnectionKey(): void
    {
        $payload = json_encode([
            'v' => 1,
            'api' => 'https://engazpro.example.com/api/webhooks/anoofy/events',
            'kid' => '01JCEXAMPLEKID1234567890AB',
            'secret' => 'a9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e3d2c1b0a9f8',
        ], JSON_THROW_ON_ERROR);

        $keyString = $this->encodeBase64Url($payload);
        $keyData = ConnectionKey::parse($keyString);

        self::assertSame(1, $keyData->version);
        self::assertSame('https://engazpro.example.com/api/webhooks/anoofy/events', $keyData->api);
        self::assertSame('01JCEXAMPLEKID1234567890AB', $keyData->kid);
        self::assertSame('a9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e3d2c1b0a9f8', $keyData->secret);
    }

    public function testThrowsOnInvalidBase64(): void
    {
        $this->expectException(InvalidConnectionKeyException::class);
        ConnectionKey::parse('!!!not-valid-base64url!!!');
    }

    public function testThrowsOnNonJsonPayload(): void
    {
        $this->expectException(InvalidConnectionKeyException::class);
        ConnectionKey::parse($this->encodeBase64Url('plain text not json'));
    }

    public function testThrowsWhenVersionIsNotOne(): void
    {
        $payload = json_encode([
            'v' => 2,
            'api' => 'https://example.com',
            'kid' => 'k1',
            'secret' => 's1',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidConnectionKeyException::class);
        ConnectionKey::parse($this->encodeBase64Url($payload));
    }

    public function testThrowsWhenApiIsNotHttps(): void
    {
        $payload = json_encode([
            'v' => 1,
            'api' => 'http://example.com/api',
            'kid' => 'k1',
            'secret' => 's1',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidConnectionKeyException::class);
        ConnectionKey::parse($this->encodeBase64Url($payload));
    }

    public function testThrowsWhenKidIsEmpty(): void
    {
        $payload = json_encode([
            'v' => 1,
            'api' => 'https://example.com/api',
            'kid' => '   ',
            'secret' => 's1',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidConnectionKeyException::class);
        ConnectionKey::parse($this->encodeBase64Url($payload));
    }

    public function testThrowsWhenSecretIsEmpty(): void
    {
        $payload = json_encode([
            'v' => 1,
            'api' => 'https://example.com/api',
            'kid' => 'k1',
            'secret' => '',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidConnectionKeyException::class);
        ConnectionKey::parse($this->encodeBase64Url($payload));
    }

    public function testRejectsHttpWhenAllowInsecureIsExplicitlyFalse(): void
    {
        $payload = json_encode([
            'v' => 1,
            'api' => 'http://example.com/api',
            'kid' => 'k1',
            'secret' => 's1',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidConnectionKeyException::class);
        ConnectionKey::parse($this->encodeBase64Url($payload), false);
    }

    public function testAcceptsHttpWhenAllowInsecureIsTrue(): void
    {
        $payload = json_encode([
            'v' => 1,
            'api' => 'http://engazpro.test:28180/api/webhooks/anoofy/events',
            'kid' => '01JCDEVKEYID12345678901234',
            'secret' => 'secret_abcdef0123456789abcdef0123456789',
        ], JSON_THROW_ON_ERROR);

        $keyData = ConnectionKey::parse($this->encodeBase64Url($payload), true);
        self::assertSame('http://engazpro.test:28180/api/webhooks/anoofy/events', $keyData->api);
    }

    public function testAcceptsHttpsRegardlessOfAllowInsecure(): void
    {
        $payload = json_encode([
            'v' => 1,
            'api' => 'https://example.com/api',
            'kid' => 'k1',
            'secret' => 's1',
        ], JSON_THROW_ON_ERROR);

        $encoded = $this->encodeBase64Url($payload);
        $keyDataFalse = ConnectionKey::parse($encoded, false);
        self::assertSame('https://example.com/api', $keyDataFalse->api);

        $keyDataTrue = ConnectionKey::parse($encoded, true);
        self::assertSame('https://example.com/api', $keyDataTrue->api);
    }
}

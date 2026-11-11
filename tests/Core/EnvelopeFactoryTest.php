<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Connection\EnvelopeFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class EnvelopeFactoryTest extends TestCase
{
    public function testBuildMatchesConnectionVerifyFixture(): void
    {
        $fixturePath = dirname(__DIR__) . '/Fixtures/engaz/connection.verify.json';
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $envelope = EnvelopeFactory::build(
            type: EngazEventType::ConnectionVerify,
            orderVersion: $fixture['order_version'],
            data: $fixture['data'],
            eventId: $fixture['event_id'],
            occurredAt: new DateTimeImmutable($fixture['occurred_at'])
        );

        self::assertSame($fixture, $envelope);
        self::assertSame('connection.verify', $envelope['event_type']);
        self::assertSame(1, $envelope['schema_version']);
    }

    public function testBuildMatchesOrderConfirmedFixture(): void
    {
        $fixturePath = dirname(__DIR__) . '/Fixtures/engaz/order.confirmed.json';
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $envelope = EnvelopeFactory::build(
            type: EngazEventType::OrderConfirmed,
            orderVersion: $fixture['order_version'],
            data: $fixture['data'],
            eventId: $fixture['event_id'],
            occurredAt: new DateTimeImmutable($fixture['occurred_at'])
        );

        self::assertSame($fixture, $envelope);
        self::assertSame('order.confirmed', $envelope['event_type']);
        self::assertSame(1, $envelope['schema_version']);
    }

    public function testBuildMatchesOrderConfirmedEditedItemsFixture(): void
    {
        $fixturePath = dirname(__DIR__) . '/Fixtures/engaz/order.confirmed.edited-items.json';
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $envelope = EnvelopeFactory::build(
            type: EngazEventType::OrderConfirmed,
            orderVersion: $fixture['order_version'],
            data: $fixture['data'],
            eventId: $fixture['event_id'],
            occurredAt: new DateTimeImmutable($fixture['occurred_at'])
        );

        self::assertSame($fixture, $envelope);
        self::assertSame(2, $envelope['order_version']);
    }

    public function testBuildMatchesOrderStageChangedFixture(): void
    {
        $fixturePath = dirname(__DIR__) . '/Fixtures/engaz/order.stage_changed.json';
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $envelope = EnvelopeFactory::build(
            type: EngazEventType::OrderStageChanged,
            orderVersion: $fixture['order_version'],
            data: $fixture['data'],
            eventId: $fixture['event_id'],
            occurredAt: new DateTimeImmutable($fixture['occurred_at'])
        );

        self::assertSame($fixture, $envelope);
        self::assertSame('order.stage_changed', $envelope['event_type']);
    }

    public function testBuildGeneratesValidUuidV4AndIso8601UtcTimestampWhenDefaultsUsed(): void
    {
        $envelope = EnvelopeFactory::build(
            type: EngazEventType::ConnectionVerify,
            orderVersion: null,
            data: ['test' => true]
        );

        self::assertSame(1, $envelope['schema_version']);
        self::assertSame('connection.verify', $envelope['event_type']);
        self::assertNull($envelope['order_version']);
        self::assertSame(['test' => true], $envelope['data']);

        // UUID v4 regex
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $envelope['event_id']
        );

        // ISO-8601 UTC timestamp format: YYYY-MM-DDTHH:MM:SSZ
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $envelope['occurred_at']
        );
    }
}

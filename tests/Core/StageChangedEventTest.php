<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Connection\EngazStage;
use Anoofy\Woo\Core\Connection\InvalidStageChangedEventException;
use Anoofy\Woo\Core\Connection\StageChangedEvent;
use PHPUnit\Framework\TestCase;

final class StageChangedEventTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $json = file_get_contents(dirname(__DIR__) . '/Fixtures/engaz/order.stage_changed.json');
        $this->fixture = json_decode($json, true);
    }

    public function testParsesValidFixture(): void
    {
        $event = StageChangedEvent::fromEnvelope($this->fixture);

        $this->assertSame('33333333-3333-4333-8333-333333333333', $event->eventId);
        $this->assertSame(EngazEventType::OrderStageChanged, $event->eventType);
        $this->assertSame('2026-11-04T12:10:00Z', $event->occurredAt);
        $this->assertSame(1, $event->orderVersion);
        $this->assertSame(5001, $event->wcOrderId);
        $this->assertSame(EngazStage::CONFIRMED, $event->stage);
        $this->assertSame(EngazStage::PENDING_CONFIRMATION, $event->previousStage);
        $this->assertSame('2026-11-04T12:10:00Z', $event->changedAt);
        $this->assertSame('الفرع الرئيسي', $event->branchName);
        $this->assertSame('شركة أرامكس', $event->courierName);
        $this->assertSame('TRK-987654321', $event->trackingNumber);
    }

    public function testAcceptsAnyValidUuidEvenNonV4(): void
    {
        $envelope = $this->fixture;
        // Non-v4 UUID (e.g. v1: time-based, or v7)
        $envelope['event_id'] = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

        $event = StageChangedEvent::fromEnvelope($envelope);
        $this->assertSame('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $event->eventId);
    }

    public function testRejectsInvalidUuid(): void
    {
        $this->expectException(InvalidStageChangedEventException::class);
        $envelope = $this->fixture;
        $envelope['event_id'] = 'not-a-valid-uuid';
        StageChangedEvent::fromEnvelope($envelope);
    }

    public function testRejectsWrongSchemaVersion(): void
    {
        $this->expectException(InvalidStageChangedEventException::class);
        $envelope = $this->fixture;
        $envelope['schema_version'] = 2;
        StageChangedEvent::fromEnvelope($envelope);
    }

    public function testRejectsWrongEventType(): void
    {
        $this->expectException(InvalidStageChangedEventException::class);
        $envelope = $this->fixture;
        $envelope['event_type'] = 'order.confirmed';
        StageChangedEvent::fromEnvelope($envelope);
    }

    public function testRejectsInvalidOrderVersion(): void
    {
        $this->expectException(InvalidStageChangedEventException::class);
        $envelope = $this->fixture;
        $envelope['order_version'] = 0;
        StageChangedEvent::fromEnvelope($envelope);
    }

    public function testRejectsUnknownStage(): void
    {
        $this->expectException(InvalidStageChangedEventException::class);
        $envelope = $this->fixture;
        $envelope['data']['stage'] = 'UNKNOWN_STAGE';
        StageChangedEvent::fromEnvelope($envelope);
    }

    public function testRejectsPendingConfirmationInStage(): void
    {
        $this->expectException(InvalidStageChangedEventException::class);
        $envelope = $this->fixture;
        $envelope['data']['stage'] = 'PENDING_CONFIRMATION';
        StageChangedEvent::fromEnvelope($envelope);
    }

    public function testRejectsMissingWcOrderId(): void
    {
        $this->expectException(InvalidStageChangedEventException::class);
        $envelope = $this->fixture;
        unset($envelope['data']['wc_order_id']);
        StageChangedEvent::fromEnvelope($envelope);
    }

    public function testRejectsMissingStage(): void
    {
        $this->expectException(InvalidStageChangedEventException::class);
        $envelope = $this->fixture;
        unset($envelope['data']['stage']);
        StageChangedEvent::fromEnvelope($envelope);
    }

    public function testAllowsNullOptionalFields(): void
    {
        $envelope = $this->fixture;
        $envelope['data']['previous_stage'] = null;
        $envelope['data']['branch_name'] = null;
        $envelope['data']['courier_name'] = null;
        $envelope['data']['tracking_number'] = null;

        $event = StageChangedEvent::fromEnvelope($envelope);
        $this->assertNull($event->previousStage);
        $this->assertNull($event->branchName);
        $this->assertNull($event->courierName);
        $this->assertNull($event->trackingNumber);
    }
}

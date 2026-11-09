<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Mode\ModePolicy;
use Anoofy\Woo\Core\Mode\OperatingMode;
use Anoofy\Woo\Core\Order\Effect;
use Anoofy\Woo\Core\Order\OrderEvent;
use PHPUnit\Framework\TestCase;

final class ModePolicyTest extends TestCase
{
    public function testAllowsAllEventsWhenNotHandedOver(): void
    {
        foreach (OrderEvent::cases() as $event) {
            self::assertTrue(
                ModePolicy::allows($event, handedOver: false),
                "Expected {$event->value} to be allowed when not handed over."
            );
        }
    }

    public function testBlocksAllEventsWhenHandedOver(): void
    {
        foreach (OrderEvent::cases() as $event) {
            self::assertFalse(
                ModePolicy::allows($event, handedOver: true),
                "Expected {$event->value} to be blocked when handed over."
            );
        }
    }

    public function testExtraEffectsInStandaloneModeIsEmptyForAllEvents(): void
    {
        foreach (OrderEvent::cases() as $event) {
            self::assertSame([], ModePolicy::extraEffects(OperatingMode::Standalone, $event));
        }
    }

    public function testExtraEffectsInConnectedModeReturnsHandOverOnlyForConfirm(): void
    {
        self::assertSame(
            [Effect::HandOverToEngaz],
            ModePolicy::extraEffects(OperatingMode::Connected, OrderEvent::Confirm)
        );

        foreach (OrderEvent::cases() as $event) {
            if ($event === OrderEvent::Confirm) {
                continue;
            }
            self::assertSame(
                [],
                ModePolicy::extraEffects(OperatingMode::Connected, $event),
                "Expected empty extra effects for {$event->value} in connected mode."
            );
        }
    }
}

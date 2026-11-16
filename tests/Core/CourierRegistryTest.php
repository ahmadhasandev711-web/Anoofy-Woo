<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Courier\AramexCourierGateway;
use Anoofy\Woo\Core\Courier\BostaCourierGateway;
use Anoofy\Woo\Core\Courier\CourierRegistry;
use Anoofy\Woo\Core\Courier\ManualCourierGateway;
use Anoofy\Woo\Core\Courier\MylerzCourierGateway;
use Anoofy\Woo\Core\Courier\ShipmentRequest;
use PHPUnit\Framework\TestCase;

final class CourierRegistryTest extends TestCase
{
    public function testRegistersDefaultAdaptersBehindCourierGatewayInterface(): void
    {
        $registry = CourierRegistry::withDefaults();

        self::assertNotNull($registry->get('manual'));
        self::assertNotNull($registry->get('bosta'));
        self::assertNotNull($registry->get('aramex'));
        self::assertNotNull($registry->get('mylerz'));
        self::assertNull($registry->get('unknown'));
        self::assertCount(4, $registry->all());
    }

    public function testAdaptersBuildShipmentWithTrackingAndRejectMissingTracking(): void
    {
        $registry = CourierRegistry::withDefaults();
        $bosta = $registry->get('bosta');
        self::assertInstanceOf(BostaCourierGateway::class, $bosta);
        self::assertSame('Bosta', $bosta->label());
        self::assertFalse($bosta->supportsApiSync());

        $bad = $bosta->buildShipment(new ShipmentRequest(10, '10', 'Ahmed', 'Cairo', 25000, '   '));
        self::assertFalse($bad->ok);
        self::assertSame('TRACKING_REQUIRED', $bad->errorCode);

        $good = $bosta->buildShipment(new ShipmentRequest(10, '10', 'Ahmed', 'Cairo', 25000, 'BST-999'));
        self::assertTrue($good->ok);
        self::assertSame('Bosta', $good->courierLabel);
        self::assertSame('BST-999', $good->trackingNumber);

        self::assertInstanceOf(ManualCourierGateway::class, $registry->get('manual'));
        self::assertInstanceOf(AramexCourierGateway::class, $registry->get('aramex'));
        self::assertInstanceOf(MylerzCourierGateway::class, $registry->get('mylerz'));
    }
}

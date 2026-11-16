<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Courier;

/** Outcome returned by a `CourierGateway` when preparing/creating a shipment. */
final class ShipmentResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $courierLabel,
        public readonly ?string $trackingNumber,
        public readonly ?string $errorCode,
    ) {
    }

    public static function ok(string $courierLabel, string $trackingNumber): self
    {
        return new self(true, $courierLabel, $trackingNumber, null);
    }

    public static function fail(string $courierLabel, string $errorCode): self
    {
        return new self(false, $courierLabel, null, $errorCode);
    }
}

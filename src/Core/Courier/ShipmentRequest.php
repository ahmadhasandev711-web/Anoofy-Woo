<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Courier;

/** Pure input describing an order ready to dispatch through a courier adapter. */
final class ShipmentRequest
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly string $recipientName,
        public readonly string $city,
        public readonly int $codCents,
        public readonly ?string $trackingNumber = null,
    ) {
    }
}

<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Settlement;

use Anoofy\Woo\Core\Order\OrderStatus;

final class SettlementCandidate
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $trackingNumber,
        public readonly OrderStatus $opsStatus,
        public readonly int $orderTotalCents,
        public readonly bool $alreadySettled,
        public readonly bool $handedOver,
    ) {
    }
}

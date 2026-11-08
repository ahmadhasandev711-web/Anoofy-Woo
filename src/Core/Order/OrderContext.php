<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

/** Facts the state machine needs. The caller loads them from the database; the core only reads them. */
final class OrderContext
{
    public function __construct(
        public readonly bool $hasLoggedCall = false,
        public readonly bool $stockWarningPresent = false,
        public readonly bool $stockWarningAccepted = false,
        public readonly bool $assigned = false,
        public readonly bool $allLinesPrepared = false,
        public readonly bool $editedAfterPreparingUnconfirmed = false,
        public readonly bool $hasShipmentDetails = false,
        public readonly int $deliveryAttempts = 0,
        public readonly int $maxDeliveryAttempts = 3,
        public readonly ?string $reason = null,
    ) {
    }

    public function hasReason(): bool
    {
        return $this->reason !== null && trim($this->reason) !== '';
    }
}

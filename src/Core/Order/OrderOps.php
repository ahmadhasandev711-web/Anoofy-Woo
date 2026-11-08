<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

/** One row of `anoofy_order_ops` as a value object. Pure: built from a plain array, no database access. */
final class OrderOps
{
    public function __construct(
        public readonly int $orderId,
        public readonly OrderStatus $status,
        public readonly ?int $assignedTo,
        public readonly bool $hasLoggedCall,
        public readonly bool $stockWarning,
        public readonly bool $stockWarningAccepted,
        public readonly bool $editedAfterPreparing,
        public readonly int $deliveryAttempts,
        public readonly ?string $courier,
        public readonly ?string $trackingNumber,
    ) {
    }

    /** @param array<string, mixed> $row values as returned by $wpdb (everything is a string or null) */
    public static function fromRow(array $row): self
    {
        $assigned = $row['assigned_to'] ?? null;
        $courier = isset($row['courier']) ? trim((string) $row['courier']) : '';
        $tracking = isset($row['tracking_number']) ? trim((string) $row['tracking_number']) : '';

        return new self(
            (int) ($row['order_id'] ?? 0),
            OrderStatus::from((string) ($row['ops_status'] ?? OrderStatus::New->value)),
            $assigned === null || (int) $assigned === 0 ? null : (int) $assigned,
            (bool) (int) ($row['has_logged_call'] ?? 0),
            (bool) (int) ($row['stock_warning'] ?? 0),
            (bool) (int) ($row['stock_warning_accepted'] ?? 0),
            (bool) (int) ($row['edited_after_preparing'] ?? 0),
            (int) ($row['delivery_attempts'] ?? 0),
            $courier === '' ? null : $courier,
            $tracking === '' ? null : $tracking,
        );
    }

    /**
     * Facts for the state machine. Phase 1 has no preparation screen yet, so `allLinesPrepared` stays false;
     * Phase 2 will pass the real value in.
     */
    public function toContext(?string $reason = null, bool $allLinesPrepared = false): OrderContext
    {
        return new OrderContext(
            hasLoggedCall: $this->hasLoggedCall,
            stockWarningPresent: $this->stockWarning,
            stockWarningAccepted: $this->stockWarningAccepted,
            assigned: $this->assignedTo !== null,
            allLinesPrepared: $allLinesPrepared,
            editedAfterPreparingUnconfirmed: $this->editedAfterPreparing,
            hasShipmentDetails: $this->courier !== null && $this->trackingNumber !== null,
            deliveryAttempts: $this->deliveryAttempts,
            reason: $reason,
        );
    }
}

<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

/**
 * Operational status -> WooCommerce status (slug without the "wc-" prefix). This is the mapping from SPEC section 3;
 * it is a design assumption until spikes S-1/S-2 are run on a real store, so callers must treat it as optional
 * (see Settings "sync Woo status").
 */
final class WooStatusMap
{
    public static function forOps(OrderStatus $status): ?string
    {
        return match ($status) {
            OrderStatus::New, OrderStatus::UnderReview => 'on-hold',
            OrderStatus::Confirmed,
            OrderStatus::Preparing,
            OrderStatus::ReadyToShip,
            OrderStatus::CancellationPending,
            OrderStatus::Shipped,
            OrderStatus::DeliveryFailed => 'processing',
            OrderStatus::Delivered => 'completed',
            OrderStatus::Rejected, OrderStatus::Cancelled => 'cancelled',
            // Open question in the SPEC (restock after inspection): leave the Woo status alone.
            OrderStatus::ReturnedToOrigin => null,
        };
    }
}

<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

/**
 * Operational status. It is separate from the WooCommerce status on purpose: WooCommerce keeps
 * its own lifecycle (processing, completed...) and the plugin maps the two at defined points.
 */
enum OrderStatus: string
{
    case New = 'NEW';
    case UnderReview = 'UNDER_REVIEW';
    case Confirmed = 'CONFIRMED';
    case Rejected = 'REJECTED';
    case Preparing = 'PREPARING';
    case ReadyToShip = 'READY_TO_SHIP';
    case CancellationPending = 'CANCELLATION_PENDING';
    case Cancelled = 'CANCELLED';
    case Shipped = 'SHIPPED';
    case Delivered = 'DELIVERED';
    case DeliveryFailed = 'DELIVERY_FAILED';
    case ReturnedToOrigin = 'RETURNED_TO_ORIGIN';
}

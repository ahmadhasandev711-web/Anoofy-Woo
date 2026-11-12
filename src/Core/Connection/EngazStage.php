<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

/**
 * Operational stages matching EngazPRO WcOrderStatus enum.
 */
enum EngazStage: string
{
    case PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    case CONFIRMED = 'CONFIRMED';
    case PREPARING = 'PREPARING';
    case DISPATCHED = 'DISPATCHED';
    case SHIPPED_VIA_COURIER = 'SHIPPED_VIA_COURIER';
    case DELIVERED = 'DELIVERED';
    case PARTIALLY_DELIVERED = 'PARTIALLY_DELIVERED';
    case RETURNED = 'RETURNED';
    case CANCELLED = 'CANCELLED';

    public function isTransitionStage(): bool
    {
        return $this !== self::PENDING_CONFIRMATION;
    }
}

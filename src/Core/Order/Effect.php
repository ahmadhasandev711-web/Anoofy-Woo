<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

/** What the caller must do after a successful transition (the core itself never touches WooCommerce). */
enum Effect: string
{
    /** Set the WooCommerce order to "cancelled"; WooCommerce restores the reduced stock itself. */
    case CancelWooOrder = 'CANCEL_WOO_ORDER';
    case OpenRtoReturn = 'OPEN_RTO_RETURN';
    case AddRtoTag = 'ADD_RTO_TAG';
    case HandOverToEngaz = 'HAND_OVER_TO_ENGAZ';
}

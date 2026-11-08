<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

enum TransitionError: string
{
    case InvalidState = 'INVALID_STATE';
    case Forbidden = 'FORBIDDEN';
    case ReasonRequired = 'REASON_REQUIRED';
    case CallConfirmationRequired = 'CALL_CONFIRMATION_REQUIRED';
    case StockWarningNotAccepted = 'STOCK_WARNING_NOT_ACCEPTED';
    case StockWarningNeedsManager = 'STOCK_WARNING_NEEDS_MANAGER';
    case OrderNotAssigned = 'ORDER_NOT_ASSIGNED';
    case LinesNotPrepared = 'LINES_NOT_PREPARED';
    case RepreparationNotConfirmed = 'REPREPARATION_NOT_CONFIRMED';
    case ShipmentDetailsRequired = 'SHIPMENT_DETAILS_REQUIRED';
    case MaxDeliveryAttemptsReached = 'MAX_DELIVERY_ATTEMPTS_REACHED';
}

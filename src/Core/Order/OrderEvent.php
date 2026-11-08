<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

enum OrderEvent: string
{
    case LogFirstCall = 'LOG_FIRST_CALL';
    case Confirm = 'CONFIRM';
    case Reject = 'REJECT';
    case CancelDirect = 'CANCEL_DIRECT';
    case StartPreparing = 'START_PREPARING';
    case FinishPreparing = 'FINISH_PREPARING';
    case RequestCancel = 'REQUEST_CANCEL';
    case ConfirmRestocked = 'CONFIRM_RESTOCKED';
    case Ship = 'SHIP';
    case MarkDelivered = 'MARK_DELIVERED';
    case MarkDeliveryFailed = 'MARK_DELIVERY_FAILED';
    case RetryDelivery = 'RETRY_DELIVERY';
    case ReceiveReturned = 'RECEIVE_RETURNED';
}

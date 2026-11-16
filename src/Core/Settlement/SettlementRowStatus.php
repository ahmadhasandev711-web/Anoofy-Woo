<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Settlement;

enum SettlementRowStatus: string
{
    case Matched = 'MATCHED';
    case NotFound = 'NOT_FOUND';
    case NotDelivered = 'NOT_DELIVERED';
    case AlreadySettled = 'ALREADY_SETTLED';
    case AmountMismatch = 'AMOUNT_MISMATCH';
    case HandedOverToEngaz = 'HANDED_OVER_TO_ENGAZ';
    case DuplicateTracking = 'DUPLICATE_TRACKING';
}

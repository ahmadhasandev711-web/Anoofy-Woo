<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

enum DeliveryOutcome: string
{
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';
}

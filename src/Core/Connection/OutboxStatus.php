<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case InFlight = 'in_flight';
    case Sent = 'sent';
    case Failed = 'failed';
}

<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

enum EngazEventType: string
{
    case ConnectionVerify = 'connection.verify';
    case OrderConfirmed = 'order.confirmed';
    case OrderStageChanged = 'order.stage_changed';
}

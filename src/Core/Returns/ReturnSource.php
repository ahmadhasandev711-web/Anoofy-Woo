<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Returns;

/** Origin of a return request: automatic RTO from failed delivery, or post-delivery customer return. */
enum ReturnSource: string
{
    case Rto = 'rto';
    case Customer = 'customer';
}

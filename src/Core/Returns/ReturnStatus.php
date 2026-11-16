<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Returns;

/** Lifecycle of a return/RTO record from opening through warehouse inspection and stock approval. */
enum ReturnStatus: string
{
    case Open = 'open';
    case Inspected = 'inspected';
    case Approved = 'approved';
}

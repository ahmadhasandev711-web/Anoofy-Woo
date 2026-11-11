<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

enum InboxResult: string
{
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case Stale = 'stale';
    case Ignored = 'ignored';
}

<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Mode;

enum OperatingMode: string
{
    case Standalone = 'standalone';
    case Connected = 'connected';
}

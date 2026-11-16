<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Courier;

/** Mylerz courier adapter behind `CourierGateway`. */
final class MylerzCourierGateway extends AbstractLocalCourierGateway
{
    public function slug(): string
    {
        return 'mylerz';
    }

    public function label(): string
    {
        return 'Mylerz';
    }
}

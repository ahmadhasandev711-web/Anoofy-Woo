<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Courier;

/** Aramex courier adapter behind `CourierGateway`. */
final class AramexCourierGateway extends AbstractLocalCourierGateway
{
    public function slug(): string
    {
        return 'aramex';
    }

    public function label(): string
    {
        return 'Aramex';
    }
}

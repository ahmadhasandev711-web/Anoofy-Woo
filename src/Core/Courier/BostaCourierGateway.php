<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Courier;

/** Bosta courier adapter behind `CourierGateway` (offline waybill validation by default; extend for API sync). */
final class BostaCourierGateway extends AbstractLocalCourierGateway
{
    public function slug(): string
    {
        return 'bosta';
    }

    public function label(): string
    {
        return 'Bosta';
    }
}

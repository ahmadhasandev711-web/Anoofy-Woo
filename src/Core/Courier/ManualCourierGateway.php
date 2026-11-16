<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Courier;

/** Default manual courier adapter when merchants enter any courier name & waybill directly. */
final class ManualCourierGateway extends AbstractLocalCourierGateway
{
    public function slug(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Manual';
    }
}

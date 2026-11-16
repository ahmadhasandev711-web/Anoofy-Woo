<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Courier;

/** Shared base for built-in manual/offline courier adapters (no live outbound API credentials bundled). */
abstract class AbstractLocalCourierGateway implements CourierGateway
{
    public function supportsApiSync(): bool
    {
        return false;
    }

    public function buildShipment(ShipmentRequest $request): ShipmentResult
    {
        $tracking = trim((string) ($request->trackingNumber ?? ''));
        if ($tracking === '') {
            return ShipmentResult::fail($this->label(), 'TRACKING_REQUIRED');
        }

        return ShipmentResult::ok($this->label(), $tracking);
    }
}

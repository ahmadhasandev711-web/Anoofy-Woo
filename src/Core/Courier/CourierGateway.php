<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Courier;

/** Extension contract for courier adapters (Bosta, Aramex, Mylerz, or custom plugins). */
interface CourierGateway
{
    public function slug(): string;

    public function label(): string;

    public function supportsApiSync(): bool;

    public function buildShipment(ShipmentRequest $request): ShipmentResult;
}

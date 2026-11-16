<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

use Anoofy\Woo\Core\Connection\OrderHandoverItem;
use Anoofy\Woo\Core\Courier\CourierGateway;
use Anoofy\Woo\Core\Courier\CourierRegistry;
use Anoofy\Woo\Core\Courier\ShipmentRequest;
use Anoofy\Woo\Core\Courier\ShipmentResult;

/** Manages built-in and hook-registered `CourierGateway` adapters. */
final class CourierManager
{
    public function registry(): CourierRegistry
    {
        $registry = CourierRegistry::withDefaults();
        $filtered = apply_filters('anoofy_courier_gateways', $registry);

        return $filtered instanceof CourierRegistry ? $filtered : $registry;
    }

    /** @return array<string, CourierGateway> */
    public function all(): array
    {
        return $this->registry()->all();
    }

    public function get(string $slug): ?CourierGateway
    {
        return $this->registry()->get($slug);
    }

    public function buildShipmentForOrder(string $gatewaySlug, \WC_Order $order, ?string $trackingNumber): ShipmentResult
    {
        $gateway = $this->get($gatewaySlug) ?? $this->get('manual');
        if ($gateway === null) {
            return ShipmentResult::fail('Unknown', 'GATEWAY_NOT_FOUND');
        }

        $codCents = $order->get_payment_method() === 'cod'
            ? OrderHandoverItem::toCents((string) $order->get_total())
            : 0;

        $request = new ShipmentRequest(
            orderId: $order->get_id(),
            orderNumber: (string) $order->get_order_number(),
            recipientName: trim($order->get_formatted_billing_full_name()),
            city: (string) ($order->get_shipping_city() !== '' ? $order->get_shipping_city() : $order->get_billing_city()),
            codCents: $codCents,
            trackingNumber: $trackingNumber,
        );

        return $gateway->buildShipment($request);
    }
}

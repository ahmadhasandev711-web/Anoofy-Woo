<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

final class OrderConfirmedPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(OrderHandoverData $data): array
    {
        $lineItems = [];
        foreach ($data->items as $item) {
            $unitCents = $item->unitPriceCents ?? OrderHandoverItem::unitPriceCents($item->subtotalCents, $item->quantity);

            $lineItems[] = [
                'wc_product_id' => $item->productId,
                'wc_variation_id' => $item->variationId,
                'quantity' => $item->quantity,
                'name' => $item->name,
                'sku' => $item->sku,
                'price' => OrderHandoverItem::formatCents($unitCents),
                'total' => OrderHandoverItem::formatCents($item->totalCents),
            ];
        }

        return [
            'wc_order_id' => $data->orderId,
            'confirmed_at' => $data->confirmedAt,
            'confirmed_by_name' => $data->confirmedByName,
            'customer' => [
                'phone' => $data->customerPhone,
                'address_1' => $data->address1,
                'address_2' => $data->address2,
                'city' => $data->city,
                'state' => $data->state,
            ],
            'line_items' => $lineItems,
        ];
    }
}

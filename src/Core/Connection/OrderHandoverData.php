<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

final class OrderHandoverData
{
    /**
     * @param list<OrderHandoverItem> $items
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $confirmedAt,
        public readonly string $confirmedByName,
        public readonly ?string $customerPhone,
        public readonly ?string $address1,
        public readonly ?string $address2,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly array $items
    ) {
    }
}

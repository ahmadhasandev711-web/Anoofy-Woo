<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Returns;

/** Pure input item when opening a customer return against a delivered order. */
final class ReturnOpenLine
{
    public function __construct(
        public readonly int $orderItemId,
        public readonly int $orderedQty,
        public readonly int $alreadyReturnedQty,
        public readonly int $requestedQty,
    ) {
    }
}

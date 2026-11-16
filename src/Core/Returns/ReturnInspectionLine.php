<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Returns;

/** Pure per-line warehouse inspection input. */
final class ReturnInspectionLine
{
    public function __construct(
        public readonly int $orderItemId,
        public readonly int $qtyReturned,
        public readonly int $restockQty,
        public readonly int $damagedQty,
        public readonly string $note = '',
    ) {
    }
}

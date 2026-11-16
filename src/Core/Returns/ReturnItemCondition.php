<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Returns;

/** Per-item inspection outcome derived purely from returned, restockable, and damaged quantities. */
enum ReturnItemCondition: string
{
    case Uninspected = 'uninspected';
    case Restock = 'restock';
    case Damaged = 'damaged';
    case Partial = 'partial';

    public static function fromQuantities(int $qtyReturned, int $restockQty, int $damagedQty): self
    {
        if ($restockQty === 0 && $damagedQty === 0) {
            return self::Uninspected;
        }
        if ($restockQty > 0 && $damagedQty === 0 && $restockQty === $qtyReturned) {
            return self::Restock;
        }
        if ($damagedQty > 0 && $restockQty === 0 && $damagedQty === $qtyReturned) {
            return self::Damaged;
        }

        return self::Partial;
    }
}

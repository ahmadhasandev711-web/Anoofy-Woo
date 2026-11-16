<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

/** Pure helpers for line-item preparation completeness and item-edit detection. */
final class PreparationChecker
{
    /**
     * Returns true only when the order has at least one product line item and every line item ID
     * is present in `$preparedItemIds`.
     *
     * @param list<int> $orderItemIds
     * @param list<int> $preparedItemIds
     */
    public static function allPrepared(array $orderItemIds, array $preparedItemIds): bool
    {
        if ($orderItemIds === []) {
            return false;
        }

        $preparedLookup = array_flip($preparedItemIds);
        foreach ($orderItemIds as $itemId) {
            if (!isset($preparedLookup[$itemId])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Computes a deterministic SHA-256 hash over an order's line items to detect post-preparation edits.
     *
     * @param list<array{item_id: int, product_id: int, variation_id: int, qty: int|float}> $lines
     */
    public static function itemsHash(array $lines): string
    {
        $normalized = [];
        foreach ($lines as $line) {
            $normalized[] = [
                'item_id' => (int) $line['item_id'],
                'product_id' => (int) $line['product_id'],
                'variation_id' => (int) $line['variation_id'],
                'qty' => (float) $line['qty'],
            ];
        }

        usort(
            $normalized,
            static fn (array $a, array $b): int => $a['item_id'] <=> $b['item_id']
        );

        return hash('sha256', (string) json_encode($normalized, JSON_THROW_ON_ERROR));
    }
}

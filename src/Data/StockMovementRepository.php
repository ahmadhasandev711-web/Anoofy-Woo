<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Database\Schema;

/** Idempotent stock-movement ledger (`anoofy_stock_movements`) enforcing at most one movement per key. */
final class StockMovementRepository
{
    private function table(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'stock_movements');
    }

    /**
     * Inserts a movement using a UNIQUE idempotency_key. Returns true if newly inserted, false if already recorded.
     */
    public function recordOnce(
        int $orderId,
        int $returnId,
        int $returnItemId,
        int $productId,
        int $variationId,
        string $movementType,
        int $qty,
        string $idempotencyKey,
        int $userId,
        string $createdAt
    ): bool {
        global $wpdb;
        $rows = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table()} (order_id, return_id, return_item_id, product_id, variation_id, movement_type, qty, idempotency_key, created_by, created_at) VALUES (%d, %d, %d, %d, %d, %s, %d, %s, %d, %s)",
            $orderId,
            $returnId,
            $returnItemId,
            $productId,
            $variationId,
            $movementType,
            $qty,
            $idempotencyKey,
            $userId,
            $createdAt,
        ));

        return (int) $rows === 1;
    }

    /** @return list<array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_id, return_id, return_item_id, product_id, variation_id, movement_type, qty, idempotency_key, created_by, created_at FROM {$this->table()} WHERE order_id = %d ORDER BY id DESC",
            $orderId,
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'order_id' => (int) $row['order_id'],
                'return_id' => (int) $row['return_id'],
                'return_item_id' => (int) $row['return_item_id'],
                'product_id' => (int) $row['product_id'],
                'variation_id' => (int) $row['variation_id'],
                'movement_type' => (string) $row['movement_type'],
                'qty' => (int) $row['qty'],
                'idempotency_key' => (string) $row['idempotency_key'],
                'created_by' => (int) $row['created_by'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }
}

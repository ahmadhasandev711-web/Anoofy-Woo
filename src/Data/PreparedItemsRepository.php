<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Database\Schema;

/** Per-line item preparation tracking in `anoofy_order_prepared_items`. */
final class PreparedItemsRepository
{
    private function table(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'order_prepared_items');
    }

    /** @return list<int> */
    public function forOrder(int $orderId): array
    {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT item_id FROM {$this->table()} WHERE order_id = %d ORDER BY item_id ASC",
            $orderId
        ));

        return array_values(array_map('intval', is_array($rows) ? $rows : []));
    }

    /** @param list<int> $itemIds */
    public function setPreparedItems(int $orderId, array $itemIds, int $userId): void
    {
        global $wpdb;
        $table = $this->table();
        $now = gmdate('Y-m-d H:i:s');

        $unique = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));

        $wpdb->query('START TRANSACTION');
        try {
            $del = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE order_id = %d", $orderId));
            if ($del === false) {
                throw new \RuntimeException('Failed to clear prepared items: ' . $wpdb->last_error);
            }

            foreach ($unique as $itemId) {
                $ins = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table} (order_id, item_id, prepared_by, prepared_at) VALUES (%d, %d, %d, %s)",
                    $orderId,
                    $itemId,
                    $userId,
                    $now
                ));
                if ($ins === false) {
                    throw new \RuntimeException('Failed to insert prepared item: ' . $wpdb->last_error);
                }
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public function clearOrder(int $orderId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE order_id = %d", $orderId));
    }
}

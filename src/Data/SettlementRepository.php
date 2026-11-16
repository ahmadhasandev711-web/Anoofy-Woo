<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Database\Schema;

/** Persistence for COD settlement batches (`anoofy_cod_settlements` + `anoofy_cod_settlement_orders`). */
final class SettlementRepository
{
    private function batchesTable(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'cod_settlements');
    }

    private function ordersTable(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'cod_settlement_orders');
    }

    /**
     * Atomically creates a COD settlement batch, inserts item rows, and marks each order as settled in `order_ops`.
     *
     * @param list<array{order_id: int, tracking_number: string, amount_cents: int}> $items
     */
    public function createBatch(
        OpsRepository $opsRepo,
        string $courier,
        ?string $reference,
        array $items,
        int $userId,
        string $settledAt
    ): int {
        if ($items === []) {
            throw new \InvalidArgumentException('Settlement batch must contain at least one order.');
        }

        global $wpdb;
        $totalOrders = count($items);
        $totalCents = array_sum(array_column($items, 'amount_cents'));

        $wpdb->query('START TRANSACTION');
        try {
            $insBatch = $wpdb->insert($this->batchesTable(), [
                'courier' => $courier,
                'reference' => $reference,
                'total_orders' => $totalOrders,
                'total_cents' => $totalCents,
                'created_by' => $userId,
                'created_at' => $settledAt,
            ], ['%s', '%s', '%d', '%d', '%d', '%s']);

            if ($insBatch === false) {
                throw new \RuntimeException('Failed to create settlement batch: ' . $wpdb->last_error);
            }
            $settlementId = (int) $wpdb->insert_id;

            foreach ($items as $item) {
                $insItem = $wpdb->insert($this->ordersTable(), [
                    'settlement_id' => $settlementId,
                    'order_id' => $item['order_id'],
                    'tracking_number' => $item['tracking_number'],
                    'amount_cents' => $item['amount_cents'],
                    'created_at' => $settledAt,
                ], ['%d', '%d', '%s', '%d', '%s']);

                if ($insItem === false) {
                    throw new \RuntimeException('Order #' . $item['order_id'] . ' could not be added to settlement: ' . $wpdb->last_error);
                }

                if (!$opsRepo->markCodSettled($item['order_id'], $settlementId, $settledAt)) {
                    throw new \RuntimeException('Order #' . $item['order_id'] . ' is no longer eligible for COD settlement.');
                }
            }

            $wpdb->query('COMMIT');

            return $settlementId;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /** @return list<array{id: int, courier: string, reference: ?string, total_orders: int, total_cents: int, created_by: int, created_at: string}> */
    public function recent(int $limit = 20): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, courier, reference, total_orders, total_cents, created_by, created_at FROM {$this->batchesTable()} ORDER BY id DESC LIMIT %d",
            $limit,
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'courier' => (string) $row['courier'],
                'reference' => $row['reference'] !== null ? (string) $row['reference'] : null,
                'total_orders' => (int) $row['total_orders'],
                'total_cents' => (int) $row['total_cents'],
                'created_by' => (int) $row['created_by'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }
}

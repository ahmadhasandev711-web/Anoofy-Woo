<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Core\Returns\ReturnItemCondition;
use Anoofy\Woo\Core\Returns\ReturnSource;
use Anoofy\Woo\Core\Returns\ReturnStatus;
use Anoofy\Woo\Database\Schema;

/** Persistence for return/RTO requests and per-item inspection records (`anoofy_returns` + `anoofy_return_items`). */
final class ReturnRepository
{
    private function returnsTable(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'returns');
    }

    private function itemsTable(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'return_items');
    }

    /**
     * @param list<array{
     *     order_item_id: int,
     *     product_id: int,
     *     variation_id: int,
     *     sku: ?string,
     *     name: string,
     *     qty_returned: int
     * }> $items
     */
    public function createReturn(
        int $orderId,
        ReturnSource $source,
        ?string $reason,
        int $openedBy,
        array $items,
        string $nowUtc
    ): int {
        if ($items === []) {
            throw new \InvalidArgumentException('Return must contain at least one item.');
        }

        if ($source === ReturnSource::Rto) {
            $existing = $this->findRtoForOrder($orderId);
            if ($existing !== null) {
                return $existing;
            }
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $okHeader = $wpdb->insert($this->returnsTable(), [
                'order_id' => $orderId,
                'source' => $source->value,
                'status' => ReturnStatus::Open->value,
                'reason' => $reason !== '' ? $reason : null,
                'opened_by' => $openedBy,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ], ['%d', '%s', '%s', '%s', '%d', '%s', '%s']);

            if ($okHeader === false) {
                throw new \RuntimeException('Failed to insert return header: ' . $wpdb->last_error);
            }
            $returnId = (int) $wpdb->insert_id;

            foreach ($items as $item) {
                $okItem = $wpdb->insert($this->itemsTable(), [
                    'return_id' => $returnId,
                    'order_id' => $orderId,
                    'order_item_id' => $item['order_item_id'],
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'],
                    'sku' => $item['sku'],
                    'name' => mb_substr($item['name'], 0, 255),
                    'qty_returned' => $item['qty_returned'],
                    'restock_qty' => 0,
                    'damaged_qty' => 0,
                    'condition_status' => ReturnItemCondition::Uninspected->value,
                    'note' => null,
                ], ['%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s']);

                if ($okItem === false) {
                    throw new \RuntimeException('Failed to insert return item: ' . $wpdb->last_error);
                }
            }

            $wpdb->query('COMMIT');

            return $returnId;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public function findRtoForOrder(int $orderId): ?int
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->returnsTable()} WHERE order_id = %d AND source = %s ORDER BY id DESC LIMIT 1",
            $orderId,
            ReturnSource::Rto->value,
        ));

        return $id !== null ? (int) $id : null;
    }

    /** @return ?array<string, mixed> */
    public function find(int $returnId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, order_id, source, status, reason, opened_by, inspected_by, inspected_at, approved_by, approved_at, created_at, updated_at FROM {$this->returnsTable()} WHERE id = %d",
            $returnId,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrateReturn($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function findItems(int $returnId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, return_id, order_id, order_item_id, product_id, variation_id, sku, name, qty_returned, restock_qty, damaged_qty, condition_status, note FROM {$this->itemsTable()} WHERE return_id = %d ORDER BY id ASC",
            $returnId,
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'return_id' => (int) $row['return_id'],
                'order_id' => (int) $row['order_id'],
                'order_item_id' => (int) $row['order_item_id'],
                'product_id' => (int) $row['product_id'],
                'variation_id' => (int) $row['variation_id'],
                'sku' => $row['sku'] !== null ? (string) $row['sku'] : null,
                'name' => (string) $row['name'],
                'qty_returned' => (int) $row['qty_returned'],
                'restock_qty' => (int) $row['restock_qty'],
                'damaged_qty' => (int) $row['damaged_qty'],
                'condition_status' => ReturnItemCondition::from((string) $row['condition_status']),
                'note' => $row['note'] !== null ? (string) $row['note'] : null,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_id, source, status, reason, opened_by, inspected_by, inspected_at, approved_by, approved_at, created_at, updated_at FROM {$this->returnsTable()} WHERE order_id = %d ORDER BY id DESC",
            $orderId,
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $ret = $this->hydrateReturn($row);
            $ret['items'] = $this->findItems($ret['id']);
            $out[] = $ret;
        }

        return $out;
    }

    /** @return array<int, int> order_item_id => total qty already returned across returns for this order */
    public function alreadyReturnedQuantities(int $orderId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT order_item_id, COALESCE(SUM(qty_returned), 0) AS qty_sum FROM {$this->itemsTable()} WHERE order_id = %d GROUP BY order_item_id",
            $orderId,
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(int) $row['order_item_id']] = (int) $row['qty_sum'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function listRecent(?ReturnStatus $status = null, int $limit = 50): array
    {
        global $wpdb;
        if ($status !== null) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, order_id, source, status, reason, opened_by, inspected_by, inspected_at, approved_by, approved_at, created_at, updated_at FROM {$this->returnsTable()} WHERE status = %s ORDER BY id DESC LIMIT %d",
                $status->value,
                $limit,
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, order_id, source, status, reason, opened_by, inspected_by, inspected_at, approved_by, approved_at, created_at, updated_at FROM {$this->returnsTable()} ORDER BY id DESC LIMIT %d",
                $limit,
            ), ARRAY_A);
        }

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = $this->hydrateReturn($row);
        }

        return $out;
    }

    /** @return array<string, int> */
    public function countByStatus(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM {$this->returnsTable()} GROUP BY status",
            ARRAY_A,
        );
        $out = [];
        foreach (ReturnStatus::cases() as $s) {
            $out[$s->value] = 0;
        }
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['status']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * @param list<array{order_item_id: int, restock_qty: int, damaged_qty: int, condition: ReturnItemCondition, note: ?string}> $updates
     */
    public function saveInspection(int $returnId, int $userId, array $updates, string $nowUtc): bool
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->returnsTable()} SET status = %s, inspected_by = %d, inspected_at = %s, updated_at = %s WHERE id = %d AND status IN (%s, %s)",
                ReturnStatus::Inspected->value,
                $userId,
                $nowUtc,
                $nowUtc,
                $returnId,
                ReturnStatus::Open->value,
                ReturnStatus::Inspected->value,
            ));

            if ($updated === false || (int) $updated === 0) {
                // Re-check whether row wasn't updated only because values stayed identical when already Inspected.
                $current = $this->find($returnId);
                if ($current === null || $current['status'] === ReturnStatus::Approved) {
                    $wpdb->query('ROLLBACK');

                    return false;
                }
            }

            foreach ($updates as $u) {
                $ok = $wpdb->update(
                    $this->itemsTable(),
                    [
                        'restock_qty' => $u['restock_qty'],
                        'damaged_qty' => $u['damaged_qty'],
                        'condition_status' => $u['condition']->value,
                        'note' => $u['note'],
                    ],
                    [
                        'return_id' => $returnId,
                        'order_item_id' => $u['order_item_id'],
                    ],
                    ['%d', '%d', '%s', '%s'],
                    ['%d', '%d']
                );
                if ($ok === false) {
                    throw new \RuntimeException('Failed to update return item: ' . $wpdb->last_error);
                }
            }

            $wpdb->query('COMMIT');

            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public function compareAndSetApproved(int $returnId, int $userId, string $nowUtc): bool
    {
        global $wpdb;
        $rows = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->returnsTable()} SET status = %s, approved_by = %d, approved_at = %s, updated_at = %s WHERE id = %d AND status = %s",
            ReturnStatus::Approved->value,
            $userId,
            $nowUtc,
            $nowUtc,
            $returnId,
            ReturnStatus::Inspected->value,
        ));

        return (int) $rows === 1;
    }

    /**
     * Latest return status per order ID for list-table badges.
     *
     * @param list<int> $orderIds
     * @return array<int, ReturnStatus>
     */
    public function latestStatusForOrders(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT order_id, status FROM {$this->returnsTable()} WHERE order_id IN ({$placeholders}) ORDER BY id ASC",
            ...$ids
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $status = ReturnStatus::tryFrom((string) $row['status']);
            if ($status !== null) {
                $out[(int) $row['order_id']] = $status;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateReturn(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'order_id' => (int) $row['order_id'],
            'source' => ReturnSource::from((string) $row['source']),
            'status' => ReturnStatus::from((string) $row['status']),
            'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
            'opened_by' => (int) $row['opened_by'],
            'inspected_by' => $row['inspected_by'] !== null ? (int) $row['inspected_by'] : null,
            'inspected_at' => $row['inspected_at'] !== null ? (string) $row['inspected_at'] : null,
            'approved_by' => $row['approved_by'] !== null ? (int) $row['approved_by'] : null,
            'approved_at' => $row['approved_at'] !== null ? (string) $row['approved_at'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}

<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Core\Connection\EngazStage;
use Anoofy\Woo\Core\Order\OrderOps;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Database\Schema;

/** All SQL on `anoofy_order_ops`. Orders themselves are never queried here (WooCommerce CRUD owns them). */
final class OpsRepository
{
    private function table(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'order_ops');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public function find(int $orderId): ?OrderOps
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE order_id = %d", $orderId), ARRAY_A);

        return is_array($row) ? OrderOps::fromRow($row) : null;
    }

    /** Idempotent: creates the row as NEW if missing (safe under concurrent requests thanks to INSERT IGNORE). */
    public function ensure(int $orderId): OrderOps
    {
        global $wpdb;
        $now = self::now();
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table()} (order_id, ops_status, created_at, updated_at) VALUES (%d, %s, %s, %s)",
            $orderId,
            OrderStatus::New->value,
            $now,
            $now,
        ));

        return $this->find($orderId) ?? OrderOps::fromRow(['order_id' => $orderId, 'ops_status' => OrderStatus::New->value]);
    }

    /** Compare-and-set: moves the order only if it is still in `$from`. True when exactly one row changed. */
    public function compareAndSetStatus(int $orderId, OrderStatus $from, OrderStatus $to): bool
    {
        global $wpdb;
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET ops_status = %s, updated_at = %s WHERE order_id = %d AND ops_status = %s",
            $to->value,
            self::now(),
            $orderId,
            $from->value,
        ));

        return $changed === 1;
    }

    /** Sticky flag: once a call was answered it stays true. */
    public function markCallConfirmed(int $orderId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET has_logged_call = 1, updated_at = %s WHERE order_id = %d",
            self::now(),
            $orderId,
        ));
    }

    public function recordHandover(int $orderId, string $handoverAt): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET engaz_handover_at = %s, engaz_out_version = engaz_out_version + 1, updated_at = %s WHERE order_id = %d",
            $handoverAt,
            self::now(),
            $orderId,
        ));

        $ver = $wpdb->get_var($wpdb->prepare(
            "SELECT engaz_out_version FROM {$this->table()} WHERE order_id = %d",
            $orderId,
        ));

        return (int) $ver;
    }

    public function assign(int $orderId, ?int $userId): void
    {
        global $wpdb;
        if ($userId === null || $userId <= 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table()} SET assigned_to = NULL, updated_at = %s WHERE order_id = %d",
                self::now(),
                $orderId,
            ));

            return;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET assigned_to = %d, updated_at = %s WHERE order_id = %d",
            $userId,
            self::now(),
            $orderId,
        ));
    }

    /**
     * @param list<string>|null $statuses
     * @param list<int>|null    $orderIds
     * @return list<OrderOps>
     */
    public function search(?array $statuses, int|string|null $assigned, ?array $orderIds, int $limit, int $offset): array
    {
        global $wpdb;
        [$sql, $args] = OpsQuery::select($this->table(), $statuses, $assigned, $orderIds, $limit, $offset);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map(static fn (array $r): OrderOps => OrderOps::fromRow($r), is_array($rows) ? $rows : []);
    }

    /** @param list<string>|null $statuses @param list<int>|null $orderIds */
    public function count(?array $statuses, int|string|null $assigned, ?array $orderIds): int
    {
        global $wpdb;
        [$sql, $args] = OpsQuery::count($this->table(), $statuses, $assigned, $orderIds);

        return (int) $wpdb->get_var($args === [] ? $sql : $wpdb->prepare($sql, ...$args));
    }

    /** @param list<int>|null $orderIds @return array<string, int> ops_status value => count */
    public function statusCounts(int|string|null $assigned, ?array $orderIds): array
    {
        global $wpdb;
        [$sql, $args] = OpsQuery::statusCounts($this->table(), $assigned, $orderIds);
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['ops_status']] = (int) $row['c'];
        }

        return $out;
    }

    /**
     * Atomically updates EngazPRO stage details only if version is higher.
     * Returns true if a row was updated, false if stale (version was <= existing version).
     */
    public function applyEngazStageChange(
        int $orderId,
        EngazStage $stage,
        string $stageAt,
        int $version,
        ?string $branch,
        ?string $courier,
        ?string $tracking
    ): bool {
        global $wpdb;
        $res = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET 
                engaz_stage = %s,
                engaz_stage_at = %s,
                engaz_version = %d,
                engaz_branch = %s,
                engaz_courier = %s,
                engaz_tracking = %s,
                updated_at = %s
             WHERE order_id = %d AND (engaz_version IS NULL OR engaz_version < %d)",
            $stage->value,
            $stageAt,
            $version,
            $branch,
            $courier,
            $tracking,
            self::now(),
            $orderId,
            $version
        ));

        return $res > 0;
    }
}

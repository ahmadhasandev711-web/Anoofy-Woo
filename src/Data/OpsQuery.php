<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

/**
 * Builds the SQL for the Orders screen as [template, args] for `$wpdb->prepare()`.
 * Pure (no WordPress calls) so the placeholder/argument pairing is unit-tested. Every value goes through a
 * placeholder; only the table name (from the prefix helper) and fixed keywords are interpolated.
 */
final class OpsQuery
{
    public const ASSIGNED_UNASSIGNED = 'unassigned';

    /**
     * @param list<string>|null $statuses  OrderStatus values; null = any
     * @param int|string|null   $assigned  user id, self::ASSIGNED_UNASSIGNED, or null = any
     * @param list<int>|null    $orderIds  restrict to these orders (search result); [] = match nothing
     * @return array{0: string, 1: list<int|string>}
     */
    public static function select(string $table, ?array $statuses, int|string|null $assigned, ?array $orderIds, int $limit, int $offset): array
    {
        [$where, $args] = self::where($statuses, $assigned, $orderIds);
        $args[] = max(1, $limit);
        $args[] = max(0, $offset);

        return ["SELECT * FROM {$table}{$where} ORDER BY order_id DESC LIMIT %d OFFSET %d", $args];
    }

    /** @return array{0: string, 1: list<int|string>} */
    public static function count(string $table, ?array $statuses, int|string|null $assigned, ?array $orderIds): array
    {
        [$where, $args] = self::where($statuses, $assigned, $orderIds);

        return ["SELECT COUNT(*) FROM {$table}{$where}", $args];
    }

    /** Per-status counts for the tab badges (ignores the status filter itself). @return array{0: string, 1: list<int|string>} */
    public static function statusCounts(string $table, int|string|null $assigned, ?array $orderIds): array
    {
        [$where, $args] = self::where(null, $assigned, $orderIds);

        return ["SELECT ops_status, COUNT(*) AS c FROM {$table}{$where} GROUP BY ops_status", $args];
    }

    /** @return array{0: string, 1: list<int|string>} */
    private static function where(?array $statuses, int|string|null $assigned, ?array $orderIds): array
    {
        $parts = [];
        $args = [];

        if ($statuses !== null) {
            if ($statuses === []) {
                $parts[] = '1 = 0';
            } else {
                $parts[] = 'ops_status IN (' . implode(', ', array_fill(0, count($statuses), '%s')) . ')';
                array_push($args, ...array_values($statuses));
            }
        }

        if ($assigned === self::ASSIGNED_UNASSIGNED) {
            $parts[] = 'assigned_to IS NULL';
        } elseif (is_int($assigned) && $assigned > 0) {
            $parts[] = 'assigned_to = %d';
            $args[] = $assigned;
        }

        if ($orderIds !== null) {
            if ($orderIds === []) {
                $parts[] = '1 = 0';
            } else {
                $parts[] = 'order_id IN (' . implode(', ', array_fill(0, count($orderIds), '%d')) . ')';
                array_push($args, ...array_map('intval', array_values($orderIds)));
            }
        }

        return [$parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts), $args];
    }
}

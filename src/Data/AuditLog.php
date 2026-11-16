<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Database\Schema;

/** Append-only: who did what to which order, and why. There is deliberately no update or delete method. */
final class AuditLog
{
    private function table(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'audit_log');
    }

    /** @param array<string, mixed> $context */
    public function record(?int $orderId, int $userId, string $action, ?string $from = null, ?string $to = null, ?string $reason = null, array $context = []): void
    {
        global $wpdb;
        $wpdb->insert($this->table(), [
            'order_id' => $orderId,
            'user_id' => $userId,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'context' => $context === [] ? null : wp_json_encode($context),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']);
    }

    /** @return list<array{user_id: int, action: string, from_status: ?string, to_status: ?string, reason: ?string, created_at: string}> */
    public function forOrder(int $orderId, int $limit = 100): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, action, from_status, to_status, reason, created_at FROM {$this->table()} WHERE order_id = %d ORDER BY id DESC LIMIT %d",
            $orderId,
            $limit,
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[] = [
                'user_id' => (int) $r['user_id'],
                'action' => (string) $r['action'],
                'from_status' => $r['from_status'] !== null ? (string) $r['from_status'] : null,
                'to_status' => $r['to_status'] !== null ? (string) $r['to_status'] : null,
                'reason' => $r['reason'] !== null ? (string) $r['reason'] : null,
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $out;
    }

    public function hasActionAfter(int $orderId, string $action, string $afterUtc): bool
    {
        global $wpdb;
        $timestamp = strtotime($afterUtc);
        $afterSql = gmdate('Y-m-d H:i:s', $timestamp !== false ? $timestamp : 0);

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE order_id = %d AND action = %s AND created_at >= %s",
            $orderId,
            $action,
            $afterSql
        ));

        return ((int) $count) > 0;
    }

    /** @return list<array{id: int, order_id: ?int, user_id: int, action: string, from_status: ?string, to_status: ?string, reason: ?string, created_at: string}> */
    public function search(?string $action = null, ?int $userId = null, ?int $orderId = null, int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        [$where, $args] = self::buildWhere($action, $userId, $orderId);
        $args[] = max(1, $limit);
        $args[] = max(0, $offset);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_id, user_id, action, from_status, to_status, reason, created_at FROM {$this->table()}{$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            ...$args
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'order_id' => $r['order_id'] !== null ? (int) $r['order_id'] : null,
                'user_id' => (int) $r['user_id'],
                'action' => (string) $r['action'],
                'from_status' => $r['from_status'] !== null ? (string) $r['from_status'] : null,
                'to_status' => $r['to_status'] !== null ? (string) $r['to_status'] : null,
                'reason' => $r['reason'] !== null ? (string) $r['reason'] : null,
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $out;
    }

    public function count(?string $action = null, ?int $userId = null, ?int $orderId = null): int
    {
        global $wpdb;
        [$where, $args] = self::buildWhere($action, $userId, $orderId);
        $sql = "SELECT COUNT(*) FROM {$this->table()}{$where}";

        return (int) $wpdb->get_var($args === [] ? $sql : $wpdb->prepare($sql, ...$args));
    }

    /** @return list<string> */
    public function distinctActions(): array
    {
        global $wpdb;
        $col = $wpdb->get_col("SELECT DISTINCT action FROM {$this->table()} ORDER BY action ASC");

        return array_values(array_map('strval', is_array($col) ? $col : []));
    }

    /** @return array{0: string, 1: list<int|string>} */
    private static function buildWhere(?string $action, ?int $userId, ?int $orderId): array
    {
        $parts = [];
        $args = [];
        if ($action !== null && $action !== '') {
            $parts[] = 'action = %s';
            $args[] = $action;
        }
        if ($userId !== null && $userId > 0) {
            $parts[] = 'user_id = %d';
            $args[] = $userId;
        }
        if ($orderId !== null && $orderId > 0) {
            $parts[] = 'order_id = %d';
            $args[] = $orderId;
        }

        return [$parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts), $args];
    }
}

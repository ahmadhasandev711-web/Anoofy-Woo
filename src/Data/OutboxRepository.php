<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Core\Connection\OutboxStatus;
use Anoofy\Woo\Database\Schema;

final class OutboxRepository
{
    private function table(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'outbox');
    }

    public function create(
        string $eventId,
        string $eventType,
        ?int $wcOrderId,
        ?int $orderVersion,
        string $payload
    ): int {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        $inserted = $wpdb->insert($this->table(), [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'wc_order_id' => $wcOrderId,
            'order_version' => $orderVersion,
            'payload' => $payload,
            'status' => OutboxStatus::Pending->value,
            'attempts' => 0,
            'created_at' => $now,
        ], [
            '%s',
            '%s',
            $wcOrderId === null ? null : '%d',
            $orderVersion === null ? null : '%d',
            '%s',
            '%s',
            '%d',
            '%s',
        ]);

        if ($inserted === false || $wpdb->insert_id <= 0) {
            throw new \RuntimeException('Failed to insert outbox row: ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLatestForOrder(int $orderId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE wc_order_id = %d ORDER BY id DESC LIMIT 1",
            $orderId
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function updateStatus(
        int $id,
        OutboxStatus $status,
        ?string $nextRetryAt = null,
        ?string $lastError = null,
        bool $incrementAttempts = false,
        ?int $lastHttpStatus = null
    ): void {
        global $wpdb;

        $statusValue = $status->value;
        $fields = ['status' => $statusValue];
        $formats = ['%s'];

        if ($nextRetryAt !== null) {
            $fields['next_retry_at'] = $nextRetryAt;
            $formats[] = '%s';
        }

        if ($lastError !== null) {
            $fields['last_error'] = $lastError;
            $formats[] = '%s';
        }

        if ($lastHttpStatus !== null) {
            $fields['last_http_status'] = $lastHttpStatus;
            $formats[] = '%d';
        }

        if ($incrementAttempts) {
            $sql = "UPDATE {$this->table()} SET status = %s, attempts = attempts + 1";
            $params = [$statusValue];

            if ($nextRetryAt !== null) {
                $sql .= ", next_retry_at = %s";
                $params[] = $nextRetryAt;
            }

            if ($lastError !== null) {
                $sql .= ", last_error = %s";
                $params[] = $lastError;
            }

            if ($lastHttpStatus !== null) {
                $sql .= ", last_http_status = %d";
                $params[] = $lastHttpStatus;
            }

            $sql .= " WHERE id = %d";
            $params[] = $id;

            $wpdb->query($wpdb->prepare($sql, ...$params));
            return;
        }

        $wpdb->update($this->table(), $fields, ['id' => $id], $formats, ['%d']);
    }

    public function markSent(int $id, string $sentAt): void
    {
        global $wpdb;
        $wpdb->update($this->table(), [
            'status' => OutboxStatus::Sent->value,
            'sent_at' => $sentAt,
        ], ['id' => $id], ['%s', '%s'], ['%d']);
    }

    /**
     * Atomically claim a row for processing (Compare-and-Set).
     * Claims if:
     * - status is 'pending' AND (next_retry_at is null OR next_retry_at <= now)
     * - OR status is 'in_flight' AND next_retry_at <= now (lease expired after 10 minutes)
     * Sets lease 10 minutes in the future.
     */
    public function claimForProcessing(int $id): bool
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');
        $leaseUntil = gmdate('Y-m-d H:i:s', time() + 600);

        $sql = "UPDATE {$this->table()}
                SET status = %s, next_retry_at = %s
                WHERE id = %d
                  AND (
                    (status = %s AND (next_retry_at IS NULL OR next_retry_at <= %s))
                    OR (status = %s AND next_retry_at <= %s)
                  )";

        $wpdb->query($wpdb->prepare(
            $sql,
            OutboxStatus::InFlight->value,
            $leaseUntil,
            $id,
            OutboxStatus::Pending->value,
            $now,
            OutboxStatus::InFlight->value,
            $now
        ));

        return ((int) $wpdb->rows_affected) === 1;
    }

    public function resetForRetry(int $id): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET status = %s, next_retry_at = NULL WHERE id = %d",
            OutboxStatus::Pending->value,
            $id
        ));
    }

    /**
     * Resets failed rows that failed due to HTTP 401 back to pending.
     *
     * @return list<int>
     */
    public function resetFailedAuthRows(): array
    {
        global $wpdb;
        $table = $this->table();

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status = %s AND last_http_status = 401",
            OutboxStatus::Failed->value
        ));

        if (empty($ids)) {
            return [];
        }

        $idList = implode(',', array_map('intval', $ids));
        $wpdb->query("UPDATE {$table} SET status = '" . OutboxStatus::Pending->value . "', next_retry_at = NULL WHERE id IN ({$idList})");

        return array_map('intval', $ids);
    }

    /**
     * @return list<int>
     */
    public function findPendingIds(): array
    {
        global $wpdb;
        $table = $this->table();

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status = %s ORDER BY id ASC",
            OutboxStatus::Pending->value
        ));

        return array_map('intval', $ids);
    }
}

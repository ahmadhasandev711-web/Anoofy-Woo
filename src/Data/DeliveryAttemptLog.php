<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Core\Order\DeliveryOutcome;
use Anoofy\Woo\Database\Schema;

/** Append-only history of courier delivery attempts for an order. */
final class DeliveryAttemptLog
{
    private function table(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'delivery_attempts');
    }

    public function record(int $orderId, int $attemptNo, int $userId, DeliveryOutcome $outcome, ?string $reason): void
    {
        global $wpdb;
        $wpdb->insert($this->table(), [
            'order_id' => $orderId,
            'attempt_no' => $attemptNo,
            'user_id' => $userId,
            'outcome' => $outcome->value,
            'reason' => $reason,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], ['%d', '%d', '%d', '%s', '%s', '%s']);
    }

    /** @return list<array{attempt_no: int, user_id: int, outcome: DeliveryOutcome, reason: ?string, created_at: string}> newest first */
    public function forOrder(int $orderId, int $limit = 20): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT attempt_no, user_id, outcome, reason, created_at FROM {$this->table()} WHERE order_id = %d ORDER BY id DESC LIMIT %d",
            $orderId,
            $limit,
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $outcome = DeliveryOutcome::tryFrom((string) $row['outcome']);
            if ($outcome === null) {
                continue;
            }
            $out[] = [
                'attempt_no' => (int) $row['attempt_no'],
                'user_id' => (int) $row['user_id'],
                'outcome' => $outcome,
                'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }
}

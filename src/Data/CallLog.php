<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Database\Schema;

/** Append-only history of call attempts for an order. */
final class CallLog
{
    private function table(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'call_attempts');
    }

    public function add(int $orderId, int $userId, CallOutcome $outcome, ?string $note): void
    {
        global $wpdb;
        $wpdb->insert($this->table(), [
            'order_id' => $orderId,
            'user_id' => $userId,
            'outcome' => $outcome->value,
            'note' => $note,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], ['%d', '%d', '%s', '%s', '%s']);
    }

    /** @return list<array{user_id: int, outcome: CallOutcome, note: ?string, created_at: string}> newest first */
    public function forOrder(int $orderId, int $limit = 50): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, outcome, note, created_at FROM {$this->table()} WHERE order_id = %d ORDER BY id DESC LIMIT %d",
            $orderId,
            $limit,
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $outcome = CallOutcome::tryFrom((string) $row['outcome']);
            if ($outcome === null) {
                continue;
            }
            $out[] = [
                'user_id' => (int) $row['user_id'],
                'outcome' => $outcome,
                'note' => $row['note'] !== null ? (string) $row['note'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }
}

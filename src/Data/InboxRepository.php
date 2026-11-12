<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Core\Connection\InboxResult;
use Anoofy\Woo\Database\Schema;

final class InboxRepository
{
    private function table(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'inbox');
    }

    /**
     * Atomically claims an event in anoofy_inbox.
     * Returns true if newly inserted, false if already exists (duplicate).
     *
     * @throws \RuntimeException on database failure
     */
    public function claim(string $eventId, string $eventType, int $wcOrderId, string $receivedAt): bool
    {
        global $wpdb;

        $claimed = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table()} (event_id, event_type, wc_order_id, result, received_at) VALUES (%s, %s, %d, %s, %s)",
            $eventId,
            $eventType,
            $wcOrderId,
            InboxResult::Processed->value,
            $receivedAt
        ));

        if ($claimed === false) {
            throw new \RuntimeException('Failed to insert into anoofy_inbox: ' . $wpdb->last_error);
        }

        return $claimed > 0;
    }

    /**
     * Marks an existing inbox row as stale when the order version check fails.
     *
     * @throws \RuntimeException on database failure
     */
    public function markStale(string $eventId): void
    {
        global $wpdb;

        $res = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET result = %s WHERE event_id = %s",
            InboxResult::Stale->value,
            $eventId
        ));

        if ($res === false) {
            throw new \RuntimeException('Failed to update anoofy_inbox: ' . $wpdb->last_error);
        }
    }
}

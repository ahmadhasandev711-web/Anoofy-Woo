<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Core\Connection\EngazStage;
use Anoofy\Woo\Core\Connection\InboxResult;
use Anoofy\Woo\Core\Connection\StageChangedEvent;
use Anoofy\Woo\Database\Schema;

final class InboxRepository
{
    private function table(): string
    {
        global $wpdb;

        return Schema::table($wpdb->prefix, 'inbox');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Atomically claims the event in anoofy_inbox and applies the stage change in anoofy_order_ops.
     */
    public function claimAndApply(StageChangedEvent $event, OpsRepository $opsRepo): InboxResult
    {
        global $wpdb;

        $now = self::now();
        $table = $this->table();

        $wpdb->query('START TRANSACTION');

        try {
            // 1. Claim the event using INSERT IGNORE.
            // If rows_affected === 0, the event_id was already claimed/processed (duplicate).
            $claimed = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (event_id, event_type, wc_order_id, result, received_at) VALUES (%s, %s, %d, %s, %s)",
                $event->eventId,
                $event->eventType->value,
                $event->wcOrderId,
                InboxResult::Processed->value,
                $now
            ));

            if ($claimed === 0 || $claimed === false) {
                $wpdb->query('COMMIT');

                return InboxResult::Duplicate;
            }

            // 2. Apply update in order_ops with version check
            $updated = $opsRepo->applyEngazStageChange(
                $event->wcOrderId,
                $event->stage,
                $event->changedAt,
                $event->orderVersion,
                $event->branchName,
                $event->courierName,
                $event->trackingNumber
            );

            if (!$updated) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET result = %s WHERE event_id = %s",
                    InboxResult::Stale->value,
                    $event->eventId
                ));

                $wpdb->query('COMMIT');

                return InboxResult::Stale;
            }

            $wpdb->query('COMMIT');

            return InboxResult::Processed;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}

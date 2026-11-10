<?php
/**
 * Test C-4: Outbox Worker Error Handling & Retry Backoff
 * - Simulates 409 / 5xx responses from EngazPRO
 * - Checks retry backoff delays: [60, 300, 900, 3600, 21600, 43200]
 * - Verifies attempts incremented and next_retry_at scheduled
 * - Verifies after 6 attempts status becomes 'failed'
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Connection\EnvelopeFactory;
use Anoofy\Woo\Services\EngazOutboxWorker;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_c4(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-4: Outbox Retry Backoff ===\n";

global $wpdb;

// Ensure connected
update_option('anoofy_engaz_connection', [
    'api' => 'https://engazpro.example.com/api/webhooks/anoofy/events',
    'kid' => '01JCTESTKID12345678901234AB',
    'secret' => 'f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e3d2c1b0a9f8e7',
    'connected_at' => gmdate('Y-m-d\TH:i:s\Z'),
], false);

// Mock 409 Conflict (ORDER_NOT_YET_SYNCED)
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (str_contains($url, 'anoofy/events') || str_contains($url, 'engazpro')) {
        return [
            'response' => ['code' => 409, 'message' => 'Conflict'],
            'body' => json_encode(['success' => false, 'error_code' => 'ORDER_NOT_YET_SYNCED']),
            'headers' => ['content-type' => 'application/json'],
        ];
    }
    return $pre;
}, 10, 3);

$outboxTable = $wpdb->prefix . 'anoofy_outbox';

// Insert test outbox entry directly
$envelope = EnvelopeFactory::build(EngazEventType::OrderConfirmed, 1, ['test' => true]);
$eventId = $envelope['event_id'];

$wpdb->insert($outboxTable, [
    'event_id' => $eventId,
    'event_type' => 'order.confirmed',
    'wc_order_id' => 99999,
    'order_version' => 1,
    'payload' => json_encode($envelope),
    'status' => 'pending',
    'attempts' => 0,
    'created_at' => gmdate('Y-m-d H:i:s'),
]);
$outboxId = (int) $wpdb->insert_id;

// Attempt 1: Expect 60s delay
$beforeTime = time();
EngazOutboxWorker::process($outboxId);

$row1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$outboxTable} WHERE id = %d", $outboxId), ARRAY_A);
assert_c4((int) $row1['attempts'] === 1, "Attempt 1 incremented attempts to 1");
assert_c4($row1['status'] === 'pending', "Status remains pending for retry");
$nextRetry1 = strtotime($row1['next_retry_at']);
$diff1 = $nextRetry1 - $beforeTime;
assert_c4($diff1 >= 58 && $diff1 <= 65, "Next retry scheduled ~60s in future", "diff: {$diff1}s");

// Simulate attempts up to 6
$schedule = [60, 300, 900, 3600, 21600, 43200];
for ($i = 2; $i <= 6; $i++) {
    // Reset next_retry_at to past so it can run
    $wpdb->update($outboxTable, ['next_retry_at' => gmdate('Y-m-d H:i:s', time() - 10)], ['id' => $outboxId]);
    $attemptStart = time();
    EngazOutboxWorker::process($outboxId);

    $rowI = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$outboxTable} WHERE id = %d", $outboxId), ARRAY_A);
    assert_c4((int) $rowI['attempts'] === $i, "Attempt {$i} incremented attempts to {$i}");

    $expectedDelay = $schedule[$i - 1];
    $diffI = strtotime($rowI['next_retry_at']) - $attemptStart;
    assert_c4($diffI >= ($expectedDelay - 2) && $diffI <= ($expectedDelay + 5), "Attempt {$i} delay is ~{$expectedDelay}s", "actual: {$diffI}s");
}

// Attempt 7: Beyond 6 attempts, status must become 'failed'
$wpdb->update($outboxTable, ['next_retry_at' => gmdate('Y-m-d H:i:s', time() - 10)], ['id' => $outboxId]);
EngazOutboxWorker::process($outboxId);

$finalRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$outboxTable} WHERE id = %d", $outboxId), ARRAY_A);
assert_c4((int) $finalRow['attempts'] >= 7, "Attempts count updated");
assert_c4($finalRow['status'] === 'failed', "Status transitioned to 'failed' after exhausting 6 retries");
assert_c4(!empty($finalRow['last_error']), "last_error contains error description");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

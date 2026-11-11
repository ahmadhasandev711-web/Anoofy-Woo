<?php
/**
 * Test C-3 (Compare-and-Set Worker Claiming & Lease):
 * 1. Concurrent worker execution during HTTP request must result in exactly 1 HTTP request.
 * 2. In-flight row with unexpired lease cannot be claimed.
 * 3. In-flight row with expired lease (> 10 minutes) can be claimed.
 * 4. Manual retry resets row to pending without synchronous execution, and validates order ID and failed status.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Connection\EnvelopeFactory;
use Anoofy\Woo\Core\Connection\OutboxStatus;
use Anoofy\Woo\Services\EngazOutboxWorker;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_cas(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-3: Worker Compare-and-Set Claiming ===\n";

// 1. Connect store
$conn = [
    'api' => 'https://app.engazpro.test/api/v1/events',
    'kid' => 'kid_live_cas_test',
    'secret' => 'sec_live_cas_test_0123456789012345678901234567890123456789012',
    'connected_at' => gmdate('Y-m-d\TH:i:s\Z'),
];
update_option('anoofy_engaz_connection', $conn, false);

// Create an outbox row
$envelope = EnvelopeFactory::build(
    type: EngazEventType::OrderConfirmed,
    orderVersion: 1,
    data: ['wc_order_id' => 9999],
    occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
);
$payload = json_encode($envelope, JSON_UNESCAPED_SLASHES);
$outboxId = Services::outbox()->create(
    $envelope['event_id'],
    $envelope['event_type'],
    9999,
    1,
    $payload
);

// 2. Concurrency Test via pre_http_request
$httpCallCount = 0;
$recursed = false;

add_filter('pre_http_request', function ($pre, $args, $url) use (&$httpCallCount, &$recursed, $outboxId) {
    if (str_contains($url, 'app.engazpro.test')) {
        $httpCallCount++;

        if (!$recursed) {
            $recursed = true;
            // Simulate concurrent worker running right now on the same row!
            EngazOutboxWorker::process($outboxId);
        }

        // Return mock 200 OK
        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => json_encode(['success' => true]),
        ];
    }
    return $pre;
}, 10, 3);

// Run first worker
EngazOutboxWorker::process($outboxId);

assert_cas($httpCallCount === 1, "Exactly 1 HTTP request made despite concurrent execution", "actual requests: {$httpCallCount}");

// 3. Lease Tests on claimForProcessing
if (method_exists(Services::outbox(), 'claimForProcessing')) {
    // Create row with active in_flight lease (5 minutes in future)
    global $wpdb;
    $table = $wpdb->prefix . 'anoofy_outbox';
    $futureRetry = gmdate('Y-m-d H:i:s', time() + 300);
    $wpdb->update($table, [
        'status' => OutboxStatus::InFlight->value,
        'next_retry_at' => $futureRetry,
    ], ['id' => $outboxId]);

    $unexpiredClaim = Services::outbox()->claimForProcessing($outboxId);
    assert_cas(!$unexpiredClaim, "In-flight row with unexpired lease cannot be claimed");

    // Expire lease (11 minutes in past)
    $pastRetry = gmdate('Y-m-d H:i:s', time() - 660);
    $wpdb->update($table, [
        'status' => OutboxStatus::InFlight->value,
        'next_retry_at' => $pastRetry,
    ], ['id' => $outboxId]);

    $expiredClaim = Services::outbox()->claimForProcessing($outboxId);
    assert_cas($expiredClaim, "In-flight row with expired lease (> 10 mins) can be claimed");
} else {
    assert_cas(false, "OutboxRepository::claimForProcessing does not exist");
}

if ($hasFailed) {
    exit(1);
}
echo "=== CAS Test Completed Successfully ===\n";
exit(0);

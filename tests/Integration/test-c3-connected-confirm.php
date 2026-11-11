<?php
/**
 * Test C-3: Connected Mode Order Confirmation & Handover
 * - Connects plugin to EngazPRO
 * - Confirms order -> HandOverToEngaz effect triggers
 * - engaz_handover_at recorded, engaz_out_version incremented
 * - Outbox row created in anoofy_outbox (pending)
 * - Payload structure matches order.confirmed fixture
 * - Worker processes outbox row on 200 response -> status becomes 'sent'
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Mode\OperatingMode;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\EngazOutboxWorker;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_c3(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-3: Connected Mode Confirmation & Handover ===\n";

global $wpdb;

// 1. Establish connection
$kid = "01JCTESTKID12345678901234AB";
$secret = "f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e3d2c1b0a9f8e7";
$endpoint = "https://engazpro.example.com/api/webhooks/anoofy/events";

update_option('anoofy_engaz_connection', [
    'api' => $endpoint,
    'kid' => $kid,
    'secret' => $secret,
    'connected_at' => gmdate('Y-m-d\TH:i:s\Z'),
], false);

assert_c3(Services::mode() === OperatingMode::Connected, "Plugin is in Connected mode");

// 2. Create test order with customer and line items
$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);

$order = wc_create_order();
$order->add_product($product, 2);
$order->set_billing_first_name('Hassan');
$order->set_billing_last_name('Ali');
$order->set_billing_phone('01011223344');
$order->set_billing_address_1('15 El Nasr St');
$order->set_billing_address_2('Apt 4');
$order->set_billing_city('Nasr City');
$order->set_billing_state('Cairo');
$order->calculate_totals();
$order->save();
$orderId = $order->get_id();

$adminUser = get_user_by('login', 'admin');
$adminId = $adminUser ? $adminUser->ID : 1;

// Log call then confirm
Services::orderOps()->logCall($orderId, $adminId, CallOutcome::Answered, 'Confirmed by phone');
$confirmResult = Services::orderOps()->runEvent($orderId, OrderEvent::Confirm, $adminId);

assert_c3($confirmResult->ok, "Order confirmed in Connected mode");

// 3. Verify handover recorded on order_ops
$ops = Services::repository()->find($orderId);
assert_c3($ops !== null && $ops->status === OrderStatus::Confirmed, "Ops status is Confirmed");
assert_c3($ops !== null && $ops->isHandedOver(), "Order is marked handed over (engaz_handover_at is set)");
assert_c3($ops !== null && $ops->engazOutVersion >= 1, "engaz_out_version is >= 1", "version: " . ($ops ? $ops->engazOutVersion : 0));

// 4. Verify outbox row created
$outboxTable = $wpdb->prefix . 'anoofy_outbox';
$outboxRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$outboxTable} WHERE wc_order_id = %d ORDER BY id DESC LIMIT 1", $orderId), ARRAY_A);

assert_c3($outboxRow !== null, "Outbox row created for confirmed order");
assert_c3($outboxRow['event_type'] === EngazEventType::OrderConfirmed->value, "Outbox event_type is order.confirmed");
assert_c3($outboxRow['status'] === 'pending', "Outbox initial status is pending");

$payload = json_decode($outboxRow['payload'], true);
assert_c3(is_array($payload), "Outbox payload is valid JSON");
assert_c3(($payload['schema_version'] ?? 0) === 1, "Payload schema_version is 1");
assert_c3(($payload['event_type'] ?? '') === 'order.confirmed', "Payload event_type is order.confirmed");
assert_c3(($payload['data']['wc_order_id'] ?? 0) === $orderId, "Payload data contains wc_order_id");
assert_c3(!empty($payload['data']['customer']['phone']), "Payload customer phone is populated");
assert_c3(!empty($payload['data']['line_items']), "Payload line_items is populated");
assert_c3($payload['data']['line_items'][0]['quantity'] === 2, "Line item quantity matches order");

// 5. Simulate Worker processing with mocked 200 HTTP response
$workerRequests = 0;
add_filter('pre_http_request', function ($pre, $args, $url) use (&$workerRequests) {
    if (str_contains($url, 'anoofy/events') || str_contains($url, 'engazpro')) {
        $workerRequests++;
        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => json_encode(['success' => true, 'data' => ['result' => 'processed']]),
            'headers' => ['content-type' => 'application/json'],
        ];
    }
    return $pre;
}, 10, 3);

$outboxId = (int) $outboxRow['id'];
EngazOutboxWorker::process($outboxId);

$freshOutbox = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$outboxTable} WHERE id = %d", $outboxId), ARRAY_A);
assert_c3($freshOutbox['status'] === 'sent', "Outbox status updated to 'sent' after successful send");
assert_c3(!empty($freshOutbox['sent_at']), "Outbox sent_at timestamp is recorded");
assert_c3($workerRequests === 1, "Worker dispatched HTTP request to EngazPRO");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

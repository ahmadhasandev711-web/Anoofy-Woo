<?php
/**
 * Test C-7: Inbound Callback POST /wp-json/anoofy/v1/callback
 * - Strict check order: not connected -> 401, signature -> 401, payload -> 422, ignored (not handed over) -> 200 without inbox, claim/apply
 * - Responses: processed, duplicate, stale, ignored, 401, 422
 * - WooCommerce order status does NOT change after processed
 * - Malformed JSON with wrong signature -> 401
 * - Ignored event -> handover order -> re-send same event_id -> processed
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Connection\EngazStage;
use Anoofy\Woo\Core\Mode\OperatingMode;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_c7(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-7: Inbound Callback order.stage_changed ===\n";

global $wpdb;

// Ensure Connected mode
$GLOBALS['testKid'] = 'kid_live_inbound_test_1234567890';
$GLOBALS['testSecret'] = 'secret_live_inbound_abcdef0123456789abcdef0123456789abcdef01234567';
update_option('anoofy_engaz_connection', [
    'api' => 'https://app.engazpro.test/api/v1/events',
    'kid' => $GLOBALS['testKid'],
    'secret' => $GLOBALS['testSecret'],
    'connected_at' => gmdate('Y-m-d H:i:s'),
], false);

// Helper to make signed REST request
function make_inbound_request(string $rawBody, ?string $kid = null, ?int $ts = null, ?string $sig = null): WP_REST_Response {
    $kid ??= $GLOBALS['testKid'];
    $ts ??= time();
    $sig ??= hash_hmac('sha256', $ts . '.' . $rawBody, $GLOBALS['testSecret']);

    $request = new WP_REST_Request('POST', '/anoofy/v1/callback');
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-Anoofy-Key-Id', $kid);
    $request->set_header('X-Anoofy-Timestamp', (string) $ts);
    $request->set_header('X-Anoofy-Signature', $sig);
    $request->set_body($rawBody);

    return rest_do_request($request);
}

// 1. Create a handed-over test order
$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);
$order = wc_create_order();
$order->add_product($product, 1);
$order->set_billing_first_name('Tarek');
$order->set_billing_last_name('Nabil');
$order->calculate_totals();
$order->save();
$orderId = $order->get_id();

$opsRepo = Services::repository();
$ops = $opsRepo->ensure($orderId);
$opsRepo->compareAndSetStatus($orderId, $ops->status, \Anoofy\Woo\Core\Order\OrderStatus::Confirmed);
$opsRepo->recordHandover($orderId, gmdate('Y-m-d H:i:s'));

$wooStatusBefore = $order->get_status();

// Case A: Valid processed request
$eventIdA = wp_generate_uuid4();
$bodyA = json_encode([
    'schema_version' => 1,
    'event_id' => $eventIdA,
    'event_type' => 'order.stage_changed',
    'occurred_at' => gmdate('c'),
    'order_version' => 1,
    'data' => [
        'wc_order_id' => $orderId,
        'stage' => 'PREPARING',
        'previous_stage' => 'CONFIRMED',
        'changed_at' => gmdate('c'),
        'branch_name' => 'فرع الجيزة',
        'courier_name' => 'بوسطة',
        'tracking_number' => 'BOSTA-112233',
    ],
]);

$responseA = make_inbound_request($bodyA);
assert_c7($responseA->get_status() === 200, "Valid request returned 200", "actual: " . $responseA->get_status());
$dataA = $responseA->get_data();
assert_c7(($dataA['data']['result'] ?? '') === 'processed', "Result is 'processed'", "actual: " . ($dataA['data']['result'] ?? ''));

// Verify data saved in DB
$freshOpsA = $opsRepo->find($orderId);
assert_c7($freshOpsA->engazStage === EngazStage::PREPARING, "engaz_stage updated to PREPARING");
assert_c7($freshOpsA->engazBranch === 'فرع الجيزة', "engaz_branch updated");
assert_c7($freshOpsA->engazCourier === 'بوسطة', "engaz_courier updated");
assert_c7($freshOpsA->engazTracking === 'BOSTA-112233', "engaz_tracking updated");
assert_c7($freshOpsA->engazVersion === 1, "engaz_version updated to 1");

// Verify WooCommerce order status did NOT change
$freshOrderA = wc_get_order($orderId);
assert_c7($freshOrderA->get_status() === $wooStatusBefore, "WooCommerce order status remained unchanged");

// Case B: Duplicate request (same event_id)
$responseB = make_inbound_request($bodyA);
assert_c7($responseB->get_status() === 200, "Duplicate request returned 200", "actual: " . $responseB->get_status());
$dataB = $responseB->get_data();
assert_c7(($dataB['data']['result'] ?? '') === 'duplicate', "Result is 'duplicate'", "actual: " . ($dataB['data']['result'] ?? ''));

// Case C: Stale request (new event_id, but version <= existing version 1)
$eventIdC = wp_generate_uuid4();
$bodyC = json_encode([
    'schema_version' => 1,
    'event_id' => $eventIdC,
    'event_type' => 'order.stage_changed',
    'occurred_at' => gmdate('c'),
    'order_version' => 1, // existing is 1, so 1 is stale
    'data' => [
        'wc_order_id' => $orderId,
        'stage' => 'DISPATCHED',
        'previous_stage' => 'PREPARING',
        'changed_at' => gmdate('c'),
        'branch_name' => 'فرع الجيزة',
        'courier_name' => 'بوسطة',
        'tracking_number' => 'BOSTA-112233',
    ],
]);
$responseC = make_inbound_request($bodyC);
assert_c7($responseC->get_status() === 200, "Stale request returned 200", "actual: " . $responseC->get_status());
$dataC = $responseC->get_data();
assert_c7(($dataC['data']['result'] ?? '') === 'stale', "Result is 'stale'", "actual: " . ($dataC['data']['result'] ?? ''));

// Verify DB stage was NOT overwritten by stale event
$freshOpsC = $opsRepo->find($orderId);
assert_c7($freshOpsC->engazStage === EngazStage::PREPARING, "engaz_stage was NOT overwritten by stale event");

// Case D: Higher version updates successfully
$eventIdD = wp_generate_uuid4();
$bodyD = json_encode([
    'schema_version' => 1,
    'event_id' => $eventIdD,
    'event_type' => 'order.stage_changed',
    'occurred_at' => gmdate('c'),
    'order_version' => 2, // version 2 > 1
    'data' => [
        'wc_order_id' => $orderId,
        'stage' => 'SHIPPED_VIA_COURIER',
        'previous_stage' => 'PREPARING',
        'changed_at' => gmdate('c'),
        'branch_name' => 'فرع الجيزة',
        'courier_name' => 'أرامكس',
        'tracking_number' => 'ARAMEX-998877',
    ],
]);
$responseD = make_inbound_request($bodyD);
assert_c7($responseD->get_status() === 200, "Higher version request returned 200");
$dataD = $responseD->get_data();
assert_c7(($dataD['data']['result'] ?? '') === 'processed', "Result is 'processed' for version 2");
$freshOpsD = $opsRepo->find($orderId);
assert_c7($freshOpsD->engazStage === EngazStage::SHIPPED_VIA_COURIER, "engaz_stage updated to SHIPPED_VIA_COURIER");
assert_c7($freshOpsD->engazVersion === 2, "engaz_version updated to 2");

// Case E: Wrong signature -> 401
$responseE = make_inbound_request($bodyD, sig: 'wrong_signature_12345');
assert_c7($responseE->get_status() === 401, "Wrong signature returns 401", "actual: " . $responseE->get_status());
$dataE = $responseE->get_data();
assert_c7(($dataE['error_code'] ?? '') === 'UNAUTHORIZED', "Error code is UNAUTHORIZED");

// Case F: Old timestamp (> 300s) -> 401
$responseF = make_inbound_request($bodyD, ts: time() - 350);
assert_c7($responseF->get_status() === 401, "Old timestamp returns 401", "actual: " . $responseF->get_status());

// Case G: Malformed JSON with wrong signature -> 401 (signature check happens BEFORE JSON decode!)
$malformedBody = '{"not valid json...';
$responseG = make_inbound_request($malformedBody, sig: 'invalid_sig');
assert_c7($responseG->get_status() === 401, "Malformed JSON with wrong signature returns 401 (not 422)", "actual: " . $responseG->get_status());

// Case H: Malformed JSON with valid signature -> 422
$responseH = make_inbound_request($malformedBody);
assert_c7($responseH->get_status() === 422, "Malformed JSON with valid signature returns 422", "actual: " . $responseH->get_status());

// Case I: Unknown stage with valid signature -> 422
$bodyI = json_encode([
    'schema_version' => 1,
    'event_id' => '11111111-1111-4111-8111-111111111111',
    'event_type' => 'order.stage_changed',
    'occurred_at' => gmdate('c'),
    'order_version' => 3,
    'data' => [
        'wc_order_id' => $orderId,
        'stage' => 'NON_EXISTENT_STAGE',
        'previous_stage' => null,
        'changed_at' => gmdate('c'),
    ],
]);
$responseI = make_inbound_request($bodyI);
assert_c7($responseI->get_status() === 422, "Unknown stage returns 422", "actual: " . $responseI->get_status());

// Case J: Order not handed over -> ignored (200) AND NOT saved in anoofy_inbox
$orderNotHandedOver = wc_create_order();
$orderNotHandedOver->add_product($product, 1);
$orderNotHandedOver->save();
$unhandedId = $orderNotHandedOver->get_id();
$opsRepo->ensure($unhandedId); // not handed over!

$eventIdJ = wp_generate_uuid4();
$bodyJ = json_encode([
    'schema_version' => 1,
    'event_id' => $eventIdJ,
    'event_type' => 'order.stage_changed',
    'occurred_at' => gmdate('c'),
    'order_version' => 1,
    'data' => [
        'wc_order_id' => $unhandedId,
        'stage' => 'CONFIRMED',
        'previous_stage' => 'PENDING_CONFIRMATION',
        'changed_at' => gmdate('c'),
    ],
]);
$responseJ = make_inbound_request($bodyJ);
assert_c7($responseJ->get_status() === 200, "Unhanded order event returns 200");
$dataJ = $responseJ->get_data();
assert_c7(($dataJ['data']['result'] ?? '') === 'ignored', "Result is 'ignored' for unhanded order");

// Verify event was NOT saved in anoofy_inbox
$inboxRowJ = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}anoofy_inbox WHERE event_id = %s",
    $eventIdJ
));
assert_c7((int)$inboxRowJ === 0, "Ignored event was NOT recorded in anoofy_inbox", "count: {$inboxRowJ}");

// Hand over the order now, and re-send the exact same event_id: must be processed (not duplicate)!
$opsRepo->recordHandover($unhandedId, gmdate('Y-m-d H:i:s'));
$responseJ2 = make_inbound_request($bodyJ);
assert_c7($responseJ2->get_status() === 200, "Re-sent event after handover returns 200");
$dataJ2 = $responseJ2->get_data();
assert_c7(($dataJ2['data']['result'] ?? '') === 'processed', "Re-sent event was 'processed' (not duplicate)", "actual: " . ($dataJ2['data']['result'] ?? ''));

// Case K: Store not connected -> 401
delete_option('anoofy_engaz_connection');
$responseK = make_inbound_request($bodyD);
assert_c7($responseK->get_status() === 401, "Disconnected store returns 401", "actual: " . $responseK->get_status());
$dataK = $responseK->get_data();
assert_c7(($dataK['error_code'] ?? '') === 'UNAUTHORIZED', "Disconnected error code is UNAUTHORIZED");

// Restore connection option for other tests
update_option('anoofy_engaz_connection', [
    'api' => 'https://app.engazpro.test/api/v1/events',
    'kid' => $GLOBALS['testKid'],
    'secret' => $GLOBALS['testSecret'],
    'connected_at' => gmdate('Y-m-d H:i:s'),
], false);

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

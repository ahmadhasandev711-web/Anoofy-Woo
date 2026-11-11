<?php
/**
 * Test C-2 (Atomic Handover):
 * 1. Outbox insert failure rolls back recordHandover atomically.
 * 2. Order remains NOT handed over, and order.effect_failed audit row is recorded.
 * 3. Retry Handover button appears ONLY when:
 *    - Mode is Connected
 *    - OpsStatus is Confirmed
 *    - Order is NOT handed over
 *    - order.effect_failed audit entry exists after connected_at
 * 4. Pre-connection orders (confirmed before connection) NEVER show retry handover button and action returns FORBIDDEN.
 * 5. Valid retry handover succeeds and hands over the order.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Admin\OrderBox;
use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Mode\OperatingMode;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\ActionResult;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_atomic(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-2: Atomic Handover & Retry ===\n";

// Setup admin user
$adminUser = get_user_by('login', 'admin');
$adminId = $adminUser ? (int) $adminUser->ID : 1;
wp_set_current_user($adminId);

// 1. Connect the store
$connectedAt = gmdate('Y-m-d\TH:i:s\Z');
$conn = [
    'api' => 'https://app.engazpro.test/api/v1/events',
    'kid' => 'kid_live_atomic_test',
    'secret' => 'sec_live_atomic_test_0123456789012345678901234567890123456789012',
    'connected_at' => $connectedAt,
];
update_option('anoofy_engaz_connection', $conn, false);
assert_atomic(Services::mode() === OperatingMode::Connected, "Store is Connected");

// 2. Create an order
$order = wc_create_order();
$order->set_payment_method('cod');
$order->set_billing_first_name('Atomic');
$order->set_billing_last_name('Tester');
$order->set_billing_phone('01011112222');
$order->set_status('processing');
$order->save();
$orderId = $order->get_id();

Services::orderOps()->logCall($orderId, $adminId, CallOutcome::Answered, 'Call answered');

// 3. Force wp_anoofy_outbox insert to fail via query filter
$forceFailOutbox = true;
add_filter('query', function ($query) use (&$forceFailOutbox) {
    if ($forceFailOutbox && stripos($query, 'INSERT INTO') !== false && stripos($query, 'anoofy_outbox') !== false) {
        // Intentionally break the query to cause an insert failure
        return 'INSERT INTO nonexistent_table_anoofy_fail VALUES (1)';
    }
    return $query;
});

// Confirm the order -> should attempt handover and fail atomically
$confirmRes = Services::orderOps()->runEvent($orderId, OrderEvent::Confirm, $adminId);

$forceFailOutbox = false; // remove query failure

// 4. Verify atomic rollback: order confirmed in Woo/ops, but NOT handed over
$ops = Services::repository()->find($orderId);
assert_atomic($ops !== null && $ops->status === OrderStatus::Confirmed, "Order status is Confirmed");
assert_atomic($ops !== null && !$ops->isHandedOver(), "Order is NOT handed over after outbox insert failure");
assert_atomic($ops !== null && $ops->engazHandoverAt === null, "engaz_handover_at remains null");

// Verify order.effect_failed audit entry
global $wpdb;
$auditTable = $wpdb->prefix . 'anoofy_audit_log';
$auditRows = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$auditTable} WHERE order_id = %d AND action = 'order.effect_failed'",
    $orderId
), ARRAY_A);
assert_atomic(count($auditRows) > 0, "order.effect_failed was recorded in audit log");

// 5. Test OrderBox rendering has retry handover button
ob_start();
OrderBox::render($order);
$orderBoxHtml = ob_get_clean();

$hasRetryBtn = str_contains($orderBoxHtml, 'anoofy_retry_handover') || str_contains($orderBoxHtml, 'إعادة التسليم لـ EngazPRO');
assert_atomic($hasRetryBtn, "OrderBox renders 'إعادة التسليم لـ EngazPRO' button when handover failed");

// 6. Test C-6 pre-connection order (confirmed in Standalone)
$preOrder = wc_create_order();
$preOrder->set_payment_method('cod');
$preOrder->set_billing_first_name('Pre');
$preOrder->set_billing_last_name('Conn');
$preOrder->set_billing_phone('01099998888');
$preOrder->set_status('processing');
$preOrder->save();
$preOrderId = $preOrder->get_id();

// Simulate pre-connection confirmation by setting handoverAt to null and no effect_failed audit after connected_at
Services::orderOps()->logCall($preOrderId, $adminId, CallOutcome::Answered, 'Pre call');
$preOps = Services::repository()->ensure($preOrderId);
Services::repository()->compareAndSetStatus($preOrderId, OrderStatus::New, OrderStatus::Confirmed);

ob_start();
OrderBox::render($preOrder);
$preHtml = ob_get_clean();
$preHasRetryBtn = str_contains($preHtml, 'anoofy_retry_handover');
assert_atomic(!$preHasRetryBtn, "Pre-connection order does NOT render retry handover button");

// Calling retryHandover on pre-connection order should return FORBIDDEN
$preRetryRes = Services::orderOps()->retryHandover($preOrderId, $adminId);
assert_atomic(!$preRetryRes->ok && $preRetryRes->code === ActionResult::FORBIDDEN, "retryHandover on pre-connection order returns FORBIDDEN");

// 7. Perform valid retry handover on the failed order
$retryRes = Services::orderOps()->retryHandover($orderId, $adminId);
assert_atomic($retryRes->ok, "Valid retryHandover succeeds");

$postOps = Services::repository()->find($orderId);
assert_atomic($postOps !== null && $postOps->isHandedOver(), "Order is now handed over after retryHandover");

if ($hasFailed) {
    exit(1);
}
echo "=== Atomic Handover Test Completed Successfully ===\n";
exit(0);

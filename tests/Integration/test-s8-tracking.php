<?php
/**
 * Spike S-8: Order tracking hooks behavior.
 * (A) Real COD order -> exactly 1 row in anoofy_order_ops with NEW.
 * (B) checkout-draft order -> 0 rows; changes to processing -> 1 row with NEW.
 * (C) pending admin order -> 0 rows; status changes -> 1 row with NEW.
 * (D) Same hooks fired multiple times -> still exactly 1 row.
 */
defined('ABSPATH') || exit;

global $wpdb;
$table = $wpdb->prefix . 'anoofy_order_ops';
$hasFailed = false;

function assert_s8(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Spike S-8: Order Tracking Hooks ===\n";

$countRows = static function (int $orderId) use ($wpdb, $table): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE order_id = %d", $orderId));
};

$getStatus = static function (int $orderId) use ($wpdb, $table): ?string {
    return $wpdb->get_var($wpdb->prepare("SELECT ops_status FROM {$table} WHERE order_id = %d", $orderId));
};

// --- Case (A): Real COD order ---
$orderA = wc_create_order();
$orderA->set_payment_method('cod');
$orderA->set_status('processing');
$orderA->save();
$idA = $orderA->get_id();

$countA = $countRows($idA);
$statusA = $getStatus($idA);
assert_s8($countA === 1, "S-8(A): Real COD order has exactly 1 row in anoofy_order_ops", "count: {$countA}");
assert_s8($statusA === 'NEW', "S-8(A): Operational status is NEW", "status: {$statusA}");

// --- Case (B): checkout-draft order ---
$orderB = wc_create_order();
$orderB->set_status('checkout-draft');
$orderB->save();
$idB = $orderB->get_id();

$countB1 = $countRows($idB);
assert_s8($countB1 === 0, "S-8(B): checkout-draft has 0 rows in anoofy_order_ops", "count: {$countB1}");

$orderB->update_status('processing');
$countB2 = $countRows($idB);
$statusB2 = $getStatus($idB);
assert_s8($countB2 === 1, "S-8(B): After moving to processing, checkout-draft has 1 row", "count: {$countB2}");
assert_s8($statusB2 === 'NEW', "S-8(B): Operational status is NEW", "status: {$statusB2}");

// --- Case (C): pending admin order ---
$orderC = wc_create_order();
$orderC->set_status('pending');
$orderC->save();
$idC = $orderC->get_id();

$countC1 = $countRows($idC);
assert_s8($countC1 === 0, "S-8(C): pending order has 0 rows in anoofy_order_ops", "count: {$countC1}");

$orderC->update_status('processing');
$countC2 = $countRows($idC);
$statusC2 = $getStatus($idC);
assert_s8($countC2 === 1, "S-8(C): After status change, pending order has 1 row", "count: {$countC2}");
assert_s8($statusC2 === 'NEW', "S-8(C): Operational status is NEW", "status: {$statusC2}");

// --- Case (D): hooks fired again ---
do_action('woocommerce_new_order', $idA, $orderA);
do_action('woocommerce_order_status_changed', $idA, 'processing', 'processing', $orderA);
$countA_after = $countRows($idA);
assert_s8($countA_after === 1, "S-8(D): Re-firing hooks keeps exactly 1 row", "count: {$countA_after}");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

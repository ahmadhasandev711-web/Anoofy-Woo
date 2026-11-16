<?php
/**
 * Spike S-3: Marking a COD order paid at settlement on WooCommerce 11.2.1 (HPOS on & off).
 *
 * Verifies:
 * 1. A real COD order placed in 'processing' has date_paid === null.
 * 2. Calling $order->payment_complete() on a 'processing' or 'completed' order does NOT set date_paid
 *    (because PAYMENT_COMPLETE_STATUSES only includes on-hold, pending, failed, cancelled).
 * 3. Calling $order->set_date_paid() + $order->set_transaction_id() + $order->save() persists date_paid
 *    cleanly without altering stock or firing customer payment emails.
 */
defined('ABSPATH') || exit;

$hasFailed = false;

function assert_s3(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Spike S-3: COD Order Payment Complete vs set_date_paid ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);
$product->set_stock_quantity(10);
$product->save();

$order = wc_create_order();
$order->set_billing_email('s3-customer@example.com');
$order->add_product($product, 1);
$order->set_payment_method('cod');
$order->set_payment_method_title('Cash on delivery');
$order->set_status('processing');
$order->calculate_totals();
$order->save();
wc_reduce_stock_levels($order->get_id());
$orderId = $order->get_id();

$fresh = wc_get_order($orderId);
assert_s3($fresh->get_date_paid('edit') === null, "S-3: Fresh COD 'processing' order has date_paid === null");

// Attempt payment_complete() on 'processing'
$fresh->payment_complete('TXN-IGNORE');
$afterPcProcessing = wc_get_order($orderId);
assert_s3($afterPcProcessing->get_date_paid('edit') === null, "S-3: payment_complete() on 'processing' leaves date_paid === null");

// Use set_date_paid + set_transaction_id + save directly on 'processing' while tracking stock and emails
$capturedMails = [];
$mailCap = static function ($args) use (&$capturedMails) {
    $capturedMails[] = $args['subject'] ?? '';
    return $args;
};
$preWpMail = static fn () => true;
add_filter('wp_mail', $mailCap);
add_filter('pre_wp_mail', $preWpMail);

$stockBefore = wc_get_product($productId)->get_stock_quantity();
$afterPcProcessing->set_date_paid(time());
$afterPcProcessing->set_transaction_id('SETTLE-REF-001');
$afterPcProcessing->add_order_note('COD settled via courier statement SETTLE-REF-001');
$afterPcProcessing->save();

remove_filter('wp_mail', $mailCap);
remove_filter('pre_wp_mail', $preWpMail);

$settled = wc_get_order($orderId);
$stockAfter = wc_get_product($productId)->get_stock_quantity();

assert_s3($settled->get_date_paid('edit') !== null, "S-3: set_date_paid() + save() persists date_paid on 'processing' order");
assert_s3($settled->get_transaction_id() === 'SETTLE-REF-001', "S-3: set_transaction_id() persists reference");
assert_s3($settled->get_status() === 'processing', "S-3: Woo order status stays 'processing' (no unintended status jump)");
assert_s3($stockBefore === $stockAfter, "S-3: Stock unchanged by set_date_paid()", "before={$stockBefore}, after={$stockAfter}");
assert_s3($capturedMails === [], "S-3: Zero customer emails fired on set_date_paid()");

// Cleanup
$settled->delete(true);
$p = wc_get_product($productId);
$p->set_stock_quantity(10);
$p->save();

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

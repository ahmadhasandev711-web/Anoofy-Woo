<?php
/**
 * Spike S-1: Where a new COD order lands (processing vs on-hold).
 */
defined('ABSPATH') || exit;

$hasFailed = false;

function assert_s1(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Spike S-1: New COD Order Placement ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);

$order = wc_create_order();
$order->add_product($product, 1);
$order->set_payment_method('cod');
$order->set_payment_method_title('Cash on delivery');
$order->set_billing_first_name('Ali');
$order->set_billing_last_name('Hassan');
$order->set_billing_city('Giza');
$order->calculate_totals();
$order->save();

// Simulate standard WooCommerce COD checkout payment execution
$gateways = WC()->payment_gateways()->payment_gateways();
$codGateway = $gateways['cod'] ?? null;

assert_s1($codGateway !== null, "COD Gateway available in WooCommerce");

if ($codGateway) {
    $codGateway->process_payment($order->get_id());
}

$freshOrder = wc_get_order($order->get_id());
$actualStatus = $freshOrder ? $freshOrder->get_status() : '';
echo "  [INFO] WooCommerce status after COD process_payment: '{$actualStatus}'\n";

assert_s1($actualStatus !== '', "Order status is not empty", "actual: {$actualStatus}");
assert_s1(in_array($actualStatus, ['processing', 'on-hold'], true), "S-1: COD order lands in 'processing' or 'on-hold'", "actual: {$actualStatus}");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

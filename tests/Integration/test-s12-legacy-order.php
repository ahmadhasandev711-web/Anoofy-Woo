<?php
/**
 * Spike S-12: Existing orders from before the plugin get an ops row (status NEW)
 * the first time they are opened in OrderBox.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Admin\OrderBox;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\Services;

global $wpdb;
$hasFailed = false;

function assert_s12(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Spike S-12: Legacy Order Auto-Track on Box Render ===\n";

$orderId = isset($args[0]) ? (int) $args[0] : 0;

if ($orderId <= 0) {
    // If no orderId passed as argument, create an order and delete its ops row
    $order = wc_create_order();
    $order->set_status('processing');
    $order->save();
    $orderId = $order->get_id();
    $wpdb->delete($wpdb->prefix . 'anoofy_order_ops', ['order_id' => $orderId]);
    $wpdb->delete($wpdb->prefix . 'anoofy_audit_log', ['order_id' => $orderId]);
} else {
    $order = wc_get_order($orderId);
}

assert_s12($order instanceof \WC_Order, "Order exists", "ID: {$orderId}");

// 1. Assert NO row in anoofy_order_ops before opening OrderBox
$opsBefore = Services::repository()->find($orderId);
assert_s12($opsBefore === null, "S-12: Order initially has NO row in anoofy_order_ops");

// 2. Set current user to administrator
$admin = get_user_by('login', 'admin');
if ($admin) {
    wp_set_current_user($admin->ID);
}

// 3. Render OrderBox inside ob_start()
ob_start();
OrderBox::render($order);
$renderedBox = ob_get_clean();

// 4. Assert row in anoofy_order_ops now exists with NEW
$opsAfter = Services::repository()->find($orderId);
assert_s12($opsAfter !== null, "S-12: Row created in anoofy_order_ops after OrderBox::render");
assert_s12($opsAfter?->status === OrderStatus::New, "S-12: Created ops status is NEW", "status: " . ($opsAfter?->status?->value ?? 'null'));

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

<?php
/**
 * Spike S-4: RTO per-item partial restock after inspection on WooCommerce 11.2.1 (HPOS on & off).
 *
 * Verifies:
 * 1. Cancelling a WooCommerce order automatically restores ALL reduced stock (unsuitable for damaged/partial RTO).
 * 2. Zero-amount wc_create_refund with restock_items=true triggers WooCommerce refund emails/objects.
 * 3. wc_update_product_stock($product, $restockQty, 'increase') accurately restores only the inspected healthy qty,
 *    handles stock=6 -> +3 = 9 (1 damaged excluded), keeps Woo order status intact, and fires zero customer emails.
 */
defined('ABSPATH') || exit;

$hasFailed = false;

function assert_s4(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Spike S-4: RTO Partial Restock vs Woo Cancel / Refund ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);
$product->set_manage_stock(true);
$product->set_stock_quantity(10);
$product->save();

// 1. Order A: Prove Woo cancel restores full qty (4 -> 10)
$orderA = wc_create_order();
$orderA->set_billing_email('s4-cancel@example.com');
$orderA->add_product($product, 4);
$orderA->set_payment_method('cod');
$orderA->set_status('processing');
$orderA->calculate_totals();
$orderA->save();
wc_reduce_stock_levels($orderA->get_id());

$stockAfterOrderA = wc_get_product($productId)->get_stock_quantity();
assert_s4($stockAfterOrderA === 6, "S-4(A): Order with qty 4 reduced stock from 10 to 6", "actual={$stockAfterOrderA}");

$orderA->update_status('cancelled', 'Testing full restock on cancel');
$stockAfterCancelA = wc_get_product($productId)->get_stock_quantity();
assert_s4($stockAfterCancelA === 10, "S-4(A): Woo cancel automatically restores FULL qty (6 -> 10)", "actual={$stockAfterCancelA}");
$orderA->delete(true);

// 2. Order B: Prove wc_update_product_stock restores ONLY healthy qty (3 of 4, 1 damaged) with 0 emails
$product = wc_get_product($productId);
$product->set_stock_quantity(10);
$product->save();

$orderB = wc_create_order();
$orderB->set_billing_email('s4-rto@example.com');
$orderB->add_product($product, 4);
$orderB->set_payment_method('cod');
$orderB->set_status('processing');
$orderB->calculate_totals();
$orderB->save();
wc_reduce_stock_levels($orderB->get_id());
$orderBId = $orderB->get_id();

$stockAfterOrderB = wc_get_product($productId)->get_stock_quantity();
assert_s4($stockAfterOrderB === 6, "S-4(B): Order B with qty 4 reduced stock from 10 to 6", "actual={$stockAfterOrderB}");

$capturedMails = [];
$mailCap = static function ($args) use (&$capturedMails) {
    $capturedMails[] = (string) ($args['subject'] ?? '');
    return $args;
};
$preWpMail = static fn () => true;
add_filter('wp_mail', $mailCap);
add_filter('pre_wp_mail', $preWpMail);

// Partial inspection: 3 healthy -> restock, 1 damaged -> do not restock
$targetProduct = wc_get_product($productId);
$newQty = wc_update_product_stock($targetProduct, 3, 'increase');
$orderB = wc_get_order($orderBId);
$orderB->add_order_note('RTO inspected: 3 restocked, 1 damaged');
$orderB->save();

remove_filter('wp_mail', $mailCap);
remove_filter('pre_wp_mail', $preWpMail);

$finalStockB = wc_get_product($productId)->get_stock_quantity();
assert_s4((int) $newQty === 9 && $finalStockB === 9, "S-4(B): wc_update_product_stock(..., 3, 'increase') restores only 3 healthy units (6 -> 9)", "actual={$finalStockB}");
assert_s4($orderB->get_status() === 'processing', "S-4(B): Woo order status remains intact (no automatic full restock)", "status=" . $orderB->get_status());
assert_s4($capturedMails === [], "S-4(B): Zero customer emails fired during partial stock restock");

// 3. Compare zero-amount refund email side effect on Order C
$orderC = wc_create_order();
$orderC->set_billing_email('s4-refund@example.com');
$itemCId = $orderC->add_product(wc_get_product($productId), 2);
$orderC->set_payment_method('cod');
$orderC->set_status('completed');
$orderC->calculate_totals();
$orderC->save();

$refundMails = [];
$refundCap = static function ($args) use (&$refundMails) {
    $refundMails[] = (string) ($args['subject'] ?? '');
    return $args;
};
add_filter('wp_mail', $refundCap);
add_filter('pre_wp_mail', $preWpMail);

$refund = wc_create_refund([
    'amount' => 0,
    'reason' => 'S-4 comparison',
    'order_id' => $orderC->get_id(),
    'line_items' => [
        $itemCId => [
            'qty' => 1,
            'refund_total' => 0,
            'refund_tax' => [],
        ],
    ],
    'restock_items' => true,
]);

remove_filter('wp_mail', $refundCap);
remove_filter('pre_wp_mail', $preWpMail);

assert_s4(!is_wp_error($refund) && count($refundMails) > 0, "S-4(C): Zero-amount wc_create_refund sends customer refund email(s)", "emails=" . count($refundMails));

// Cleanup
$orderB->delete(true);
$orderC->delete(true);
$p = wc_get_product($productId);
$p->set_stock_quantity(10);
$p->save();

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

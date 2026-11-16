<?php
/**
 * Spikes S-2, S-5, S-6 verification on real WooCommerce 11.2.1 (HPOS on & off).
 *
 * - S-2: Customer emails triggered on Woo status changes and suppression check.
 * - S-5: Order editing hooks when items/quantities change in WooCommerce.
 * - S-6: Warehouse role (`anoofy_warehouse`) capabilities and order access without `edit_shop_orders`.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Identity\Action;

$hasFailed = false;

function assert_spike(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Spikes S-2, S-5, S-6 Verification ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);

$admin = get_user_by('login', 'admin');
$adminId = $admin ? $admin->ID : 1;
wp_set_current_user($adminId);

// --------------------------------------------------------------------------
// Spike S-2: WooCommerce Emails on Status Transitions
// --------------------------------------------------------------------------
echo "--- Spike S-2: Emails on status change ---\n";

WC()->mailer(); // ensure email classes are loaded

$capturedMails = [];
$mailListener = static function ($args) use (&$capturedMails) {
    $capturedMails[] = [
        'to' => $args['to'] ?? '',
        'subject' => $args['subject'] ?? '',
    ];
    return $args;
};
add_filter('wp_mail', $mailListener);

$preWpMailTrue = static fn () => true;
add_filter('pre_wp_mail', $preWpMailTrue);

$orderS2 = wc_create_order();
$orderS2->set_billing_email('customer-s2@example.com');
$orderS2->add_product($product, 1);
$orderS2->set_payment_method('cod');
$orderS2->set_status('pending');
$orderS2->calculate_totals();
$orderS2->save();

$capturedMails = [];
$orderS2->update_status('on-hold', 'S-2 test to on-hold');
$mailsOnHold = $capturedMails;
echo "  [INFO] pending -> on-hold triggered " . count($mailsOnHold) . " email(s): " . implode(', ', array_column($mailsOnHold, 'subject')) . "\n";

$capturedMails = [];
$orderS2->update_status('processing', 'S-2 test to processing');
$mailsProcessing = $capturedMails;
echo "  [INFO] on-hold -> processing triggered " . count($mailsProcessing) . " email(s): " . implode(', ', array_column($mailsProcessing, 'subject')) . "\n";

$capturedMails = [];
$orderS2->update_status('completed', 'S-2 test to completed');
$mailsCompleted = $capturedMails;
echo "  [INFO] processing -> completed triggered " . count($mailsCompleted) . " email(s): " . implode(', ', array_column($mailsCompleted, 'subject')) . "\n";

$capturedMails = [];
$orderS2->update_status('cancelled', 'S-2 test to cancelled');
$mailsCancelled = $capturedMails;
echo "  [INFO] completed -> cancelled triggered " . count($mailsCancelled) . " email(s): " . implode(', ', array_column($mailsCancelled, 'subject')) . "\n";

assert_spike(count($mailsProcessing) >= 1, "S-2: on-hold -> processing triggers WooCommerce email notifications");
assert_spike(count($mailsCompleted) >= 1, "S-2: processing -> completed triggers customer completed order email");

remove_filter('wp_mail', $mailListener);
remove_filter('pre_wp_mail', $preWpMailTrue);
$orderS2->delete(true);

// --------------------------------------------------------------------------
// Spike S-5: Order Editing Hooks (Items/Quantities & Update Order)
// --------------------------------------------------------------------------
echo "--- Spike S-5: Order editing hooks ---\n";

$orderS5 = wc_create_order();
$itemId = $orderS5->add_product($product, 1);
$orderS5->set_payment_method('cod');
$orderS5->set_status('processing');
$orderS5->calculate_totals();
$orderS5->save();
$s5Id = $orderS5->get_id();

$firedHooks = [];
$hookLogger = static function (string $name) use (&$firedHooks, $s5Id): callable {
    return static function ($arg1) use ($name, &$firedHooks, $s5Id): void {
        $id = is_numeric($arg1) ? (int) $arg1 : ($arg1 instanceof \WC_Order ? $arg1->get_id() : 0);
        if ($id === $s5Id) {
            $firedHooks[] = $name;
        }
    };
};

$fnUpdate = $hookLogger('woocommerce_update_order');
$fnSavedItems = $hookLogger('woocommerce_saved_order_items');
$fnBeforeItems = $hookLogger('woocommerce_before_save_order_items');
$fnUpdateItem = static function ($itemIdArg, $itemArg) use (&$firedHooks): void {
    $firedHooks[] = 'woocommerce_update_order_item';
};

add_action('woocommerce_update_order', $fnUpdate, 10, 1);
add_action('woocommerce_saved_order_items', $fnSavedItems, 10, 1);
add_action('woocommerce_before_save_order_items', $fnBeforeItems, 10, 1);
add_action('woocommerce_update_order_item', $fnUpdateItem, 10, 2);

// Test A: Edit item quantity via CRUD + save
$firedHooks = [];
$freshS5 = wc_get_order($s5Id);
$item = $freshS5->get_item($itemId);
if ($item instanceof \WC_Order_Item_Product) {
    $item->set_quantity(2);
    $item->save();
}
$freshS5->calculate_totals();
$freshS5->save();
assert_spike(in_array('woocommerce_update_order', $firedHooks, true), "S-5(A): CRUD item qty edit fires woocommerce_update_order", "hooks: " . implode(', ', array_unique($firedHooks)));

// Test B: Edit via wc_save_order_items() (admin edit flow)
if (!function_exists('wc_save_order_items') && defined('WC_ABSPATH')) {
    require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
}
$firedHooks = [];
wc_save_order_items($s5Id, [
    'order_item_id' => [$itemId],
    'order_item_qty' => [$itemId => 3],
]);
assert_spike(in_array('woocommerce_saved_order_items', $firedHooks, true), "S-5(B): wc_save_order_items fires woocommerce_saved_order_items", "hooks: " . implode(', ', array_unique($firedHooks)));

remove_action('woocommerce_update_order', $fnUpdate, 10);
remove_action('woocommerce_saved_order_items', $fnSavedItems, 10);
remove_action('woocommerce_before_save_order_items', $fnBeforeItems, 10);
remove_action('woocommerce_update_order_item', $fnUpdateItem, 10);
$orderS5->delete(true);

// --------------------------------------------------------------------------
// Spike S-6: Warehouse role capabilities & order access
// --------------------------------------------------------------------------
echo "--- Spike S-6: anoofy_warehouse role permissions ---\n";

$whUsername = 'anoofy_wh_spike_' . wp_generate_password(6, false);
$whUserId = wp_create_user($whUsername, wp_generate_password(12), $whUsername . '@example.com');
$whUser = new WP_User($whUserId);
$whUser->set_role('anoofy_warehouse');

wp_set_current_user($whUserId);

assert_spike(!current_user_can('edit_shop_orders'), "S-6: anoofy_warehouse does NOT have Woo edit_shop_orders capability");
assert_spike(current_user_can(Action::OrderView->capability()), "S-6: anoofy_warehouse HAS anoofy_order_view capability");
assert_spike(current_user_can(Action::OrderPrepare->capability()), "S-6: anoofy_warehouse HAS anoofy_order_prepare capability");
assert_spike(!current_user_can(Action::OrderViewCustomerContact->capability()), "S-6: anoofy_warehouse does NOT have anoofy_order_view_customer_contact");

// Verify wc_get_orders & wc_get_order work under anoofy_warehouse context
$ordersList = wc_get_orders(['limit' => 2]);
assert_spike(is_array($ordersList), "S-6: wc_get_orders executes cleanly under anoofy_warehouse user");

wp_set_current_user($adminId);
wp_delete_user($whUserId);

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

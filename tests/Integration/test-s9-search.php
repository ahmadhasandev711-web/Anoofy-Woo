<?php
/**
 * Spike S-9: OrdersPage search (name, phone, email, order number).
 * Black-box test: sets $_GET['s'], calls OrdersPage::render() inside ob_start() as administrator,
 * and asserts that target order is present in the output and other order is absent.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Admin\OrdersPage;

$hasFailed = false;

function assert_s9(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Spike S-9: OrdersPage Search Integration ===\n";

// Ensure current user is an administrator
$admin = get_user_by('login', 'admin');
if ($admin) {
    wp_set_current_user($admin->ID);
}

if (function_exists('set_current_screen')) {
    set_current_screen('toplevel_page_anoofy');
}

// Create Target Order (A)
$orderA = wc_create_order();
$orderA->set_payment_method('cod');
$orderA->set_billing_first_name('MahmoudTarget');
$orderA->set_billing_last_name('KareemTarget');
$orderA->set_billing_phone('01011223344');
$orderA->set_billing_email('mahmoud.target@anoofy.test');
$orderA->set_status('processing');
$orderA->save();
$idA = $orderA->get_id();

// Create Other Order (B) that should NOT match Target's search
$orderB = wc_create_order();
$orderB->set_payment_method('cod');
$orderB->set_billing_first_name('SamehOther');
$orderB->set_billing_last_name('FahmyOther');
$orderB->set_billing_phone('01299887766');
$orderB->set_billing_email('sameh.other@different.test');
$orderB->set_status('processing');
$orderB->save();
$idB = $orderB->get_id();

$renderSearch = static function (string $term): string {
    $_GET['s'] = $term;
    $_GET['page'] = 'anoofy';
    ob_start();
    OrdersPage::render();
    return ob_get_clean();
};

// 1. Search by First Name
$outName = $renderSearch('MahmoudTarget');
$hasA_name = str_contains($outName, '#' . $idA);
$hasB_name = str_contains($outName, '#' . $idB);
assert_s9($hasA_name && !$hasB_name, "S-9: Search by Name finds Target order and excludes Other order", "Target: {$hasA_name}, Other: {$hasB_name}");

// 2. Search by Phone
$outPhone = $renderSearch('01011223344');
$hasA_phone = str_contains($outPhone, '#' . $idA);
$hasB_phone = str_contains($outPhone, '#' . $idB);
assert_s9($hasA_phone && !$hasB_phone, "S-9: Search by Phone finds Target order and excludes Other order", "Target: {$hasA_phone}, Other: {$hasB_phone}");

// 3. Search by Email
$outEmail = $renderSearch('mahmoud.target@anoofy.test');
$hasA_email = str_contains($outEmail, '#' . $idA);
$hasB_email = str_contains($outEmail, '#' . $idB);
assert_s9($hasA_email && !$hasB_email, "S-9: Search by Email finds Target order and excludes Other order", "Target: {$hasA_email}, Other: {$hasB_email}");

// 4. Search by Order Number
$outId = $renderSearch((string) $idA);
$hasA_id = str_contains($outId, '#' . $idA);
$hasB_id = str_contains($outId, '#' . $idB);
assert_s9($hasA_id && !$hasB_id, "S-9: Search by Order ID finds Target order and excludes Other order", "Target: {$hasA_id}, Other: {$hasB_id}");

// Clean up $_GET
unset($_GET['s'], $_GET['page']);

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

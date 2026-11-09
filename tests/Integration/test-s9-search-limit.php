<?php
/**
 * Extra test for Spike S-9: Search with > 500 matching orders.
 * Verifies that searching a term matching > 500 orders:
 * 1. Does not timeout or error.
 * 2. Returns <= 500 results (capped at MAX_SEARCH_RESULTS).
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Admin\OrdersPage;

global $wpdb;
$hasFailed = false;

function assert_s9_limit(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Spike S-9: Search Limit Stress Test (> 500 Orders) ===\n";

$startTime = microtime(true);

// 1. Bulk create 505 minimal orders with the same billing first name if not already created
$term = 'BulkCustomer505';
$totalRequired = 505;

$existingCount = 0;
if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
    $existingCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_addresses WHERE address_type = 'billing' AND first_name = %s",
        $term
    ));
} else {
    $existingCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_billing_first_name' AND meta_value = %s",
        $term
    ));
}

$toCreate = max(0, $totalRequired - $existingCount);

if ($toCreate > 0) {
    echo "[1/3] Creating {$toCreate} test orders with first name '{$term}' (existing: {$existingCount})...\n";

    // Suspend deferred operations for speed
    wp_defer_term_counting(true);
    wp_defer_comment_counting(true);

    $createStart = microtime(true);
    $wpdb->query('START TRANSACTION');

    for ($i = 1; $i <= $toCreate; $i++) {
        $order = wc_create_order(['status' => 'processing']);
        $order->set_billing_first_name($term);
        $order->set_billing_last_name("Test{$i}");
        $order->save();
    }

    $wpdb->query('COMMIT');
    wp_defer_term_counting(false);
    wp_defer_comment_counting(false);

    $createElapsed = round(microtime(true) - $createStart, 2);
    echo "[2/3] Created {$toCreate} orders in {$createElapsed}s\n";
} else {
    echo "[1/3] Reusing {$existingCount} existing orders with first name '{$term}'.\n";
}

// 2. Set current user to administrator
$admin = get_user_by('login', 'admin');
if ($admin) {
    wp_set_current_user($admin->ID);
}

if (function_exists('set_current_screen')) {
    set_current_screen('toplevel_page_anoofy');
}

// 3. Perform search via OrdersPage::render inside ob_start()
echo "[3/3] Executing search for '{$term}'...\n";
$searchStart = microtime(true);

$_GET['s'] = $term;
$_GET['page'] = 'anoofy';

ob_start();
OrdersPage::render();
$htmlOutput = ob_get_clean();

$searchElapsed = round(microtime(true) - $searchStart, 3);
echo "Search execution took: {$searchElapsed}s\n";

unset($_GET['s'], $_GET['page']);

// Count occurrences of order links/checkboxes in the rendered table
// Each order row has <input type="checkbox" name="order_ids[]" value="<id>">
preg_match_all('/name="order_ids\[\]" value="(\d+)"/', $htmlOutput, $matches);
$renderedRows = count($matches[1] ?? []);

// Also check reflection of searchOrderIds or the pagination count in HTML
// Table pagination says: <span class="displaying-num">500 items</span>
preg_match('/<span class="displaying-num">(\d+) items<\/span>/', $htmlOutput, $numMatches);
$totalFoundInTable = isset($numMatches[1]) ? (int) $numMatches[1] : 0;

$totalElapsed = round(microtime(true) - $startTime, 2);

echo "Total elapsed test time: {$totalElapsed}s\n";
echo "Rendered rows in current page: {$renderedRows}\n";
echo "Total matching items reported in table: {$totalFoundInTable}\n";

// Assertions
assert_s9_limit($totalFoundInTable <= 500 && $totalFoundInTable > 0, "S-9 Limit: Total matched orders capped at <= 500", "found: {$totalFoundInTable}");
assert_s9_limit(!str_contains($htmlOutput, 'Fatal error') && !str_contains($htmlOutput, 'Error:'), "S-9 Limit: No PHP errors or timeouts");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

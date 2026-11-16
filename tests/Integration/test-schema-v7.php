<?php
/**
 * Integration test: Schema v7 migration & Returns / Return Items / Stock Movements tables.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Database\Migrator;
use Anoofy\Woo\Database\Schema;

global $wpdb;
$hasFailed = false;

function assert_v7(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test Schema v7: Migration & Returns / Stock Movement Tables ===\n";

Migrator::maybeUpgrade();
assert_v7(Migrator::currentVersion() === Schema::VERSION && Schema::VERSION === '7', "Schema version option is '7'", "actual: " . Migrator::currentVersion());

foreach (['anoofy_returns', 'anoofy_return_items', 'anoofy_stock_movements', 'anoofy_cod_settlements', 'anoofy_cod_settlement_orders', 'anoofy_delivery_attempts'] as $short) {
    $t = $wpdb->prefix . $short;
    assert_v7($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t, "Table {$t} exists");
}

Migrator::maybeUpgrade();
assert_v7(Migrator::currentVersion() === '7', "Migrator::maybeUpgrade() is idempotent at '7'");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

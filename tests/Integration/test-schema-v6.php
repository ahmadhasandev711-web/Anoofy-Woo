<?php
/**
 * Integration test: Schema v6 migration & SettlementRepository / OpsRepository tracking lookup.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Database\Migrator;
use Anoofy\Woo\Database\Schema;

global $wpdb;
$hasFailed = false;

function assert_v6(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test Schema v6: Migration & COD Settlement Tables ===\n";

Migrator::maybeUpgrade();
assert_v6(Migrator::currentVersion() === Schema::VERSION && Schema::VERSION === '6', "Schema version option is '6'", "actual: " . Migrator::currentVersion());

$opsCols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}anoofy_order_ops", 0);
assert_v6(in_array('cod_settled_at', $opsCols, true), "anoofy_order_ops has 'cod_settled_at'");
assert_v6(in_array('cod_settlement_id', $opsCols, true), "anoofy_order_ops has 'cod_settlement_id'");

foreach (['anoofy_cod_settlements', 'anoofy_cod_settlement_orders', 'anoofy_delivery_attempts'] as $short) {
    $t = $wpdb->prefix . $short;
    assert_v6($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t, "Table {$t} exists");
}

Migrator::maybeUpgrade();
assert_v6(Migrator::currentVersion() === '6', "Migrator::maybeUpgrade() is idempotent at '6'");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

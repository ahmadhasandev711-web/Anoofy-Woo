<?php
/**
 * Helper script invoked by tools/bootstrap-wp.ps1
 * Usage: wp eval-file wp-content/plugins/anoofy-woo/tools/setup-store.php [on|off]
 */

defined('ABSPATH') || exit;

$hpos = isset($args[0]) && strtolower($args[0]) === 'off' ? 'off' : 'on';

// Ensure WooCommerce core and HPOS tables are created
if (class_exists('WC_Install')) {
    WC_Install::install();
}

// 1. Setup simple product
$sku = 'TEST-PROD-10';
$productId = wc_get_product_id_by_sku($sku);

if (!$productId) {
    $product = new WC_Product_Simple();
    $product->set_name('Test Product');
    $product->set_slug('test-product');
    $product->set_sku($sku);
    $product->set_regular_price('100');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(10);
    $product->set_status('publish');
    $productId = $product->save();
    echo "Created product ID: {$productId} with stock 10\n";
} else {
    $product = wc_get_product($productId);
    if ($product instanceof WC_Product) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity(10);
        $product->set_status('publish');
        $product->save();
        echo "Reset stock to 10 for product ID: {$productId}\n";
    }
}

// 2. Enable COD payment gateway
$codSettings = (array) get_option('woocommerce_cod_settings', []);
$codSettings['enabled'] = 'yes';
update_option('woocommerce_cod_settings', $codSettings);
echo "COD Gateway enabled: " . ($codSettings['enabled'] ?? 'no') . "\n";

// 3. Configure HPOS
if ($hpos === 'on') {
    if (class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class)) {
        wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class)->create_database_tables();
    }
    update_option('woocommerce_custom_orders_table_enabled', 'yes');
    update_option('woocommerce_custom_orders_table_data_sync_enabled', 'yes');
    echo "HPOS enabled: " . get_option('woocommerce_custom_orders_table_enabled') . "\n";
} else {
    update_option('woocommerce_custom_orders_table_enabled', 'no');
    echo "HPOS disabled (legacy mode): " . get_option('woocommerce_custom_orders_table_enabled') . "\n";
}

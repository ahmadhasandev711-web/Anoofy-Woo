<?php
/**
 * Plugin Name:          Anoofy for WooCommerce
 * Description:          Order operations for WooCommerce: call-center confirmation, preparation, delivery, COD settlement and returns.
 * Version:              0.1.0
 * Requires at least:    7.0
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 11.2
 * WC tested up to:      11.2
 * License:              GPL-2.0-or-later
 * Text Domain:          anoofy-woo
 * Domain Path:          /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const ANOOFY_WOO_VERSION = '0.1.0';
const ANOOFY_WOO_FILE = __FILE__;

// HPOS (custom order tables) compatibility must be declared before WooCommerce initialises.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ANOOFY_WOO_FILE, true);
    }
});

$anoofy_autoload = __DIR__ . '/vendor/autoload.php';
if (!is_readable($anoofy_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Anoofy for WooCommerce', 'anoofy-woo') . ':</strong> '
            . esc_html__('dependencies are missing. Run "composer install --no-dev" in the plugin folder.', 'anoofy-woo') . '</p></div>';
    });

    return;
}
require_once $anoofy_autoload;

register_activation_hook(ANOOFY_WOO_FILE, [\Anoofy\Woo\Plugin::class, 'activate']);

// Priority 20: after WooCommerce (plugins_loaded, default priority) has defined its constants.
add_action('plugins_loaded', [\Anoofy\Woo\Plugin::class, 'boot'], 20);

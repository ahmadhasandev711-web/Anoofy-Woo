<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Database\Migrator;

/** Admin menu: Orders (landing screen) and System status (environment health check). */
final class Menu
{
    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_menu_page(
                __('Anoofy', 'anoofy-woo'),
                __('Anoofy', 'anoofy-woo'),
                Action::OrderView->capability(),
                'anoofy',
                [OrdersPage::class, 'render'],
                'dashicons-clipboard',
                56,
            );
            // Rename the auto-created first submenu from "Anoofy" to "Orders", then add the health check.
            add_submenu_page('anoofy', __('Orders', 'anoofy-woo'), __('Orders', 'anoofy-woo'), Action::OrderView->capability(), 'anoofy', [OrdersPage::class, 'render']);
            add_submenu_page('anoofy', __('System status', 'anoofy-woo'), __('System status', 'anoofy-woo'), Action::PluginSettings->capability(), 'anoofy-status', [self::class, 'render']);
        });
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if (str_contains($hook, 'anoofy')) {
                wp_enqueue_style('anoofy-admin', plugin_dir_url(ANOOFY_WOO_FILE) . 'assets/css/admin.css', [], ANOOFY_WOO_VERSION);
            }
        });
        Notices::register();
        ActionsController::register();
    }

    public static function render(): void
    {
        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        $rows = [
            __('Plugin version', 'anoofy-woo') => ANOOFY_WOO_VERSION,
            __('PHP', 'anoofy-woo') => PHP_VERSION,
            __('WordPress', 'anoofy-woo') => get_bloginfo('version'),
            __('WooCommerce', 'anoofy-woo') => defined('WC_VERSION') ? (string) WC_VERSION : '-',
            __('Order storage', 'anoofy-woo') => $hpos ? __('HPOS (custom order tables)', 'anoofy-woo') : __('Legacy (posts)', 'anoofy-woo'),
            __('Background jobs (Action Scheduler)', 'anoofy-woo') => function_exists('as_enqueue_async_action') ? __('Available', 'anoofy-woo') : __('Missing', 'anoofy-woo'),
            __('Database schema version', 'anoofy-woo') => Migrator::currentVersion(),
        ];

        echo '<div class="wrap"><h1>' . esc_html__('Anoofy for WooCommerce', 'anoofy-woo') . '</h1>';
        echo '<p>' . esc_html__('Order operations: call-center confirmation, preparation, delivery, COD settlement and returns.', 'anoofy-woo') . '</p>';
        echo '<table class="widefat striped" style="max-width:640px"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

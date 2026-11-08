<?php
declare(strict_types=1);

namespace Anoofy\Woo;

use Anoofy\Woo\Admin\Menu;
use Anoofy\Woo\Admin\OrderBox;
use Anoofy\Woo\Database\Migrator;
use Anoofy\Woo\Services\Hooks;

final class Plugin
{
    private const VERSION_OPTION = 'anoofy_woo_version';

    public static function activate(): void
    {
        // WooCommerce may not be loaded yet during activation; only touch our own data here.
        Migrator::maybeUpgrade();
        Capabilities::install();
        update_option(self::VERSION_OPTION, ANOOFY_WOO_VERSION, false);
    }

    public static function boot(): void
    {
        add_action('init', [self::class, 'loadTextDomain']);

        $problems = Environment::problems(
            PHP_VERSION,
            get_bloginfo('version'),
            defined('WC_VERSION') ? (string) WC_VERSION : null,
        );
        if ($problems !== []) {
            add_action('admin_notices', static function () use ($problems): void {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Anoofy for WooCommerce', 'anoofy-woo') . '</strong> '
                    . esc_html__('is inactive:', 'anoofy-woo') . '</p><ul>';
                foreach ($problems as $problem) {
                    echo '<li>' . esc_html($problem) . '</li>';
                }
                echo '</ul></div>';
            });

            return;
        }

        // Plugin updated in place (no activation): bring the tables and capabilities up to date.
        if (get_option(self::VERSION_OPTION) !== ANOOFY_WOO_VERSION) {
            Migrator::maybeUpgrade();
            Capabilities::install();
            update_option(self::VERSION_OPTION, ANOOFY_WOO_VERSION, false);
        } else {
            Migrator::maybeUpgrade();
        }

        Hooks::register();

        if (is_admin()) {
            Menu::register();
            OrderBox::register();
        }
    }

    public static function loadTextDomain(): void
    {
        load_plugin_textdomain('anoofy-woo', false, dirname(plugin_basename(ANOOFY_WOO_FILE)) . '/languages');
    }
}

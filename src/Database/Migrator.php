<?php
declare(strict_types=1);

namespace Anoofy\Woo\Database;

/** Creates/updates the tables. Runs on activation and whenever Schema::VERSION changes. */
final class Migrator
{
    private const OPTION = 'anoofy_woo_db_version';

    public static function maybeUpgrade(): void
    {
        if (get_option(self::OPTION) === Schema::VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach (Schema::statements($wpdb->prefix, $wpdb->get_charset_collate()) as $sql) {
            dbDelta($sql);
        }
        update_option(self::OPTION, Schema::VERSION, false);
    }

    public static function currentVersion(): string
    {
        return (string) get_option(self::OPTION, '0');
    }
}

<?php
declare(strict_types=1);

namespace Anoofy\Woo\Database;

/**
 * Table definitions in the format WordPress `dbDelta()` expects (one column per line, two spaces
 * after PRIMARY KEY, KEY not INDEX). Pure: the Migrator passes the prefix and collation in.
 *
 * Orders are referenced by ID only, so this works with both HPOS and the legacy posts storage.
 */
final class Schema
{
    /** Bump when any table changes; the Migrator re-runs dbDelta when it differs from the stored value. */
    public const VERSION = '1';

    /** Full table name for one of our tables, e.g. table('wp_', 'order_ops') => 'wp_anoofy_order_ops'. */
    public static function table(string $prefix, string $short): string
    {
        return $prefix . 'anoofy_' . $short;
    }

    /** @return list<string> */
    public static function statements(string $prefix, string $collate): array
    {
        return [
            // One row per order: our operational status and the facts the state machine needs.
            "CREATE TABLE {$prefix}anoofy_order_ops (
  order_id bigint(20) unsigned NOT NULL,
  ops_status varchar(32) NOT NULL DEFAULT 'NEW',
  assigned_to bigint(20) unsigned DEFAULT NULL,
  has_logged_call tinyint(1) NOT NULL DEFAULT 0,
  stock_warning tinyint(1) NOT NULL DEFAULT 0,
  stock_warning_accepted tinyint(1) NOT NULL DEFAULT 0,
  edited_after_preparing tinyint(1) NOT NULL DEFAULT 0,
  delivery_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  courier varchar(100) DEFAULT NULL,
  tracking_number varchar(100) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (order_id),
  KEY ops_status (ops_status),
  KEY assigned_to (assigned_to)
) {$collate};",

            "CREATE TABLE {$prefix}anoofy_call_attempts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  outcome varchar(20) NOT NULL,
  note text DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY user_id (user_id)
) {$collate};",

            // Append-only: who did what to which order, and why.
            "CREATE TABLE {$prefix}anoofy_audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned NOT NULL,
  action varchar(64) NOT NULL,
  from_status varchar(32) DEFAULT NULL,
  to_status varchar(32) DEFAULT NULL,
  reason text DEFAULT NULL,
  context longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY user_id (user_id),
  KEY created_at (created_at)
) {$collate};",
        ];
    }
}

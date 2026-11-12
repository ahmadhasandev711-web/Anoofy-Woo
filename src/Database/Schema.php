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
    public const VERSION = '3';

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
  engaz_handover_at datetime DEFAULT NULL,
  engaz_out_version int(10) unsigned NOT NULL DEFAULT 0,
  engaz_stage varchar(32) DEFAULT NULL,
  engaz_stage_at datetime DEFAULT NULL,
  engaz_version int(11) DEFAULT NULL,
  engaz_branch varchar(255) DEFAULT NULL,
  engaz_courier varchar(255) DEFAULT NULL,
  engaz_tracking varchar(255) DEFAULT NULL,
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

            // Reliable async outbound events to EngazPRO.
            "CREATE TABLE {$prefix}anoofy_outbox (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id char(36) NOT NULL,
  event_type varchar(64) NOT NULL,
  wc_order_id bigint(20) unsigned DEFAULT NULL,
  order_version int(10) unsigned DEFAULT NULL,
  payload longtext NOT NULL,
  status enum('pending','in_flight','sent','failed') NOT NULL DEFAULT 'pending',
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  next_retry_at datetime DEFAULT NULL,
  last_http_status smallint DEFAULT NULL,
  last_error text DEFAULT NULL,
  created_at datetime NOT NULL,
  sent_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_id (event_id),
  KEY status_retry (status, next_retry_at),
  KEY wc_order_id (wc_order_id)
) {$collate};",

            // Deduplicated inbound events from EngazPRO.
            "CREATE TABLE {$prefix}anoofy_inbox (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id varchar(36) NOT NULL,
  event_type varchar(64) NOT NULL,
  wc_order_id bigint(20) unsigned NOT NULL,
  result varchar(32) NOT NULL,
  received_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_id (event_id),
  KEY wc_order_id (wc_order_id)
) {$collate};",
        ];
    }
}

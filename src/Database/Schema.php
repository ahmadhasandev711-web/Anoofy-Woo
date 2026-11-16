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
    public const VERSION = '7';

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
  prep_items_hash varchar(64) DEFAULT NULL,
  cod_settled_at datetime DEFAULT NULL,
  cod_settlement_id bigint(20) unsigned DEFAULT NULL,
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

            // Per-line preparation checklist for Standalone Mode warehouse workflow.
            "CREATE TABLE {$prefix}anoofy_order_prepared_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  prepared_by bigint(20) unsigned NOT NULL,
  prepared_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_item (order_id, item_id),
  KEY order_id (order_id)
) {$collate};",

            // Append-only log of courier delivery attempts and outcomes (Phase 3).
            "CREATE TABLE {$prefix}anoofy_delivery_attempts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  attempt_no smallint(5) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  outcome varchar(20) NOT NULL,
  reason text DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY created_at (created_at)
) {$collate};",

            // COD settlement batches from courier statements (Phase 4).
            "CREATE TABLE {$prefix}anoofy_cod_settlements (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  courier varchar(100) NOT NULL,
  reference varchar(100) DEFAULT NULL,
  total_orders int(10) unsigned NOT NULL DEFAULT 0,
  total_cents bigint(20) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {$collate};",

            // Individual orders settled in a COD batch (UNIQUE order_id prevents double settlement).
            "CREATE TABLE {$prefix}anoofy_cod_settlement_orders (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  settlement_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL,
  tracking_number varchar(100) NOT NULL,
  amount_cents bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id),
  KEY settlement_id (settlement_id)
) {$collate};",

            // Return / RTO requests (Phase 5).
            "CREATE TABLE {$prefix}anoofy_returns (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  source varchar(20) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'open',
  reason text DEFAULT NULL,
  opened_by bigint(20) unsigned NOT NULL,
  inspected_by bigint(20) unsigned DEFAULT NULL,
  inspected_at datetime DEFAULT NULL,
  approved_by bigint(20) unsigned DEFAULT NULL,
  approved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY status (status)
) {$collate};",

            // Returned line items and warehouse inspection breakdown (Phase 5).
            "CREATE TABLE {$prefix}anoofy_return_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  return_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL,
  order_item_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sku varchar(100) DEFAULT NULL,
  name varchar(255) NOT NULL,
  qty_returned int(10) unsigned NOT NULL,
  restock_qty int(10) unsigned NOT NULL DEFAULT 0,
  damaged_qty int(10) unsigned NOT NULL DEFAULT 0,
  condition_status varchar(20) NOT NULL DEFAULT 'uninspected',
  note text DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY return_order_item (return_id, order_item_id),
  KEY order_id (order_id)
) {$collate};",

            // Idempotent stock movement ledger for approved returns (UNIQUE idempotency_key prevents double restock).
            "CREATE TABLE {$prefix}anoofy_stock_movements (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  return_id bigint(20) unsigned NOT NULL,
  return_item_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  movement_type varchar(32) NOT NULL,
  qty int(10) unsigned NOT NULL,
  idempotency_key varchar(100) NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY order_id (order_id),
  KEY return_id (return_id)
) {$collate};",
        ];
    }
}

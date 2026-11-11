<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests;

use Anoofy\Woo\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testSchemaVersionIsThree(): void
    {
        self::assertSame('3', Schema::VERSION);
    }

    public function testEveryStatementCreatesAPrefixedTableWithAPrimaryKeyInDbDeltaFormat(): void
    {
        $statements = Schema::statements('wp_', 'DEFAULT CHARACTER SET utf8mb4');
        self::assertCount(5, $statements);
        foreach ($statements as $sql) {
            self::assertTrue(str_starts_with($sql, 'CREATE TABLE wp_anoofy_'), $sql);
            // dbDelta needs exactly two spaces after PRIMARY KEY and KEY (not INDEX).
            self::assertTrue(str_contains($sql, 'PRIMARY KEY  ('), $sql);
            self::assertFalse(str_contains($sql, 'INDEX '), $sql);
            self::assertTrue(str_ends_with($sql, 'DEFAULT CHARACTER SET utf8mb4;'), $sql);
        }
    }

    public function testCustomPrefixIsUsed(): void
    {
        foreach (Schema::statements('shop7_', '') as $sql) {
            self::assertTrue(str_starts_with($sql, 'CREATE TABLE shop7_anoofy_'), $sql);
        }
    }

    public function testOrderOpsContainsV3Columns(): void
    {
        $orderOpsSql = Schema::statements('wp_', '')[0];
        self::assertTrue(str_contains($orderOpsSql, "ops_status varchar(32) NOT NULL DEFAULT 'NEW'"));
        self::assertTrue(str_contains($orderOpsSql, "engaz_handover_at datetime DEFAULT NULL"));
        self::assertTrue(str_contains($orderOpsSql, "engaz_out_version int(10) unsigned NOT NULL DEFAULT 0"));
        self::assertTrue(str_contains($orderOpsSql, "engaz_stage varchar(32) DEFAULT NULL"));
        self::assertTrue(str_contains($orderOpsSql, "engaz_stage_at datetime DEFAULT NULL"));
        self::assertTrue(str_contains($orderOpsSql, "engaz_version int(11) DEFAULT NULL"));
        self::assertTrue(str_contains($orderOpsSql, "engaz_branch varchar(255) DEFAULT NULL"));
        self::assertTrue(str_contains($orderOpsSql, "engaz_courier varchar(255) DEFAULT NULL"));
        self::assertTrue(str_contains($orderOpsSql, "engaz_tracking varchar(255) DEFAULT NULL"));
    }

    public function testInboxStatementContainsRequiredColumnsAndKeys(): void
    {
        $statements = Schema::statements('wp_', '');
        $inboxSql = $statements[4] ?? '';
        self::assertTrue(str_contains($inboxSql, 'CREATE TABLE wp_anoofy_inbox ('));
        self::assertTrue(str_contains($inboxSql, 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT'));
        self::assertTrue(str_contains($inboxSql, 'event_id varchar(36) NOT NULL'));
        self::assertTrue(str_contains($inboxSql, 'event_type varchar(64) NOT NULL'));
        self::assertTrue(str_contains($inboxSql, 'wc_order_id bigint(20) unsigned NOT NULL'));
        self::assertTrue(str_contains($inboxSql, 'result varchar(32) NOT NULL'));
        self::assertTrue(str_contains($inboxSql, 'received_at datetime NOT NULL'));
        self::assertTrue(str_contains($inboxSql, 'PRIMARY KEY  (id)'));
        self::assertTrue(str_contains($inboxSql, 'UNIQUE KEY event_id (event_id)'));
        self::assertTrue(str_contains($inboxSql, 'KEY wc_order_id (wc_order_id)'));
    }

    public function testOutboxStatementContainsRequiredColumnsAndKeys(): void
    {
        $statements = Schema::statements('wp_', '');
        $outboxSql = $statements[3] ?? '';
        self::assertTrue(str_contains($outboxSql, 'CREATE TABLE wp_anoofy_outbox ('));
        self::assertTrue(str_contains($outboxSql, 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT'));
        self::assertTrue(str_contains($outboxSql, 'event_id char(36) NOT NULL'));
        self::assertTrue(str_contains($outboxSql, 'event_type varchar(64) NOT NULL'));
        self::assertTrue(str_contains($outboxSql, 'wc_order_id bigint(20) unsigned DEFAULT NULL'));
        self::assertTrue(str_contains($outboxSql, 'order_version int(10) unsigned DEFAULT NULL'));
        self::assertTrue(str_contains($outboxSql, 'payload longtext NOT NULL'));
        self::assertTrue(str_contains($outboxSql, "status enum('pending','in_flight','sent','failed') NOT NULL DEFAULT 'pending'"));
        self::assertTrue(str_contains($outboxSql, 'attempts int(10) unsigned NOT NULL DEFAULT 0'));
        self::assertTrue(str_contains($outboxSql, 'next_retry_at datetime DEFAULT NULL'));
        self::assertTrue(str_contains($outboxSql, 'last_error text DEFAULT NULL'));
        self::assertTrue(str_contains($outboxSql, 'created_at datetime NOT NULL'));
        self::assertTrue(str_contains($outboxSql, 'sent_at datetime DEFAULT NULL'));
        self::assertTrue(str_contains($outboxSql, 'PRIMARY KEY  (id)'));
        self::assertTrue(str_contains($outboxSql, 'KEY status_retry (status, next_retry_at)') || str_contains($outboxSql, 'KEY (status, next_retry_at)'));
        self::assertTrue(str_contains($outboxSql, 'KEY wc_order_id (wc_order_id)'));
    }

    public function testTableHelperMatchesTheCreateStatements(): void
    {
        $names = Schema::statements('wp_', '');
        foreach (['order_ops', 'call_attempts', 'audit_log', 'outbox'] as $short) {
            $table = Schema::table('wp_', $short);
            $found = false;
            foreach ($names as $sql) {
                $found = $found || str_contains($sql, 'CREATE TABLE ' . $table . ' (');
            }
            self::assertTrue($found, $table);
        }
    }
}

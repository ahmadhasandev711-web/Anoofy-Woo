<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests;

use Anoofy\Woo\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testEveryStatementCreatesAPrefixedTableWithAPrimaryKeyInDbDeltaFormat(): void
    {
        $statements = Schema::statements('wp_', 'DEFAULT CHARACTER SET utf8mb4');
        self::assertCount(3, $statements);
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

    public function testOrderOpsDefaultsToTheNewStatus(): void
    {
        self::assertTrue(str_contains(Schema::statements('wp_', '')[0], "ops_status varchar(32) NOT NULL DEFAULT 'NEW'"));
    }

    public function testTableHelperMatchesTheCreateStatements(): void
    {
        $names = Schema::statements('wp_', '');
        foreach (['order_ops', 'call_attempts', 'audit_log'] as $short) {
            $table = Schema::table('wp_', $short);
            $found = false;
            foreach ($names as $sql) {
                $found = $found || str_contains($sql, 'CREATE TABLE ' . $table . ' (');
            }
            self::assertTrue($found, $table);
        }
    }
}

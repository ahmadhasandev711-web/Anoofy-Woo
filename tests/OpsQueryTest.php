<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests;

use Anoofy\Woo\Data\OpsQuery;
use PHPUnit\Framework\TestCase;

final class OpsQueryTest extends TestCase
{
    private function placeholders(string $sql): int
    {
        return preg_match_all('/%[ds]/', $sql);
    }

    public function testNoFiltersHasOnlyPagingPlaceholders(): void
    {
        [$sql, $args] = OpsQuery::select('wp_anoofy_order_ops', null, null, null, 20, 40);
        self::assertSame('SELECT * FROM wp_anoofy_order_ops ORDER BY order_id DESC LIMIT %d OFFSET %d', $sql);
        self::assertSame([20, 40], $args);
    }

    public function testPlaceholderCountAlwaysMatchesArgs(): void
    {
        $cases = [
            [['NEW'], null, null],
            [['NEW', 'UNDER_REVIEW'], 5, null],
            [null, OpsQuery::ASSIGNED_UNASSIGNED, [1, 2, 3]],
            [['CONFIRMED'], 9, [10, 11]],
            [[], null, null],
            [null, null, []],
        ];
        foreach ($cases as [$statuses, $assigned, $ids]) {
            [$sql, $args] = OpsQuery::select('t', $statuses, $assigned, $ids, 10, 0);
            self::assertSame($this->placeholders($sql), count($args), $sql);
            [$sql, $args] = OpsQuery::count('t', $statuses, $assigned, $ids);
            self::assertSame($this->placeholders($sql), count($args), $sql);
            [$sql, $args] = OpsQuery::statusCounts('t', $assigned, $ids);
            self::assertSame($this->placeholders($sql), count($args), $sql);
        }
    }

    public function testArgumentOrderFollowsPlaceholderOrder(): void
    {
        [$sql, $args] = OpsQuery::select('t', ['NEW', 'CONFIRMED'], 7, [100, 200], 20, 0);
        self::assertSame(
            'SELECT * FROM t WHERE ops_status IN (%s, %s) AND assigned_to = %d AND order_id IN (%d, %d) ORDER BY order_id DESC LIMIT %d OFFSET %d',
            $sql,
        );
        self::assertSame(['NEW', 'CONFIRMED', 7, 100, 200, 20, 0], $args);
    }

    public function testEmptySearchResultMatchesNothingInsteadOfEverything(): void
    {
        [$sql] = OpsQuery::count('t', null, null, []);
        self::assertSame('SELECT COUNT(*) FROM t WHERE 1 = 0', $sql);
        [$sql] = OpsQuery::count('t', [], null, null);
        self::assertSame('SELECT COUNT(*) FROM t WHERE 1 = 0', $sql);
    }

    public function testUnassignedFilterAndStatusCountsIgnoreStatus(): void
    {
        [$sql, $args] = OpsQuery::statusCounts('t', OpsQuery::ASSIGNED_UNASSIGNED, null);
        self::assertSame('SELECT ops_status, COUNT(*) AS c FROM t WHERE assigned_to IS NULL GROUP BY ops_status', $sql);
        self::assertSame([], $args);
    }

    public function testPagingIsClamped(): void
    {
        [, $args] = OpsQuery::select('t', null, null, null, 0, -5);
        self::assertSame([1, 0], $args);
    }
}

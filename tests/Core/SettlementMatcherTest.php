<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Core\Settlement\SettlementCandidate;
use Anoofy\Woo\Core\Settlement\SettlementCsvParser;
use Anoofy\Woo\Core\Settlement\SettlementMatcher;
use Anoofy\Woo\Core\Settlement\SettlementRowStatus;
use PHPUnit\Framework\TestCase;

final class SettlementMatcherTest extends TestCase
{
    public function testCsvParserParsesHeadersCommaAndSemicolonWithExactCents(): void
    {
        $csv = "\xEF\xBB\xBFtracking_number,cod_amount,reference\r\n"
            . "BOSTA-101,350.50,BATCH-1\r\n"
            . "ARX-202,1200,BATCH-1\r\n";

        $parsed = SettlementCsvParser::parse($csv);
        self::assertSame([], $parsed['errors']);
        self::assertCount(2, $parsed['lines']);
        self::assertSame('BOSTA-101', $parsed['lines'][0]->trackingNumber);
        self::assertSame(35050, $parsed['lines'][0]->collectedCents);
        self::assertSame('BATCH-1', $parsed['lines'][0]->reference);
        self::assertSame('ARX-202', $parsed['lines'][1]->trackingNumber);
        self::assertSame(120000, $parsed['lines'][1]->collectedCents);

        $semicolon = "awb;amount\nTRK-9;99.99";
        $parsedSemi = SettlementCsvParser::parse($semicolon);
        self::assertSame([], $parsedSemi['errors']);
        self::assertCount(1, $parsedSemi['lines']);
        self::assertSame('TRK-9', $parsedSemi['lines'][0]->trackingNumber);
        self::assertSame(9999, $parsedSemi['lines'][0]->collectedCents);
    }

    public function testCsvParserSupportsHeaderlessRowsAndReportsInvalidLines(): void
    {
        $csv = "TRK-1,250.00\nBAD-ROW,\nTRK-2,-10\n";
        $parsed = SettlementCsvParser::parse($csv);

        self::assertCount(1, $parsed['lines']);
        self::assertSame('TRK-1', $parsed['lines'][0]->trackingNumber);
        self::assertSame(25000, $parsed['lines'][0]->collectedCents);
        self::assertCount(2, $parsed['errors']);
    }

    public function testMatcherClassifiesAllStatusesAccurately(): void
    {
        $csv = implode("\n", [
            'tracking,amount',
            'TRK-OK,250.00',
            'TRK-OK,250.00',
            'TRK-MISSING,100.00',
            'TRK-ENGAZ,250.00',
            'TRK-SETTLED,250.00',
            'TRK-SHIPPED,250.00',
            'TRK-DIFF,240.00',
        ]);

        $parsed = SettlementCsvParser::parse($csv);
        $candidates = [
            'TRK-OK' => new SettlementCandidate(10, 'TRK-OK', OrderStatus::Delivered, 25000, false, false),
            'TRK-ENGAZ' => new SettlementCandidate(11, 'TRK-ENGAZ', OrderStatus::Delivered, 25000, false, true),
            'TRK-SETTLED' => new SettlementCandidate(12, 'TRK-SETTLED', OrderStatus::Delivered, 25000, true, false),
            'TRK-SHIPPED' => new SettlementCandidate(13, 'TRK-SHIPPED', OrderStatus::Shipped, 25000, false, false),
            'TRK-DIFF' => new SettlementCandidate(14, 'TRK-DIFF', OrderStatus::Delivered, 25000, false, false),
        ];

        $preview = SettlementMatcher::match($parsed['lines'], $candidates, $parsed['errors']);

        self::assertSame(SettlementRowStatus::Matched, $preview->rows[0]->status);
        self::assertSame(SettlementRowStatus::DuplicateTracking, $preview->rows[1]->status);
        self::assertSame(SettlementRowStatus::NotFound, $preview->rows[2]->status);
        self::assertSame(SettlementRowStatus::HandedOverToEngaz, $preview->rows[3]->status);
        self::assertSame(SettlementRowStatus::AlreadySettled, $preview->rows[4]->status);
        self::assertSame(SettlementRowStatus::NotDelivered, $preview->rows[5]->status);
        self::assertSame(SettlementRowStatus::AmountMismatch, $preview->rows[6]->status);

        self::assertCount(1, $preview->matched);
        self::assertSame(25000, $preview->matchedTotalCents);
    }
}

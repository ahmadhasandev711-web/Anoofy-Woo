<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Order\PreparationChecker;
use PHPUnit\Framework\TestCase;

final class PreparationCheckerTest extends TestCase
{
    public function testAllPreparedRequiresNonEmptyOrderItemsAndEveryItemPrepared(): void
    {
        self::assertFalse(PreparationChecker::allPrepared([], []));
        self::assertFalse(PreparationChecker::allPrepared([10, 20], []));
        self::assertFalse(PreparationChecker::allPrepared([10, 20], [10]));
        self::assertTrue(PreparationChecker::allPrepared([10, 20], [20, 10]));
        self::assertTrue(PreparationChecker::allPrepared([10, 20], [10, 20, 99]));
    }

    public function testItemsHashIsOrderIndependentAndDetectsQuantityOrProductChanges(): void
    {
        $a = [
            ['item_id' => 2, 'product_id' => 100, 'variation_id' => 0, 'qty' => 1],
            ['item_id' => 1, 'product_id' => 101, 'variation_id' => 5, 'qty' => 2],
        ];
        $b = [
            ['item_id' => 1, 'product_id' => 101, 'variation_id' => 5, 'qty' => 2],
            ['item_id' => 2, 'product_id' => 100, 'variation_id' => 0, 'qty' => 1],
        ];

        $hashA = PreparationChecker::itemsHash($a);
        self::assertSame(64, strlen($hashA));
        self::assertSame($hashA, PreparationChecker::itemsHash($b));

        $qtyChanged = $a;
        $qtyChanged[0]['qty'] = 3;
        self::assertNotSame($hashA, PreparationChecker::itemsHash($qtyChanged));

        $itemAdded = $a;
        $itemAdded[] = ['item_id' => 3, 'product_id' => 102, 'variation_id' => 0, 'qty' => 1];
        self::assertNotSame($hashA, PreparationChecker::itemsHash($itemAdded));
    }
}

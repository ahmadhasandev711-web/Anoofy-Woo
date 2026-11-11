<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Connection\OrderConfirmedPayload;
use Anoofy\Woo\Core\Connection\OrderHandoverData;
use Anoofy\Woo\Core\Connection\OrderHandoverItem;
use PHPUnit\Framework\TestCase;

final class OrderConfirmedPayloadTest extends TestCase
{
    public function testBuildMatchesOrderConfirmedFixture(): void
    {
        $fixturePath = dirname(__DIR__) . '/Fixtures/engaz/order.confirmed.json';
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $item = new OrderHandoverItem(
            productId: 901,
            variationId: null,
            quantity: 2,
            name: 'قميص قطني',
            sku: 'SHIRT-01',
            subtotalCents: 20000,
            totalCents: 20000
        );

        $data = new OrderHandoverData(
            orderId: 5001,
            confirmedAt: '2026-11-04T12:05:00Z',
            confirmedByName: 'سارة أحمد',
            customerPhone: '01011223344',
            address1: '15 شارع النصر',
            address2: 'شقة 4',
            city: 'مدينة نصر',
            state: 'القاهرة',
            items: [$item]
        );

        $payload = OrderConfirmedPayload::build($data);

        self::assertSame($fixture['data'], $payload);
        self::assertSame('100.00', $payload['line_items'][0]['price']);
        self::assertSame('200.00', $payload['line_items'][0]['total']);
    }

    public function testIntegerRoundHalfUpPricing(): void
    {
        // Case 1: 100 / 3 => 33.3333... cents => 33.33 EGP
        $item1 = OrderHandoverItem::fromMoney(
            productId: 1,
            variationId: null,
            quantity: 3,
            name: 'Item 1',
            sku: null,
            subtotal: '100.00',
            total: '100.00'
        );
        $data1 = new OrderHandoverData(
            orderId: 1,
            confirmedAt: '2026-11-04T12:00:00Z',
            confirmedByName: 'Admin',
            customerPhone: null,
            address1: null,
            address2: null,
            city: null,
            state: null,
            items: [$item1]
        );
        $payload1 = OrderConfirmedPayload::build($data1);
        self::assertSame('33.33', $payload1['line_items'][0]['price']);
        self::assertSame('100.00', $payload1['line_items'][0]['total']);

        // Case 2: 99.99 / 3 => 33.33 exact
        $item2 = OrderHandoverItem::fromMoney(
            productId: 2,
            variationId: null,
            quantity: 3,
            name: 'Item 2',
            sku: null,
            subtotal: '99.99',
            total: '99.99'
        );
        $data2 = new OrderHandoverData(
            orderId: 2,
            confirmedAt: '2026-11-04T12:00:00Z',
            confirmedByName: 'Admin',
            customerPhone: null,
            address1: null,
            address2: null,
            city: null,
            state: null,
            items: [$item2]
        );
        $payload2 = OrderConfirmedPayload::build($data2);
        self::assertSame('33.33', $payload2['line_items'][0]['price']);
        self::assertSame('99.99', $payload2['line_items'][0]['total']);

        // Case 3: round half up (2.5 cents rounds up to 3 cents => 0.03)
        // subtotal 0.05, qty 2 -> 5 / 2 = 2.5 cents -> 3 cents = "0.03"
        $item3 = OrderHandoverItem::fromMoney(
            productId: 3,
            variationId: null,
            quantity: 2,
            name: 'Item 3',
            sku: null,
            subtotal: '0.05',
            total: '0.05'
        );
        $data3 = new OrderHandoverData(
            orderId: 3,
            confirmedAt: '2026-11-04T12:00:00Z',
            confirmedByName: 'Admin',
            customerPhone: null,
            address1: null,
            address2: null,
            city: null,
            state: null,
            items: [$item3]
        );
        $payload3 = OrderConfirmedPayload::build($data3);
        self::assertSame('0.03', $payload3['line_items'][0]['price']);
        self::assertSame('0.05', $payload3['line_items'][0]['total']);
    }

    public function testZeroQuantityDoesNotDivideByZero(): void
    {
        $item = new OrderHandoverItem(
            productId: 10,
            variationId: null,
            quantity: 0,
            name: 'Zero Qty Item',
            sku: null,
            subtotalCents: 500,
            totalCents: 500
        );
        $data = new OrderHandoverData(
            orderId: 10,
            confirmedAt: '2026-11-04T12:00:00Z',
            confirmedByName: 'Admin',
            customerPhone: null,
            address1: null,
            address2: null,
            city: null,
            state: null,
            items: [$item]
        );
        $payload = OrderConfirmedPayload::build($data);
        self::assertSame('0.00', $payload['line_items'][0]['price']);
        self::assertSame('5.00', $payload['line_items'][0]['total']);
    }

    public function testToCentsRoundHalfUpOnThirdDecimal(): void
    {
        self::assertSame(3334, OrderHandoverItem::toCents('33.335'));
        self::assertSame(3333, OrderHandoverItem::toCents('33.334'));
        self::assertSame(-101, OrderHandoverItem::toCents('-1.005'));
        self::assertSame(1000, OrderHandoverItem::toCents('10'));
    }
}

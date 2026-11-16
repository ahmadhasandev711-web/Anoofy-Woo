<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Order\DeliveryOutcome;
use Anoofy\Woo\Core\Order\OrderOps;
use PHPUnit\Framework\TestCase;

final class DeliveryOutcomeTest extends TestCase
{
    public function testCasesMatchExpectedValues(): void
    {
        self::assertSame('DELIVERED', DeliveryOutcome::Delivered->value);
        self::assertSame('FAILED', DeliveryOutcome::Failed->value);
        self::assertCount(2, DeliveryOutcome::cases());
    }

    public function testOrderOpsToContextRespectsCustomMaxDeliveryAttempts(): void
    {
        $ops = OrderOps::fromRow([
            'order_id' => '55',
            'ops_status' => 'DELIVERY_FAILED',
            'delivery_attempts' => '2',
        ]);

        $ctxDefault = $ops->toContext('no answer');
        self::assertSame(2, $ctxDefault->deliveryAttempts);
        self::assertSame(3, $ctxDefault->maxDeliveryAttempts);

        $ctxCustom = $ops->toContext('no answer', false, 5);
        self::assertSame(5, $ctxCustom->maxDeliveryAttempts);
    }
}

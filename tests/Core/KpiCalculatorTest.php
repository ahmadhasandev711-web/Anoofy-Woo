<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Core\Reports\AgentKpiRow;
use Anoofy\Woo\Core\Reports\KpiCalculator;
use PHPUnit\Framework\TestCase;

final class KpiCalculatorTest extends TestCase
{
    public function testZeroCountsProduceZeroRatesSafely(): void
    {
        $summary = KpiCalculator::fromStatusCounts([], 0, 0, 0, 0, []);

        self::assertSame(0, $summary->totalOrders);
        self::assertSame(0.0, $summary->confirmationRate);
        self::assertSame(0.0, $summary->deliverySuccessRate);
        self::assertSame(0.0, $summary->rtoRate);
        self::assertSame(0.0, $summary->cancellationRate);
        self::assertSame(0, $summary->codOutstandingCents);
        self::assertSame(0, $summary->codSettledCents);
    }

    public function testCalculatesRatesAndMoneyAndPerAgentMetrics(): void
    {
        $counts = [
            OrderStatus::New->value => 2,
            OrderStatus::Confirmed->value => 1,
            OrderStatus::Shipped->value => 1,
            OrderStatus::Delivered->value => 6,
            OrderStatus::ReturnedToOrigin->value => 2,
            OrderStatus::Rejected->value => 2,
            OrderStatus::Cancelled->value => 1,
        ];

        $agents = [
            new AgentKpiRow(5, 8, 5, 1, 14, 10, 1),
            new AgentKpiRow(7, 4, 1, 1, 6, 4, 0),
        ];

        $summary = KpiCalculator::fromStatusCounts($counts, 3, 45000, 3, 60000, $agents);

        self::assertSame(15, $summary->totalOrders);
        // Confirmed+ = 1 + 1 + 6 + 2 = 10; Rejected = 2 -> 10 / 12 = 83.3%
        self::assertSame(10, $summary->confirmedOrders);
        self::assertSame(83.3, $summary->confirmationRate);
        // Delivery finished = 6 + 2 = 8 -> delivered 6 / 8 = 75.0%, RTO 2 / 8 = 25.0%
        self::assertSame(6, $summary->deliveredOrders);
        self::assertSame(2, $summary->returnedToOriginOrders);
        self::assertSame(75.0, $summary->deliverySuccessRate);
        self::assertSame(25.0, $summary->rtoRate);
        // Cancelled + rejected = 3 / 15 = 20.0%
        self::assertSame(20.0, $summary->cancellationRate);
        self::assertSame(3, $summary->codOutstandingCount);
        self::assertSame(45000, $summary->codOutstandingCents);
        self::assertSame(3, $summary->codSettledCount);
        self::assertSame(60000, $summary->codSettledCents);
        self::assertCount(2, $summary->perAgent);
        self::assertSame(71.4, $summary->perAgent[0]->answerRate());
    }
}

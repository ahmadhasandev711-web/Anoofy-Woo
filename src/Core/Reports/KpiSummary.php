<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Reports;

/** Aggregated store KPI snapshot across confirmation, delivery, RTO, COD settlement, and agents. */
final class KpiSummary
{
    /**
     * @param array<string, int> $statusCounts
     * @param list<AgentKpiRow>  $perAgent
     */
    public function __construct(
        public readonly int $totalOrders,
        public readonly int $confirmedOrders,
        public readonly int $rejectedOrders,
        public readonly int $cancelledOrders,
        public readonly int $inFlightOrders,
        public readonly int $deliveredOrders,
        public readonly int $returnedToOriginOrders,
        public readonly float $confirmationRate,
        public readonly float $deliverySuccessRate,
        public readonly float $rtoRate,
        public readonly float $cancellationRate,
        public readonly int $codOutstandingCount,
        public readonly int $codOutstandingCents,
        public readonly int $codSettledCount,
        public readonly int $codSettledCents,
        public readonly array $statusCounts,
        public readonly array $perAgent,
    ) {
    }
}

<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Reports;

use Anoofy\Woo\Core\Order\OrderStatus;

/** Pure KPI calculator converting raw database counts and COD totals into percentages. */
final class KpiCalculator
{
    /**
     * @param array<string, int> $statusCounts
     * @param list<AgentKpiRow>  $perAgent
     */
    public static function fromStatusCounts(
        array $statusCounts,
        int $codOutstandingCount,
        int $codOutstandingCents,
        int $codSettledCount,
        int $codSettledCents,
        array $perAgent = [],
    ): KpiSummary {
        $countOf = static fn (OrderStatus $status): int => max(0, (int) ($statusCounts[$status->value] ?? 0));

        $totalOrders = 0;
        foreach ($statusCounts as $count) {
            $totalOrders += max(0, (int) $count);
        }

        $confirmedStatuses = [
            OrderStatus::Confirmed,
            OrderStatus::Preparing,
            OrderStatus::ReadyToShip,
            OrderStatus::CancellationPending,
            OrderStatus::Shipped,
            OrderStatus::Delivered,
            OrderStatus::DeliveryFailed,
            OrderStatus::ReturnedToOrigin,
        ];
        $confirmedOrders = 0;
        foreach ($confirmedStatuses as $st) {
            $confirmedOrders += $countOf($st);
        }

        $rejectedOrders = $countOf(OrderStatus::Rejected);
        $cancelledOrders = $countOf(OrderStatus::Cancelled);
        $deliveredOrders = $countOf(OrderStatus::Delivered);
        $rtoOrders = $countOf(OrderStatus::ReturnedToOrigin);

        $inFlightOrders = $countOf(OrderStatus::New)
            + $countOf(OrderStatus::UnderReview)
            + $countOf(OrderStatus::Confirmed)
            + $countOf(OrderStatus::Preparing)
            + $countOf(OrderStatus::ReadyToShip)
            + $countOf(OrderStatus::CancellationPending)
            + $countOf(OrderStatus::Shipped)
            + $countOf(OrderStatus::DeliveryFailed);

        $decided = $confirmedOrders + $rejectedOrders;
        $confirmationRate = $decided > 0 ? round(($confirmedOrders / $decided) * 100, 1) : 0.0;

        $deliveryFinished = $deliveredOrders + $rtoOrders;
        $deliverySuccessRate = $deliveryFinished > 0 ? round(($deliveredOrders / $deliveryFinished) * 100, 1) : 0.0;
        $rtoRate = $deliveryFinished > 0 ? round(($rtoOrders / $deliveryFinished) * 100, 1) : 0.0;

        $cancellationRate = $totalOrders > 0
            ? round((($rejectedOrders + $cancelledOrders) / $totalOrders) * 100, 1)
            : 0.0;

        return new KpiSummary(
            totalOrders: $totalOrders,
            confirmedOrders: $confirmedOrders,
            rejectedOrders: $rejectedOrders,
            cancelledOrders: $cancelledOrders,
            inFlightOrders: $inFlightOrders,
            deliveredOrders: $deliveredOrders,
            returnedToOriginOrders: $rtoOrders,
            confirmationRate: $confirmationRate,
            deliverySuccessRate: $deliverySuccessRate,
            rtoRate: $rtoRate,
            cancellationRate: $cancellationRate,
            codOutstandingCount: max(0, $codOutstandingCount),
            codOutstandingCents: max(0, $codOutstandingCents),
            codSettledCount: max(0, $codSettledCount),
            codSettledCents: max(0, $codSettledCents),
            statusCounts: $statusCounts,
            perAgent: $perAgent,
        );
    }
}

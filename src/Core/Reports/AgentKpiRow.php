<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Reports;

/** Per-employee operational performance row (pure data). */
final class AgentKpiRow
{
    public function __construct(
        public readonly int $userId,
        public readonly int $assignedOrders,
        public readonly int $deliveredOrders,
        public readonly int $returnedOrders,
        public readonly int $callsLogged,
        public readonly int $answeredCalls,
        public readonly int $returnsApproved,
    ) {
    }

    public function answerRate(): float
    {
        if ($this->callsLogged <= 0) {
            return 0.0;
        }

        return round(($this->answeredCalls / $this->callsLogged) * 100, 1);
    }
}

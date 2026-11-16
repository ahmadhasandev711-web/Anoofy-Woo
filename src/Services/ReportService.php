<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Identity\Permissions;
use Anoofy\Woo\Core\Identity\RoleResolver;
use Anoofy\Woo\Core\Reports\KpiCalculator;
use Anoofy\Woo\Core\Reports\KpiSummary;
use Anoofy\Woo\Data\ReportRepository;

/** Application service assembling KPI reports behind `Action::ReportView`. */
final class ReportService
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly CodSettlementService $codSettlement,
    ) {
    }

    public function buildDashboard(int $userId, string $period = '30d'): ?KpiSummary
    {
        if (!$this->canViewReports($userId)) {
            return null;
        }

        $sinceUtc = self::sinceUtcFor($period);
        $statusCounts = $this->reports->statusCounts($sinceUtc);
        $codSettled = $this->reports->codSettledSummary($sinceUtc);
        $codOutstanding = $this->codSettlement->pendingSummary();
        $agentStats = $this->reports->agentStats($sinceUtc);

        return KpiCalculator::fromStatusCounts(
            statusCounts: $statusCounts,
            codOutstandingCount: $codOutstanding['count'],
            codOutstandingCents: $codOutstanding['total_cents'],
            codSettledCount: $codSettled['count'],
            codSettledCents: $codSettled['total_cents'],
            perAgent: $agentStats,
        );
    }

    public static function normalizePeriod(string $period): string
    {
        return in_array($period, ['7d', '30d', 'all'], true) ? $period : '30d';
    }

    public static function sinceUtcFor(string $period): ?string
    {
        return match (self::normalizePeriod($period)) {
            '7d' => gmdate('Y-m-d H:i:s', time() - 7 * 86400),
            '30d' => gmdate('Y-m-d H:i:s', time() - 30 * 86400),
            default => null,
        };
    }

    private function canViewReports(int $userId): bool
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return false;
        }
        $role = RoleResolver::fromWpRoles(array_values(array_map('strval', $user->roles)));

        return $role !== null && Permissions::can($role, Action::ReportView);
    }
}

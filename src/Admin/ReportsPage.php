<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Connection\OrderHandoverItem;
use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Services\ReportService;
use Anoofy\Woo\Services\Services;

/** Admin KPI dashboard screen (confirmation, delivery success, RTO, COD settlement, per-agent metrics). */
final class ReportsPage
{
    public static function render(): void
    {
        if (!current_user_can(Action::ReportView->capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'anoofy-woo'), '', ['response' => 403]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rawPeriod = isset($_GET['period']) ? sanitize_key(wp_unslash((string) $_GET['period'])) : '30d';
        $period = ReportService::normalizePeriod($rawPeriod);

        $summary = Services::reportService()->buildDashboard(get_current_user_id(), $period);
        if ($summary === null) {
            wp_die(esc_html__('You are not allowed to do this.', 'anoofy-woo'), '', ['response' => 403]);
        }

        echo '<div class="wrap"><h1>' . esc_html__('Operations Reports & KPIs', 'anoofy-woo') . '</h1>';
        self::renderPeriodTabs($period);

        echo '<div class="anoofy-kpi-grid">';
        self::card(
            __('Total orders', 'anoofy-woo'),
            (string) $summary->totalOrders,
            sprintf(
                /* translators: %d: active orders in flight */
                __('In progress: %d', 'anoofy-woo'),
                $summary->inFlightOrders
            )
        );
        self::card(
            __('Confirmation rate', 'anoofy-woo'),
            number_format($summary->confirmationRate, 1) . '%',
            sprintf(
                /* translators: 1: confirmed orders, 2: rejected orders */
                __('%1$d confirmed / %2$d rejected', 'anoofy-woo'),
                $summary->confirmedOrders,
                $summary->rejectedOrders
            )
        );
        self::card(
            __('Delivery success rate', 'anoofy-woo'),
            number_format($summary->deliverySuccessRate, 1) . '%',
            sprintf(
                /* translators: 1: delivered orders, 2: returned to origin orders */
                __('%1$d delivered / %2$d RTO', 'anoofy-woo'),
                $summary->deliveredOrders,
                $summary->returnedToOriginOrders
            )
        );
        self::card(
            __('RTO & cancellation rate', 'anoofy-woo'),
            number_format($summary->rtoRate, 1) . '%',
            sprintf(
                /* translators: %s: cancellation percentage */
                __('Cancellation rate: %s%%', 'anoofy-woo'),
                number_format($summary->cancellationRate, 1)
            )
        );
        self::card(
            __('COD outstanding', 'anoofy-woo'),
            OrderHandoverItem::formatCents($summary->codOutstandingCents),
            sprintf(
                /* translators: %d: unsettled delivered order count */
                __('Orders awaiting settlement: %d', 'anoofy-woo'),
                $summary->codOutstandingCount
            )
        );
        self::card(
            __('COD settled', 'anoofy-woo'),
            OrderHandoverItem::formatCents($summary->codSettledCents),
            sprintf(
                /* translators: %d: settled order count */
                __('Settled orders: %d', 'anoofy-woo'),
                $summary->codSettledCount
            )
        );
        echo '</div>';

        echo '<h2>' . esc_html__('Per-agent performance', 'anoofy-woo') . '</h2>';
        if ($summary->perAgent === []) {
            echo '<p class="description">' . esc_html__('No agent activity recorded in this period.', 'anoofy-woo') . '</p></div>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>'
            . esc_html__('Employee', 'anoofy-woo') . '</th><th>'
            . esc_html__('Assigned orders', 'anoofy-woo') . '</th><th>'
            . esc_html__('Delivered', 'anoofy-woo') . '</th><th>'
            . esc_html__('Returned (RTO)', 'anoofy-woo') . '</th><th>'
            . esc_html__('Calls logged', 'anoofy-woo') . '</th><th>'
            . esc_html__('Answer rate', 'anoofy-woo') . '</th><th>'
            . esc_html__('Returns approved', 'anoofy-woo') . '</th></tr></thead><tbody>';

        foreach ($summary->perAgent as $agent) {
            printf(
                '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d (%d answered)</td><td>%s%%</td><td>%d</td></tr>',
                esc_html(Staff::name($agent->userId) ?: ('#' . $agent->userId)),
                $agent->assignedOrders,
                $agent->deliveredOrders,
                $agent->returnedOrders,
                $agent->callsLogged,
                $agent->answeredCalls,
                esc_html(number_format($agent->answerRate(), 1)),
                $agent->returnsApproved
            );
        }

        echo '</tbody></table></div>';
    }

    private static function card(string $title, string $value, string $sub): void
    {
        printf(
            '<div class="anoofy-kpi-card"><div class="anoofy-kpi-title">%s</div><div class="anoofy-kpi-value">%s</div><div class="anoofy-kpi-sub">%s</div></div>',
            esc_html($title),
            esc_html($value),
            esc_html($sub)
        );
    }

    private static function renderPeriodTabs(string $active): void
    {
        $periods = [
            '7d' => __('Last 7 days', 'anoofy-woo'),
            '30d' => __('Last 30 days', 'anoofy-woo'),
            'all' => __('All time', 'anoofy-woo'),
        ];
        $base = admin_url('admin.php?page=anoofy-reports');

        echo '<ul class="subsubsub">';
        $keys = array_keys($periods);
        foreach ($keys as $idx => $key) {
            $sep = $idx < count($keys) - 1 ? ' | ' : '';
            printf(
                '<li><a href="%s" class="%s">%s</a>%s</li>',
                esc_url(add_query_arg('period', $key, $base)),
                $active === $key ? 'current' : '',
                esc_html($periods[$key]),
                $sep
            );
        }
        echo '</ul><div class="clear"></div>';
    }
}

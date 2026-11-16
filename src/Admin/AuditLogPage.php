<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\Services;

/** Global operational Audit Log screen (Owner only, `Action::AuditView`). */
final class AuditLogPage
{
    private const PER_PAGE = 50;

    public static function render(): void
    {
        if (!current_user_can(Action::AuditView->capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'anoofy-woo'), '', ['response' => 403]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $actionFilter = isset($_GET['action_filter']) ? sanitize_text_field(wp_unslash((string) $_GET['action_filter'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderFilter = isset($_GET['filter_order_id']) ? absint(wp_unslash($_GET['filter_order_id'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = max(1, isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1);

        $audit = Services::auditLog();
        $actionParam = $actionFilter !== '' ? $actionFilter : null;
        $orderParam = $orderFilter > 0 ? $orderFilter : null;

        $total = $audit->count($actionParam, null, $orderParam);
        $rows = $audit->search($actionParam, null, $orderParam, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $actions = $audit->distinctActions();

        echo '<div class="wrap"><h1>' . esc_html__('Audit log', 'anoofy-woo') . '</h1>';
        self::renderFilters($actionFilter, $orderFilter, $actions);

        if ($rows === []) {
            echo '<p class="description">' . esc_html__('No audit entries found.', 'anoofy-woo') . '</p></div>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>'
            . esc_html__('When', 'anoofy-woo') . '</th><th>'
            . esc_html__('Order', 'anoofy-woo') . '</th><th>'
            . esc_html__('Employee', 'anoofy-woo') . '</th><th>'
            . esc_html__('Action', 'anoofy-woo') . '</th><th>'
            . esc_html__('Transition', 'anoofy-woo') . '</th><th>'
            . esc_html__('Reason', 'anoofy-woo') . '</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $orderCell = $row['order_id'] !== null
                ? sprintf('<a href="%s">#%d</a>', esc_url(add_query_arg(['page' => 'anoofy', 'order_id' => $row['order_id']], admin_url('admin.php'))), $row['order_id'])
                : '—';
            $from = $row['from_status'] !== null ? OrderStatus::tryFrom($row['from_status']) : null;
            $to = $row['to_status'] !== null ? OrderStatus::tryFrom($row['to_status']) : null;
            $transition = ($from !== null && $to !== null)
                ? Labels::status($from) . ' → ' . Labels::status($to)
                : '—';

            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
                esc_html(OrderBox::when($row['created_at'])),
                $orderCell,
                esc_html(Staff::name($row['user_id']) ?: __('System', 'anoofy-woo')),
                esc_html($row['action']),
                esc_html($transition),
                esc_html($row['reason'] ?? '—')
            );
        }

        echo '</tbody></table>';

        $totalPages = (int) ceil($total / self::PER_PAGE);
        if ($totalPages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => $page,
                'total' => $totalPages,
            ]) ?? '');
            echo '</div></div>';
        }
        echo '</div>';
    }

    /** @param list<string> $actions */
    private static function renderFilters(string $activeAction, int $orderId, array $actions): void
    {
        echo '<form method="get" class="anoofy-audit-filters">';
        echo '<input type="hidden" name="page" value="anoofy-audit-log" />';
        echo '<label>' . esc_html__('Order ID', 'anoofy-woo')
            . ' <input type="number" min="0" name="filter_order_id" value="' . ($orderId > 0 ? esc_attr((string) $orderId) : '') . '" class="small-text" /></label> ';
        echo '<label>' . esc_html__('Action', 'anoofy-woo') . ' <select name="action_filter">';
        echo '<option value="">' . esc_html__('All actions', 'anoofy-woo') . '</option>';
        foreach ($actions as $act) {
            printf('<option value="%s"%s>%s</option>', esc_attr($act), selected($activeAction, $act, false), esc_html($act));
        }
        echo '</select></label> ';
        echo '<button type="submit" class="button">' . esc_html__('Filter', 'anoofy-woo') . '</button>';
        echo '</form>';
    }
}

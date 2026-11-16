<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Returns\ReturnStatus;
use Anoofy\Woo\Services\Services;

/** Admin screen listing RTO and post-delivery customer returns for warehouse inspection and approval. */
final class ReturnsPage
{
    public static function render(): void
    {
        if (!current_user_can(Action::OrderView->capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'anoofy-woo'), '', ['response' => 403]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rawStatus = isset($_GET['status']) ? sanitize_key(wp_unslash((string) $_GET['status'])) : '';
        $statusFilter = $rawStatus !== '' ? ReturnStatus::tryFrom($rawStatus) : null;

        $repo = Services::returns();
        $counts = $repo->countByStatus();
        $rows = $repo->listRecent($statusFilter, 50);

        echo '<div class="wrap"><h1>' . esc_html__('Returns & RTO', 'anoofy-woo') . '</h1>';
        self::renderTabs($statusFilter, $counts);

        if ($rows === []) {
            echo '<p class="description">' . esc_html__('No returns recorded yet.', 'anoofy-woo') . '</p></div>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>'
            . esc_html__('Return', 'anoofy-woo') . '</th><th>'
            . esc_html__('Order', 'anoofy-woo') . '</th><th>'
            . esc_html__('Source', 'anoofy-woo') . '</th><th>'
            . esc_html__('Status', 'anoofy-woo') . '</th><th>'
            . esc_html__('Reason', 'anoofy-woo') . '</th><th>'
            . esc_html__('Opened by', 'anoofy-woo') . '</th><th>'
            . esc_html__('Created', 'anoofy-woo') . '</th><th>'
            . esc_html__('Actions', 'anoofy-woo') . '</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $orderId = (int) $row['order_id'];
            $opsUrl = add_query_arg(['page' => 'anoofy', 'order_id' => $orderId], admin_url('admin.php'));
            printf(
                '<tr><td>#%d</td><td><a href="%s">#%d</a></td><td>%s</td><td><span class="anoofy-badge">%s</span></td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" class="button button-small">%s</a></td></tr>',
                (int) $row['id'],
                esc_url($opsUrl),
                $orderId,
                esc_html(Labels::returnSource($row['source'])),
                esc_html(Labels::returnStatus($row['status'])),
                esc_html($row['reason'] ?? '—'),
                esc_html(Staff::name((int) $row['opened_by']) ?: '—'),
                esc_html(OrderBox::when((string) $row['created_at'])),
                esc_url($opsUrl),
                esc_html__('Inspect / View', 'anoofy-woo')
            );
        }

        echo '</tbody></table></div>';
    }

    /** @param array<string, int> $counts */
    private static function renderTabs(?ReturnStatus $active, array $counts): void
    {
        $total = array_sum($counts);
        $base = admin_url('admin.php?page=anoofy-returns');

        echo '<ul class="subsubsub">';
        printf(
            '<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a> | </li>',
            esc_url($base),
            $active === null ? 'current' : '',
            esc_html__('All', 'anoofy-woo'),
            $total
        );

        $cases = ReturnStatus::cases();
        foreach ($cases as $idx => $status) {
            $url = add_query_arg('status', $status->value, $base);
            $sep = $idx < count($cases) - 1 ? ' | ' : '';
            printf(
                '<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a>%s</li>',
                esc_url($url),
                $active === $status ? 'current' : '',
                esc_html(Labels::returnStatus($status)),
                $counts[$status->value] ?? 0,
                $sep
            );
        }
        echo '</ul><div class="clear"></div>';
    }
}

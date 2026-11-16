<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Connection\OrderHandoverItem;
use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Settlement\SettlementPreview;
use Anoofy\Woo\Core\Settlement\SettlementRowStatus;
use Anoofy\Woo\Services\Services;

/** Admin page for uploading/reconciling courier COD settlement CSV statements. */
final class CodSettlementPage
{
    public static function render(): void
    {
        if (!current_user_can(Action::CodSettle->capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'anoofy-woo'), '', ['response' => 403]);
        }

        $service = Services::codSettlement();
        $pending = $service->pendingSummary();

        $preview = null;
        $courier = '';
        $reference = '';
        $rawCsv = '';

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['anoofy_cod_preview'])) {
            check_admin_referer('anoofy_cod_preview', '_anoofy_nonce');
            $courier = sanitize_text_field(wp_unslash((string) ($_POST['anoofy_courier'] ?? '')));
            $reference = sanitize_text_field(wp_unslash((string) ($_POST['anoofy_reference'] ?? '')));
            $rawCsv = self::readUploadedOrPastedCsv();
            if ($rawCsv !== '') {
                $preview = $service->previewCsv($rawCsv);
            }
        }

        echo '<div class="wrap"><h1>' . esc_html__('COD Settlement', 'anoofy-woo') . '</h1>';
        printf(
            '<p class="description">%s <strong>%d</strong> — %s <strong>%s</strong></p>',
            esc_html__('Delivered orders awaiting settlement:', 'anoofy-woo'),
            $pending['count'],
            esc_html__('Total outstanding COD:', 'anoofy-woo'),
            esc_html(OrderHandoverItem::formatCents($pending['total_cents']))
        );

        self::renderUploadForm($courier, $reference, $rawCsv);

        if ($preview instanceof SettlementPreview) {
            self::renderPreview($preview, $courier, $reference);
        }

        self::renderHistory();
        echo '</div>';
    }

    private static function readUploadedOrPastedCsv(): string
    {
        if (isset($_FILES['anoofy_csv_file']['tmp_name']) && is_string($_FILES['anoofy_csv_file']['tmp_name']) && is_uploaded_file($_FILES['anoofy_csv_file']['tmp_name'])) {
            $contents = file_get_contents($_FILES['anoofy_csv_file']['tmp_name']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if (is_string($contents) && trim($contents) !== '') {
                return $contents;
            }
        }

        return isset($_POST['anoofy_csv_text']) && is_string($_POST['anoofy_csv_text'])
            ? trim((string) wp_unslash($_POST['anoofy_csv_text']))
            : '';
    }

    private static function renderUploadForm(string $courier, string $reference, string $rawCsv): void
    {
        echo '<div class="anoofy-card"><h3>' . esc_html__('Import courier statement (CSV)', 'anoofy-woo') . '</h3>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('anoofy_cod_preview', '_anoofy_nonce');
        echo '<input type="hidden" name="anoofy_cod_preview" value="1" />';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="anoofy_courier">' . esc_html__('Courier', 'anoofy-woo') . '</label></th><td>'
            . '<input type="text" id="anoofy_courier" name="anoofy_courier" required maxlength="100" value="' . esc_attr($courier) . '" placeholder="' . esc_attr__('e.g. Bosta, Aramex', 'anoofy-woo') . '" /></td></tr>';
        echo '<tr><th><label for="anoofy_reference">' . esc_html__('Statement reference', 'anoofy-woo') . '</label></th><td>'
            . '<input type="text" id="anoofy_reference" name="anoofy_reference" maxlength="100" value="' . esc_attr($reference) . '" /></td></tr>';
        echo '<tr><th><label for="anoofy_csv_file">' . esc_html__('CSV file', 'anoofy-woo') . '</label></th><td>'
            . '<input type="file" id="anoofy_csv_file" name="anoofy_csv_file" accept=".csv,text/csv" /></td></tr>';
        echo '<tr><th><label for="anoofy_csv_text">' . esc_html__('Or paste CSV lines', 'anoofy-woo') . '</label></th><td>'
            . '<textarea id="anoofy_csv_text" name="anoofy_csv_text" rows="5" class="large-text code" placeholder="tracking_number,amount&#10;BOSTA-101,350.00">' . esc_textarea($rawCsv) . '</textarea></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Preview statement', 'anoofy-woo'));
        echo '</form></div>';
    }

    private static function renderPreview(SettlementPreview $preview, string $courier, string $reference): void
    {
        echo '<div class="anoofy-card"><h3>' . esc_html__('Reconciliation preview', 'anoofy-woo') . '</h3>';

        if ($preview->parseErrors !== []) {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Skipped CSV lines:', 'anoofy-woo') . '</strong></p><ul>';
            foreach ($preview->parseErrors as $err) {
                echo '<li><code>' . esc_html($err) . '</code></li>';
            }
            echo '</ul></div>';
        }

        if ($preview->rows === []) {
            echo '<p>' . esc_html__('No valid rows found in CSV.', 'anoofy-woo') . '</p></div>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="anoofy_cod_settle" />';
        wp_nonce_field('anoofy_cod_settle', '_anoofy_nonce');
        echo '<input type="hidden" name="anoofy_courier" value="' . esc_attr($courier) . '" />';
        echo '<input type="hidden" name="anoofy_reference" value="' . esc_attr($reference) . '" />';

        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Settle', 'anoofy-woo') . '</th>'
            . '<th>' . esc_html__('Tracking number', 'anoofy-woo') . '</th>'
            . '<th>' . esc_html__('Order', 'anoofy-woo') . '</th>'
            . '<th>' . esc_html__('Collected', 'anoofy-woo') . '</th>'
            . '<th>' . esc_html__('Order total', 'anoofy-woo') . '</th>'
            . '<th>' . esc_html__('Match status', 'anoofy-woo') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($preview->rows as $match) {
            $isMatched = $match->status === SettlementRowStatus::Matched && $match->candidate !== null;
            $orderCell = $match->candidate !== null
                ? '#' . $match->candidate->orderId
                : '—';
            $orderTotalCell = $match->candidate !== null
                ? OrderHandoverItem::formatCents($match->candidate->orderTotalCents)
                : '—';

            $checkbox = $isMatched
                ? sprintf(
                    '<input type="checkbox" name="settle_orders[%d]" value="%d" checked />',
                    $match->candidate->orderId,
                    $match->line->collectedCents
                )
                : '—';

            printf(
                '<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $checkbox,
                esc_html($match->line->trackingNumber),
                esc_html($orderCell),
                esc_html(OrderHandoverItem::formatCents($match->line->collectedCents)),
                esc_html($orderTotalCell),
                esc_html(Labels::settlementRowStatus($match->status)),
            );
        }
        echo '</tbody></table>';

        printf(
            '<p><strong>%s</strong> %d (%s)</p>',
            esc_html__('Matched orders ready to settle:', 'anoofy-woo'),
            count($preview->matched),
            esc_html(OrderHandoverItem::formatCents($preview->matchedTotalCents))
        );

        if ($preview->matched !== []) {
            submit_button(__('Settle selected orders', 'anoofy-woo'));
        }
        echo '</form></div>';
    }

    private static function renderHistory(): void
    {
        $batches = Services::settlements()->recent(20);
        echo '<div class="anoofy-card"><h3>' . esc_html__('Recent COD settlements', 'anoofy-woo') . '</h3>';
        if ($batches === []) {
            echo '<p class="description">' . esc_html__('No settlements recorded yet.', 'anoofy-woo') . '</p></div>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('#', 'anoofy-woo') . '</th><th>'
            . esc_html__('When', 'anoofy-woo') . '</th><th>' . esc_html__('Courier', 'anoofy-woo') . '</th><th>'
            . esc_html__('Statement reference', 'anoofy-woo') . '</th><th>' . esc_html__('Orders', 'anoofy-woo') . '</th><th>'
            . esc_html__('Total', 'anoofy-woo') . '</th><th>' . esc_html__('Employee', 'anoofy-woo') . '</th></tr></thead><tbody>';

        foreach ($batches as $b) {
            printf(
                '<tr><td>#%d</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                $b['id'],
                esc_html(OrderBox::when($b['created_at'])),
                esc_html($b['courier']),
                esc_html($b['reference'] ?? '—'),
                $b['total_orders'],
                esc_html(OrderHandoverItem::formatCents($b['total_cents'])),
                esc_html(Staff::name($b['created_by'])),
            );
        }
        echo '</tbody></table></div>';
    }
}

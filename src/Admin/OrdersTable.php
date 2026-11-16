<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Order\OrderOps;
use Anoofy\Woo\Services\Services;

if (!class_exists(\WP_List_Table::class)) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/** The Orders screen table. Reads our own table for status/assignment and WooCommerce CRUD for order data. */
final class OrdersTable extends \WP_List_Table
{
    private const PER_PAGE = 20;

    /**
     * @param list<string>|null $statuses
     * @param list<int>|null    $orderIds
     */
    public function __construct(
        private readonly ?array $statuses,
        private readonly int|string|null $assigned,
        private readonly ?array $orderIds,
    ) {
        parent::__construct(['singular' => 'order', 'plural' => 'orders', 'ajax' => false]);
    }

    public function get_columns(): array
    {
        $columns = [
            'cb' => '<input type="checkbox" />',
            'order' => __('Order', 'anoofy-woo'),
            'customer' => __('Customer', 'anoofy-woo'),
            'total' => __('Total', 'anoofy-woo'),
            'ops_status' => __('Operational status', 'anoofy-woo'),
            'shipment' => __('Shipment', 'anoofy-woo'),
            'woo_status' => __('WooCommerce status', 'anoofy-woo'),
            'assigned' => __('Assigned to', 'anoofy-woo'),
            'date' => __('Date', 'anoofy-woo'),
        ];
        if (!current_user_can(Action::OrderAssign->capability())) {
            unset($columns['cb']);
        }

        return $columns;
    }

    public function prepare_items(): void
    {
        $repo = Services::repository();
        $page = $this->get_pagenum();
        $total = $repo->count($this->statuses, $this->assigned, $this->orderIds);
        $this->set_pagination_args(['total_items' => $total, 'per_page' => self::PER_PAGE]);
        $this->_column_headers = [$this->get_columns(), [], []];

        $this->items = [];
        foreach ($repo->search($this->statuses, $this->assigned, $this->orderIds, self::PER_PAGE, ($page - 1) * self::PER_PAGE) as $ops) {
            $this->items[] = ['ops' => $ops, 'order' => wc_get_order($ops->orderId)];
        }
    }

    /** @param array{ops: OrderOps, order: \WC_Order|false|null} $item */
    public function column_cb($item): string
    {
        return sprintf('<input type="checkbox" name="order_ids[]" value="%d" />', $item['ops']->orderId);
    }

    /** @param array{ops: OrderOps, order: \WC_Order|false|null} $item */
    public function column_order($item): string
    {
        $id = $item['ops']->orderId;
        $order = $item['order'];
        if (!$order instanceof \WC_Order) {
            /* translators: %d: order number */
            return esc_html(sprintf(__('#%d (deleted)', 'anoofy-woo'), $id));
        }

        $opsUrl = add_query_arg(['page' => 'anoofy', 'order_id' => $id], admin_url('admin.php'));
        if (!current_user_can('edit_shop_orders')) {
            return sprintf('<a href="%s"><strong>#%s</strong></a>', esc_url($opsUrl), esc_html($order->get_order_number()));
        }

        return sprintf(
            '<a href="%s"><strong>#%s</strong></a><br><a href="%s" class="description">%s</a>',
            esc_url($order->get_edit_order_url()),
            esc_html($order->get_order_number()),
            esc_url($opsUrl),
            esc_html__('Operations', 'anoofy-woo'),
        );
    }

    /** @param array{ops: OrderOps, order: \WC_Order|false|null} $item */
    public function column_customer($item): string
    {
        $order = $item['order'];
        if (!$order instanceof \WC_Order) {
            return '';
        }
        $html = esc_html(trim($order->get_formatted_billing_full_name()));
        // Phone and city only for roles that may see customer contact details (warehouse staff do not).
        if (current_user_can(Action::OrderViewCustomerContact->capability())) {
            $phone = $order->get_billing_phone();
            $city = $order->get_billing_city();
            if ($phone !== '') {
                $html .= '<br><span dir="ltr">' . esc_html($phone) . '</span>';
            }
            if ($city !== '') {
                $html .= '<br><span class="description">' . esc_html($city) . '</span>';
            }
        }

        return $html;
    }

    /** @param array{ops: OrderOps, order: \WC_Order|false|null} $item */
    public function column_total($item): string
    {
        return $item['order'] instanceof \WC_Order ? wp_kses_post($item['order']->get_formatted_order_total()) : '';
    }

    /** @param array{ops: OrderOps, order: \WC_Order|false|null} $item */
    public function column_ops_status($item): string
    {
        $ops = $item['ops'];
        $status = $ops->status;

        $html = sprintf(
            '<span class="anoofy-badge anoofy-badge--%s">%s</span>',
            esc_attr(strtolower(str_replace('_', '-', $status->value))),
            esc_html(Labels::status($status)),
        );

        if ($ops->isHandedOver()) {
            $stage = $ops->engazStage;
            $stageClass = $stage !== null
                ? 'anoofy-stage--' . strtolower(str_replace('_', '-', $stage->value))
                : 'anoofy-stage--waiting';
            $html .= sprintf(
                ' <span class="anoofy-badge anoofy-stage-badge %s">%s</span>',
                esc_attr($stageClass),
                esc_html(Labels::engazStage($stage))
            );
        }

        return $html;
    }

    /** @param array{ops: OrderOps, order: \WC_Order|false|null} $item */
    public function column_shipment($item): string
    {
        $ops = $item['ops'];
        $courier = $ops->isHandedOver() ? $ops->engazCourier : $ops->courier;
        $tracking = $ops->isHandedOver() ? $ops->engazTracking : $ops->trackingNumber;

        if (($courier === null || $courier === '') && ($tracking === null || $tracking === '')) {
            return '<span class="description">—</span>';
        }

        $parts = [];
        if ($courier !== null && $courier !== '') {
            $parts[] = esc_html($courier);
        }
        if ($tracking !== null && $tracking !== '') {
            $parts[] = '<code>' . esc_html($tracking) . '</code>';
        }
        if (!$ops->isHandedOver() && $ops->deliveryAttempts > 0) {
            $parts[] = '<span class="description">' . esc_html(sprintf(
                /* translators: %d: number of failed delivery attempts */
                __('Failed attempts: %d', 'anoofy-woo'),
                $ops->deliveryAttempts
            )) . '</span>';
        }
        if (!$ops->isHandedOver() && $ops->isCodSettled()) {
            $parts[] = '<span class="anoofy-badge anoofy-badge--confirmed">' . esc_html__('COD settled', 'anoofy-woo') . '</span>';
        }

        return implode('<br>', $parts);
    }

    /** @param array{ops: OrderOps, order: \WC_Order|false|null} $item */
    public function column_woo_status($item): string
    {
        return $item['order'] instanceof \WC_Order ? esc_html(wc_get_order_status_name($item['order']->get_status())) : '';
    }

    /** @param array{ops: OrderOps, order: \WC_Order|false|null} $item */
    public function column_assigned($item): string
    {
        $name = Staff::name($item['ops']->assignedTo);

        return $name === '' ? '<span class="description">—</span>' : esc_html($name);
    }

    /** @param array{ops: OrderOps, order: \WC_Order|false|null} $item */
    public function column_date($item): string
    {
        $created = $item['order'] instanceof \WC_Order ? $item['order']->get_date_created() : null;

        return $created !== null ? esc_html(wc_format_datetime($created, get_option('date_format') . ' ' . get_option('time_format'))) : '';
    }

    public function no_items(): void
    {
        esc_html_e('No orders found.', 'anoofy-woo');
    }

    /** Bulk assignment controls (top of the table only). */
    protected function extra_tablenav($which): void
    {
        if ($which !== 'top' || !current_user_can(Action::OrderAssign->capability())) {
            return;
        }
        echo '<div class="alignleft actions anoofy-assign-bar"><select name="anoofy_assignee">';
        echo '<option value="0">' . esc_html__('Unassigned', 'anoofy-woo') . '</option>';
        foreach (Staff::assignable() as $id => $name) {
            printf('<option value="%d">%s</option>', $id, esc_html($name));
        }
        echo '</select><button type="submit" class="button">' . esc_html__('Assign selected', 'anoofy-woo') . '</button></div>';
    }
}

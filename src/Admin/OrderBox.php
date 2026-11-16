<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Connection\OutboxStatus;
use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Identity\Permissions;
use Anoofy\Woo\Core\Identity\Role;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderOps;
use Anoofy\Woo\Core\Order\OrderStateMachine;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Core\Returns\ReturnStatus;
use Anoofy\Woo\Services\Services;

/**
 * "Anoofy" box on the WooCommerce order screen (HPOS and legacy). It is rendered inside WooCommerce's own order form,
 * so it has NO <form> of its own (nested forms are invalid) and WooCommerce's hidden "action" field would clash with
 * admin-post.php. Buttons are driven by assets/js/order-box.js, which posts only this box's fields.
 */
final class OrderBox
{
    private const DECISION_EVENTS = [OrderEvent::Confirm, OrderEvent::Reject, OrderEvent::CancelDirect, OrderEvent::RequestCancel];

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'add']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    private static function screenId(): string
    {
        return function_exists('wc_get_page_screen_id') ? (string) wc_get_page_screen_id('shop-order') : 'shop_order';
    }

    public static function add(): void
    {
        if (!current_user_can(Action::OrderView->capability())) {
            return;
        }
        add_meta_box('anoofy_ops', __('Anoofy: order operations', 'anoofy-woo'), [self::class, 'render'], self::screenId(), 'normal', 'high');
    }

    public static function assets(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen instanceof \WP_Screen || $screen->id !== self::screenId()) {
            return;
        }
        $base = plugin_dir_url(ANOOFY_WOO_FILE);
        wp_enqueue_style('anoofy-admin', $base . 'assets/css/admin.css', [], ANOOFY_WOO_VERSION);
        self::enqueueScripts();
    }

    public static function enqueueScripts(): void
    {
        $base = plugin_dir_url(ANOOFY_WOO_FILE);
        wp_enqueue_script('anoofy-order-box', $base . 'assets/js/order-box.js', [], ANOOFY_WOO_VERSION, true);
        wp_localize_script('anoofy-order-box', 'anoofyOrderBox', [
            'endpoint' => admin_url('admin-post.php'),
            'sending' => __('Sending…', 'anoofy-woo'),
            OutboxStatus::Failed->value => __('The request failed. Check your connection and try again.', 'anoofy-woo'),
        ]);
    }

    /** @param \WP_Post|\WC_Order $subject WooCommerce passes a WC_Order with HPOS and a WP_Post otherwise */
    public static function render($subject): void
    {
        $order = $subject instanceof \WC_Order ? $subject : wc_get_order($subject instanceof \WP_Post ? $subject->ID : 0);
        if (!$order instanceof \WC_Order) {
            return;
        }

        self::renderOpsBox($order);
    }

    public static function renderOpsBox(\WC_Order $order, string $returnView = ''): void
    {
        $orderId = $order->get_id();
        $service = Services::orderOps();
        $service->track($orderId); // orders that existed before the plugin get their row the first time they are opened
        $ops = Services::repository()->find($orderId);
        if ($ops === null) {
            echo '<p>' . esc_html__('This order is not tracked yet: it is still a draft or awaiting payment.', 'anoofy-woo') . '</p>';

            return;
        }

        $role = $service->roleFor(get_current_user_id());
        $nonce = wp_create_nonce('anoofy_order_' . $orderId);
        $returnAttr = $returnView !== '' ? ' data-anoofy-return="' . esc_attr($returnView) . '"' : '';

        echo '<div class="anoofy-box" data-anoofy-box data-order-id="' . esc_attr((string) $orderId) . '" data-nonce="' . esc_attr($nonce) . '"' . $returnAttr . '>';
        self::outboxAlert($orderId);
        self::retryHandoverAlert($orderId);
        self::summary($ops);
        if ($ops->isHandedOver()) {
            self::engazDetails($ops);
        }
        self::assignment($ops, $orderId);
        if ($role !== null) {
            self::callForm($ops, $role, $orderId);
            self::decisions($ops, $role);
            if (!$ops->isHandedOver()) {
                self::preparation($order, $ops, $role);
                self::shipping($ops, $role);
                self::delivery($ops, $role);
                self::returns($order, $ops, $role);
            }
        }
        self::callHistory($orderId);
        if (!$ops->isHandedOver()) {
            self::deliveryHistory($orderId);
        }
        self::history($orderId);
        echo '</div>';
    }

    private static function outboxAlert(int $orderId): void
    {
        $latest = Services::outbox()->getLatestForOrder($orderId);
        if ($latest === null || $latest['status'] !== OutboxStatus::Failed->value) {
            return;
        }

        echo '<div class="notice notice-error anoofy-box-alert">';
        echo '<p><strong>' . esc_html__('Failed to send order to EngazPRO.', 'anoofy-woo') . '</strong></p>';
        if (!empty($latest['last_error'])) {
            echo '<p><code>' . esc_html((string) $latest['last_error']) . '</code></p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('anoofy_retry_outbox_' . $orderId, '_anoofy_nonce');
        echo '<input type="hidden" name="action" value="anoofy_retry_outbox" />';
        echo '<input type="hidden" name="anoofy_outbox_id" value="' . esc_attr((string) $latest['id']) . '" />';
        echo '<input type="hidden" name="anoofy_order_id" value="' . esc_attr((string) $orderId) . '" />';
        echo '<button type="submit" class="button button-secondary">' . esc_html__('Retry send to EngazPRO', 'anoofy-woo') . '</button>';
        echo '</form></div>';
    }

    private static function retryHandoverAlert(int $orderId): void
    {
        if (!Services::orderOps()->canRetryHandover($orderId)) {
            return;
        }

        echo '<div class="notice notice-error anoofy-box-alert">';
        echo '<p><strong>' . esc_html__('Failed to handover order to EngazPRO.', 'anoofy-woo') . '</strong></p>';
        if (current_user_can(Action::IntegrationManage->capability())) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('anoofy_retry_handover_' . $orderId, '_anoofy_nonce');
            echo '<input type="hidden" name="action" value="anoofy_retry_handover" />';
            echo '<input type="hidden" name="anoofy_order_id" value="' . esc_attr((string) $orderId) . '" />';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Retry Handover to EngazPRO', 'anoofy-woo') . '</button>';
            echo '</form>';
        }
        echo '</div>';
    }

    private static function summary(OrderOps $ops): void
    {
        printf(
            '<p class="anoofy-summary"><strong>%s</strong> <span class="anoofy-badge anoofy-badge--%s">%s</span>',
            esc_html__('Operational status:', 'anoofy-woo'),
            esc_attr(strtolower(str_replace('_', '-', $ops->status->value))),
            esc_html(Labels::status($ops->status)),
        );
        if ($ops->isHandedOver()) {
            printf(
                ' <span class="anoofy-badge anoofy-badge--handed-over">%s%s</span>',
                esc_html__('Handed over to EngazPRO', 'anoofy-woo'),
                $ops->engazHandoverAt ? ' (' . esc_html($ops->engazHandoverAt) . ')' : ''
            );
        }
        echo '</p>';
    }

    private static function engazDetails(OrderOps $ops): void
    {
        echo '<div class="anoofy-section anoofy-engaz-details">';
        echo '<h4>' . esc_html__('EngazPRO status', 'anoofy-woo') . '</h4>';

        $stage = $ops->engazStage;
        $stageClass = $stage !== null
            ? 'anoofy-stage--' . strtolower(str_replace('_', '-', $stage->value))
            : 'anoofy-stage--waiting';
        $stageLabel = Labels::engazStage($stage);

        echo '<p><strong>' . esc_html__('Stage:', 'anoofy-woo') . '</strong> ';
        printf('<span class="anoofy-badge anoofy-stage-badge %s">%s</span></p>', esc_attr($stageClass), esc_html($stageLabel));

        if ($ops->engazBranch) {
            echo '<p><strong>' . esc_html__('Branch:', 'anoofy-woo') . '</strong> ' . esc_html($ops->engazBranch) . '</p>';
        }

        if ($ops->engazCourier) {
            echo '<p><strong>' . esc_html__('Courier:', 'anoofy-woo') . '</strong> ' . esc_html($ops->engazCourier) . '</p>';
        }

        if ($ops->engazTracking) {
            echo '<p><strong>' . esc_html__('Tracking number:', 'anoofy-woo') . '</strong> <code>' . esc_html($ops->engazTracking) . '</code></p>';
        }

        if ($ops->engazStageAt) {
            $ts = strtotime($ops->engazStageAt);
            $formattedDate = $ts !== false ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts) : $ops->engazStageAt;
            echo '<p><strong>' . esc_html__('Last stage update:', 'anoofy-woo') . '</strong> ' . esc_html($formattedDate) . '</p>';
        }

        echo '</div>';
    }

    private static function assignment(OrderOps $ops, int $orderId): void
    {
        echo '<div class="anoofy-section"><h4>' . esc_html__('Assigned to', 'anoofy-woo') . '</h4>';
        if ($ops->isHandedOver() || !current_user_can(Action::OrderAssign->capability())) {
            $name = Staff::name($ops->assignedTo);
            echo '<p>' . esc_html($name !== '' ? $name : __('Unassigned', 'anoofy-woo')) . '</p></div>';

            return;
        }
        echo '<div data-anoofy-form="anoofy_assign"><select name="anoofy_assignee">';
        echo '<option value="0">' . esc_html__('Unassigned', 'anoofy-woo') . '</option>';
        foreach (Staff::assignable() as $id => $name) {
            printf('<option value="%d"%s>%s</option>', $id, selected($ops->assignedTo, $id, false), esc_html($name));
        }
        echo '</select> <button type="button" class="button" data-anoofy-submit>' . esc_html__('Save', 'anoofy-woo') . '</button></div></div>';
    }

    private static function callForm(OrderOps $ops, Role $role, int $orderId): void
    {
        $canLog = !$ops->isHandedOver()
            && Permissions::can($role, Action::OrderLogCall)
            && in_array($ops->status, [OrderStatus::New, OrderStatus::UnderReview], true);
        if (!$canLog) {
            return;
        }
        echo '<div class="anoofy-section"><h4>' . esc_html__('Log a call', 'anoofy-woo') . '</h4>';
        echo '<div data-anoofy-form="anoofy_log_call"><select name="anoofy_outcome">';
        foreach (CallOutcome::cases() as $outcome) {
            printf('<option value="%s">%s</option>', esc_attr($outcome->value), esc_html(Labels::outcome($outcome)));
        }
        echo '</select> ';
        echo '<input type="text" name="anoofy_note" maxlength="1000" class="anoofy-note" placeholder="' . esc_attr__('Note (optional)', 'anoofy-woo') . '"> ';
        echo '<button type="button" class="button button-primary" data-anoofy-submit>' . esc_html__('Log call', 'anoofy-woo') . '</button></div></div>';
    }

    private static function decisions(OrderOps $ops, Role $role): void
    {
        if ($ops->isHandedOver()) {
            echo '<div class="anoofy-section"><h4>' . esc_html__('Decision', 'anoofy-woo') . '</h4>';
            echo '<p class="notice notice-info anoofy-box-info">'
                . esc_html__('The order is being processed in EngazPRO. Operational actions are managed there.', 'anoofy-woo')
                . '</p></div>';

            return;
        }

        $events = [];
        foreach (OrderStateMachine::eventsFrom($ops->status) as $event) {
            if (in_array($event, self::DECISION_EVENTS, true) && Permissions::can($role, OrderStateMachine::actionFor($event))) {
                $events[] = $event;
            }
        }
        if ($events === []) {
            return;
        }

        echo '<div class="anoofy-section"><h4>' . esc_html__('Decision', 'anoofy-woo') . '</h4>';
        if ($ops->status === OrderStatus::UnderReview && !$ops->hasLoggedCall) {
            echo '<p class="description">' . esc_html__('Log an answered call before confirming.', 'anoofy-woo') . '</p>';
        }
        echo '<div data-anoofy-form="anoofy_order_event">';
        echo '<textarea name="anoofy_reason" rows="2" maxlength="1000" class="anoofy-reason" placeholder="' . esc_attr__('Reason (required to reject or cancel)', 'anoofy-woo') . '"></textarea>';
        echo '<p>';
        foreach ($events as $event) {
            $class = $event === OrderEvent::Confirm ? 'button button-primary' : 'button';
            printf(
                '<button type="button" class="%s" data-anoofy-submit data-anoofy-event="%s">%s</button> ',
                esc_attr($class),
                esc_attr($event->value),
                esc_html(Labels::event($event)),
            );
        }
        echo '</p></div></div>';
    }

    private static function preparation(\WC_Order $order, OrderOps $ops, Role $role): void
    {
        if ($ops->status === OrderStatus::Confirmed && Permissions::can($role, Action::OrderPrepare)) {
            echo '<div class="anoofy-section"><h4>' . esc_html__('Warehouse preparation', 'anoofy-woo') . '</h4>';
            if ($ops->assignedTo === null) {
                echo '<p class="description">' . esc_html__('Assign the order to an employee before starting preparation.', 'anoofy-woo') . '</p>';
            }
            echo '<div data-anoofy-form="anoofy_order_event"><button type="button" class="button button-primary" data-anoofy-submit data-anoofy-event="' . esc_attr(OrderEvent::StartPreparing->value) . '">'
                . esc_html(Labels::event(OrderEvent::StartPreparing)) . '</button></div></div>';

            return;
        }

        if ($ops->status === OrderStatus::CancellationPending) {
            echo '<div class="anoofy-section"><h4>' . esc_html__('Warehouse restock confirmation', 'anoofy-woo') . '</h4>';
            echo '<div class="notice notice-warning inline anoofy-box-alert"><p>'
                . esc_html__('Cancellation was requested while preparing. Confirm once the items are back on the shelf to cancel the WooCommerce order.', 'anoofy-woo')
                . '</p></div>';
            if (Permissions::can($role, Action::OrderConfirmRestock)) {
                echo '<div data-anoofy-form="anoofy_order_event"><button type="button" class="button button-primary" data-anoofy-submit data-anoofy-event="' . esc_attr(OrderEvent::ConfirmRestocked->value) . '">'
                    . esc_html(Labels::event(OrderEvent::ConfirmRestocked)) . '</button></div>';
            }
            echo '</div>';

            return;
        }

        if ($ops->status !== OrderStatus::Preparing) {
            return;
        }

        echo '<div class="anoofy-section"><h4>' . esc_html__('Warehouse preparation', 'anoofy-woo') . '</h4>';
        if ($ops->editedAfterPreparing) {
            echo '<div class="notice notice-warning inline anoofy-box-alert"><p>'
                . esc_html__('Order items were modified during preparation. Re-check the items and confirm re-preparation.', 'anoofy-woo')
                . '</p>';
            if (Permissions::can($role, Action::OrderConfirmRepreparation)) {
                echo '<div data-anoofy-form="anoofy_confirm_repreparation"><button type="button" class="button button-secondary" data-anoofy-submit>'
                    . esc_html__('Confirm re-preparation', 'anoofy-woo') . '</button></div>';
            }
            echo '</div>';
        }

        $canPrepare = Permissions::can($role, Action::OrderPrepare);
        $preparedIds = Services::preparedItems()->forOrder($order->get_id());
        $preparedLookup = array_flip($preparedIds);

        echo '<div data-anoofy-form="anoofy_save_prepared_items">';
        echo '<table class="widefat striped anoofy-table"><thead><tr><th>' . esc_html__('Prepared', 'anoofy-woo') . '</th><th>'
            . esc_html__('Product', 'anoofy-woo') . '</th><th>' . esc_html__('SKU', 'anoofy-woo') . '</th><th>'
            . esc_html__('Qty', 'anoofy-woo') . '</th></tr></thead><tbody>';

        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $itemId = $item->get_id();
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';
            $checked = isset($preparedLookup[$itemId]) ? ' checked' : '';
            $disabled = $canPrepare ? '' : ' disabled';

            printf(
                '<tr><td><input type="checkbox" name="prepared_items[]" value="%d"%s%s /></td><td>%s</td><td>%s</td><td>%d</td></tr>',
                $itemId,
                $checked,
                $disabled,
                esc_html($item->get_name()),
                esc_html($sku !== '' ? $sku : '—'),
                $item->get_quantity(),
            );
        }
        echo '</tbody></table>';

        if ($canPrepare) {
            echo '<div class="anoofy-prep-actions">';
            echo '<button type="button" class="button" data-anoofy-submit>' . esc_html__('Save preparation checklist', 'anoofy-woo') . '</button>';
            echo '</div>';
        }
        echo '</div>';

        if ($canPrepare) {
            echo '<div class="anoofy-prep-actions" data-anoofy-form="anoofy_order_event">';
            echo '<button type="button" class="button button-primary" data-anoofy-submit data-anoofy-event="' . esc_attr(OrderEvent::FinishPreparing->value) . '">'
                . esc_html(Labels::event(OrderEvent::FinishPreparing)) . '</button>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function shipping(OrderOps $ops, Role $role): void
    {
        if ($ops->status === OrderStatus::ReadyToShip) {
            echo '<div class="anoofy-section"><h4>' . esc_html__('Shipping', 'anoofy-woo') . '</h4>';
            if (Permissions::can($role, Action::ShipmentCreate)) {
                echo '<div data-anoofy-form="anoofy_save_shipment"><div class="anoofy-ship-fields">';
                echo '<label>' . esc_html__('Courier', 'anoofy-woo')
                    . '<input type="text" name="anoofy_courier" list="anoofy-courier-list" maxlength="100" value="' . esc_attr($ops->courier ?? '') . '" placeholder="' . esc_attr__('e.g. Bosta, Aramex', 'anoofy-woo') . '" /></label>';
                echo '<datalist id="anoofy-courier-list">';
                foreach (Services::courierManager()->all() as $gateway) {
                    if ($gateway->slug() === 'manual') {
                        continue;
                    }
                    echo '<option value="' . esc_attr($gateway->label()) . '"></option>';
                }
                echo '</datalist>';
                echo '<label>' . esc_html__('Tracking number', 'anoofy-woo')
                    . '<input type="text" name="anoofy_tracking" maxlength="100" value="' . esc_attr($ops->trackingNumber ?? '') . '" /></label>';
                echo '<button type="button" class="button" data-anoofy-submit>' . esc_html__('Save shipment details', 'anoofy-woo') . '</button>';
                echo '</div></div>';
                echo '<div class="anoofy-ship-actions" data-anoofy-form="anoofy_order_event">';
                echo '<button type="button" class="button button-primary" data-anoofy-submit data-anoofy-event="' . esc_attr(OrderEvent::Ship->value) . '">'
                    . esc_html(Labels::event(OrderEvent::Ship)) . '</button>';
                echo '</div>';
            } elseif ($ops->courier || $ops->trackingNumber) {
                echo '<p><strong>' . esc_html__('Courier:', 'anoofy-woo') . '</strong> ' . esc_html($ops->courier ?? '—') . '</p>';
                echo '<p><strong>' . esc_html__('Tracking number:', 'anoofy-woo') . '</strong> <code>' . esc_html($ops->trackingNumber ?? '—') . '</code></p>';
            }
            echo '</div>';

            return;
        }

        if (in_array($ops->status, [OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::DeliveryFailed, OrderStatus::ReturnedToOrigin], true)
            && ($ops->courier !== null || $ops->trackingNumber !== null || $ops->isCodSettled())
        ) {
            echo '<div class="anoofy-section"><h4>' . esc_html__('Shipping', 'anoofy-woo') . '</h4>';
            if ($ops->courier !== null || $ops->trackingNumber !== null) {
                echo '<p><strong>' . esc_html__('Courier:', 'anoofy-woo') . '</strong> ' . esc_html($ops->courier ?? '—') . '</p>';
                echo '<p><strong>' . esc_html__('Tracking number:', 'anoofy-woo') . '</strong> <code>' . esc_html($ops->trackingNumber ?? '—') . '</code></p>';
            }
            if ($ops->isCodSettled()) {
                echo '<p><strong>' . esc_html__('COD settled:', 'anoofy-woo') . '</strong> '
                    . esc_html(self::when((string) $ops->codSettledAt))
                    . ($ops->codSettlementId ? ' (#' . (int) $ops->codSettlementId . ')' : '')
                    . '</p>';
            }
            echo '</div>';
        }
    }

    private static function delivery(OrderOps $ops, Role $role): void
    {
        if (!in_array($ops->status, [OrderStatus::Shipped, OrderStatus::DeliveryFailed], true)) {
            return;
        }

        $maxAttempts = Services::orderOps()->maxDeliveryAttempts();
        echo '<div class="anoofy-section"><h4>' . esc_html__('Delivery', 'anoofy-woo') . '</h4>';
        echo '<p><strong>' . esc_html__('Failed delivery attempts:', 'anoofy-woo') . '</strong> '
            . esc_html(sprintf('%d / %d', $ops->deliveryAttempts, $maxAttempts)) . '</p>';

        if ($ops->status === OrderStatus::Shipped && Permissions::can($role, Action::DeliveryRecord)) {
            echo '<div data-anoofy-form="anoofy_order_event">';
            echo '<textarea name="anoofy_reason" rows="2" maxlength="1000" class="anoofy-reason" placeholder="' . esc_attr__('Reason (required if delivery failed)', 'anoofy-woo') . '"></textarea>';
            echo '<div class="anoofy-ship-actions">';
            echo '<button type="button" class="button button-primary" data-anoofy-submit data-anoofy-event="' . esc_attr(OrderEvent::MarkDelivered->value) . '">'
                . esc_html(Labels::event(OrderEvent::MarkDelivered)) . '</button>';
            echo '<button type="button" class="button" data-anoofy-submit data-anoofy-event="' . esc_attr(OrderEvent::MarkDeliveryFailed->value) . '">'
                . esc_html(Labels::event(OrderEvent::MarkDeliveryFailed)) . '</button>';
            echo '</div></div>';
        } elseif ($ops->status === OrderStatus::DeliveryFailed) {
            if ($ops->deliveryAttempts >= $maxAttempts) {
                echo '<div class="notice notice-warning inline anoofy-box-alert"><p>'
                    . esc_html__('Maximum delivery attempts reached. Receive the returned shipment back at the warehouse.', 'anoofy-woo')
                    . '</p></div>';
            }

            $canRetry = $ops->deliveryAttempts < $maxAttempts && Permissions::can($role, Action::DeliveryRetry);
            $canRto = Permissions::can($role, Action::ReturnReceiveAndInspect);

            if ($canRetry || $canRto) {
                echo '<div class="anoofy-ship-actions" data-anoofy-form="anoofy_order_event">';
                if ($canRetry) {
                    echo '<button type="button" class="button button-primary" data-anoofy-submit data-anoofy-event="' . esc_attr(OrderEvent::RetryDelivery->value) . '">'
                        . esc_html(Labels::event(OrderEvent::RetryDelivery)) . '</button>';
                }
                if ($canRto) {
                    echo '<button type="button" class="button" data-anoofy-submit data-anoofy-event="' . esc_attr(OrderEvent::ReceiveReturned->value) . '">'
                        . esc_html(Labels::event(OrderEvent::ReceiveReturned)) . '</button>';
                }
                echo '</div>';
            }
        }
        echo '</div>';
    }

    private static function returns(\WC_Order $order, OrderOps $ops, Role $role): void
    {
        $orderId = $order->get_id();
        $returns = Services::returns()->forOrder($orderId);
        $canOpen = $ops->status === OrderStatus::Delivered && Permissions::can($role, Action::ReturnOpen);
        if (!$canOpen && $returns === []) {
            return;
        }

        echo '<div class="anoofy-section"><h4>' . esc_html__('Returns & RTO', 'anoofy-woo') . '</h4>';

        foreach ($returns as $ret) {
            self::renderSingleReturn($ret, $role);
        }

        if ($canOpen) {
            self::renderOpenReturnForm($order);
        }

        self::stockMovementsHistory($orderId);
        echo '</div>';
    }

    /** @param array<string, mixed> $ret */
    private static function renderSingleReturn(array $ret, Role $role): void
    {
        /** @var ReturnStatus $status */
        $status = $ret['status'];
        $canInspect = $status !== ReturnStatus::Approved && Permissions::can($role, Action::ReturnReceiveAndInspect);
        $canApprove = $status === ReturnStatus::Inspected && Permissions::can($role, Action::ReturnApprove);

        echo '<div class="anoofy-return-card">';
        printf(
            '<p><strong>#%d — %s</strong> <span class="anoofy-badge">%s</span>%s</p>',
            (int) $ret['id'],
            esc_html(Labels::returnSource($ret['source'])),
            esc_html(Labels::returnStatus($status)),
            $ret['reason'] ? ' — ' . esc_html((string) $ret['reason']) : ''
        );

        if ($canInspect) {
            echo '<div data-anoofy-form="anoofy_inspect_return">';
            echo '<input type="hidden" name="return_id" value="' . esc_attr((string) (int) $ret['id']) . '" />';
        }

        echo '<table class="widefat striped anoofy-table"><thead><tr><th>'
            . esc_html__('Item', 'anoofy-woo') . '</th><th>'
            . esc_html__('Returned', 'anoofy-woo') . '</th><th>'
            . esc_html__('Restock qty', 'anoofy-woo') . '</th><th>'
            . esc_html__('Damaged qty', 'anoofy-woo') . '</th><th>'
            . esc_html__('Condition', 'anoofy-woo') . '</th><th>'
            . esc_html__('Note', 'anoofy-woo') . '</th></tr></thead><tbody>';

        foreach ($ret['items'] as $item) {
            $itemId = (int) $item['order_item_id'];
            $qtyReturned = (int) $item['qty_returned'];
            $restockQty = (int) $item['restock_qty'];
            $damagedQty = (int) $item['damaged_qty'];
            $note = (string) ($item['note'] ?? '');

            echo '<tr>';
            echo '<td>' . esc_html((string) $item['name']) . ($item['sku'] ? ' <code>' . esc_html((string) $item['sku']) . '</code>' : '') . '</td>';
            echo '<td>' . esc_html((string) $qtyReturned) . '</td>';
            if ($canInspect) {
                echo '<td><input type="number" min="0" max="' . esc_attr((string) $qtyReturned) . '" name="inspect[' . esc_attr((string) $itemId) . '][restock_qty]" value="' . esc_attr((string) $restockQty) . '" class="small-text" /></td>';
                echo '<td><input type="number" min="0" max="' . esc_attr((string) $qtyReturned) . '" name="inspect[' . esc_attr((string) $itemId) . '][damaged_qty]" value="' . esc_attr((string) $damagedQty) . '" class="small-text" /></td>';
                echo '<td>' . esc_html(Labels::returnCondition($item['condition_status'])) . '</td>';
                echo '<td><input type="text" name="inspect[' . esc_attr((string) $itemId) . '][note]" value="' . esc_attr($note) . '" maxlength="500" /></td>';
            } else {
                echo '<td>' . esc_html((string) $restockQty) . '</td>';
                echo '<td>' . esc_html((string) $damagedQty) . '</td>';
                echo '<td>' . esc_html(Labels::returnCondition($item['condition_status'])) . '</td>';
                echo '<td>' . esc_html($note !== '' ? $note : '—') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ($canInspect) {
            echo '<p><button type="button" class="button" data-anoofy-submit>'
                . esc_html__('Save inspection', 'anoofy-woo') . '</button></p></div>';
        }

        if ($canApprove) {
            echo '<div class="anoofy-ship-actions" data-anoofy-form="anoofy_approve_return">';
            echo '<input type="hidden" name="return_id" value="' . esc_attr((string) (int) $ret['id']) . '" />';
            echo '<button type="button" class="button button-primary" data-anoofy-submit>'
                . esc_html__('Approve return & restock', 'anoofy-woo') . '</button>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function renderOpenReturnForm(\WC_Order $order): void
    {
        $alreadyReturned = Services::returns()->alreadyReturnedQuantities($order->get_id());
        $returnable = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $ordered = max(0, (int) $item->get_quantity());
            $prev = (int) ($alreadyReturned[$item->get_id()] ?? 0);
            $remaining = $ordered - $prev;
            if ($remaining > 0) {
                $returnable[] = ['item' => $item, 'remaining' => $remaining];
            }
        }
        if ($returnable === []) {
            return;
        }

        echo '<div data-anoofy-form="anoofy_open_return">';
        echo '<p><strong>' . esc_html__('Open customer return', 'anoofy-woo') . '</strong></p>';
        echo '<table class="widefat striped anoofy-table"><thead><tr><th>'
            . esc_html__('Item', 'anoofy-woo') . '</th><th>'
            . esc_html__('Available to return', 'anoofy-woo') . '</th><th>'
            . esc_html__('Return qty', 'anoofy-woo') . '</th></tr></thead><tbody>';
        foreach ($returnable as $row) {
            $item = $row['item'];
            echo '<tr><td>' . esc_html($item->get_name()) . '</td><td>' . esc_html((string) $row['remaining']) . '</td>'
                . '<td><input type="number" min="0" max="' . esc_attr((string) $row['remaining']) . '" name="return_qty[' . esc_attr((string) $item->get_id()) . ']" value="0" class="small-text" /></td></tr>';
        }
        echo '</tbody></table>';
        echo '<textarea name="anoofy_reason" rows="2" maxlength="1000" class="anoofy-reason" placeholder="' . esc_attr__('Reason for customer return (required)', 'anoofy-woo') . '"></textarea>';
        echo '<button type="button" class="button" data-anoofy-submit>' . esc_html__('Open return', 'anoofy-woo') . '</button>';
        echo '</div>';
    }

    private static function stockMovementsHistory(int $orderId): void
    {
        $movements = Services::stockMovements()->forOrder($orderId);
        if ($movements === []) {
            return;
        }
        echo '<p><strong>' . esc_html__('Stock movements', 'anoofy-woo') . '</strong></p>';
        echo '<table class="widefat striped anoofy-table"><thead><tr><th>'
            . esc_html__('Return', 'anoofy-woo') . '</th><th>'
            . esc_html__('Movement', 'anoofy-woo') . '</th><th>'
            . esc_html__('Qty', 'anoofy-woo') . '</th><th>'
            . esc_html__('When', 'anoofy-woo') . '</th></tr></thead><tbody>';
        foreach ($movements as $m) {
            $typeLabel = $m['movement_type'] === 'return_restock'
                ? __('Restocked', 'anoofy-woo')
                : __('Damaged (not restocked)', 'anoofy-woo');
            printf(
                '<tr><td>#%d</td><td>%s</td><td>%d</td><td>%s</td></tr>',
                $m['return_id'],
                esc_html($typeLabel),
                $m['qty'],
                esc_html(self::when($m['created_at']))
            );
        }
        echo '</tbody></table>';
    }

    private static function deliveryHistory(int $orderId): void
    {
        $rows = Services::deliveryLog()->forOrder($orderId, 20);
        if ($rows === []) {
            return;
        }

        echo '<div class="anoofy-section"><h4>' . esc_html__('Delivery attempts', 'anoofy-woo') . '</h4>';
        echo '<table class="widefat striped anoofy-table"><thead><tr><th>' . esc_html__('#', 'anoofy-woo') . '</th><th>'
            . esc_html__('When', 'anoofy-woo') . '</th><th>' . esc_html__('Employee', 'anoofy-woo') . '</th><th>'
            . esc_html__('Result', 'anoofy-woo') . '</th><th>' . esc_html__('Reason', 'anoofy-woo') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            printf(
                '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $row['attempt_no'],
                esc_html(self::when($row['created_at'])),
                esc_html(Staff::name($row['user_id'])),
                esc_html(Labels::deliveryOutcome($row['outcome'])),
                esc_html($row['reason'] ?? '—'),
            );
        }
        echo '</tbody></table></div>';
    }

    private static function callHistory(int $orderId): void
    {
        $calls = Services::callLog()->forOrder($orderId, 20);
        echo '<div class="anoofy-section"><h4>' . esc_html__('Call log', 'anoofy-woo') . '</h4>';
        if ($calls === []) {
            echo '<p class="description">' . esc_html__('No calls yet.', 'anoofy-woo') . '</p></div>';

            return;
        }
        echo '<table class="widefat striped anoofy-table"><thead><tr><th>' . esc_html__('When', 'anoofy-woo') . '</th><th>'
            . esc_html__('Employee', 'anoofy-woo') . '</th><th>' . esc_html__('Result', 'anoofy-woo') . '</th><th>'
            . esc_html__('Note', 'anoofy-woo') . '</th></tr></thead><tbody>';
        foreach ($calls as $call) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html(self::when($call['created_at'])),
                esc_html(Staff::name($call['user_id'])),
                esc_html(Labels::outcome($call['outcome'])),
                esc_html($call['note'] ?? ''),
            );
        }
        echo '</tbody></table></div>';
    }

    private static function history(int $orderId): void
    {
        if (!current_user_can(Action::AuditView->capability())) {
            return;
        }
        $rows = Services::auditLog()->forOrder($orderId, 30);
        if ($rows === []) {
            return;
        }
        echo '<div class="anoofy-section"><h4>' . esc_html__('History', 'anoofy-woo') . '</h4><ul class="anoofy-history">';
        foreach ($rows as $row) {
            $from = $row['from_status'] !== null ? OrderStatus::tryFrom($row['from_status']) : null;
            $to = $row['to_status'] !== null ? OrderStatus::tryFrom($row['to_status']) : null;
            $change = $from !== null && $to !== null ? Labels::status($from) . ' → ' . Labels::status($to) : '';
            printf(
                '<li><span class="anoofy-when">%s</span> %s <code>%s</code> %s%s</li>',
                esc_html(self::when($row['created_at'])),
                esc_html(Staff::name($row['user_id']) ?: __('System', 'anoofy-woo')),
                esc_html($row['action']),
                esc_html($change),
                $row['reason'] !== null ? ' — ' . esc_html($row['reason']) : '',
            );
        }
        echo '</ul></div>';
    }

    /** UTC database timestamp -> site-localised date and time. */
    public static function when(string $utc): string
    {
        $ts = strtotime($utc . ' UTC');

        return $ts === false ? $utc : wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts);
    }
}

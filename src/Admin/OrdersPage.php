<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Order\StatusTabs;
use Anoofy\Woo\Data\OpsQuery;
use Anoofy\Woo\Services\Services;
use Automattic\WooCommerce\Utilities\OrderUtil;

/** Orders screen: status tabs with counts, "mine/unassigned" filter, search, bulk assignment. Server-rendered. */
final class OrdersPage
{
    private const MAX_SEARCH_RESULTS = 500;

    public static function render(): void
    {
        if (!current_user_can(Action::OrderView->capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'anoofy-woo'), '', ['response' => 403]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $viewOrderId = absint(self::query('order_id', '0'));
        if ($viewOrderId > 0) {
            self::renderOrderView($viewOrderId);

            return;
        }

        // Read-only filters from the query string; every value is validated against a whitelist or sanitised.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $tab = sanitize_key(self::query('tab', StatusTabs::ALL));
        $tab = StatusTabs::isValid($tab) ? $tab : StatusTabs::ALL;
        $filter = sanitize_key(self::query('who', 'all'));
        $search = trim(sanitize_text_field(self::query('s', '')));
        // phpcs:enable

        $assigned = match ($filter) {
            'mine' => get_current_user_id(),
            'unassigned' => OpsQuery::ASSIGNED_UNASSIGNED,
            default => null,
        };
        $orderIds = $search === '' ? null : self::searchOrderIds($search);

        $statuses = StatusTabs::statusesFor($tab);
        $statusValues = $statuses === null ? null : array_map(static fn ($s): string => $s->value, $statuses);

        $counts = Services::repository()->statusCounts($assigned, $orderIds);
        $table = new OrdersTable($statusValues, $assigned, $orderIds);
        $table->prepare_items();

        echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__('Orders', 'anoofy-woo') . '</h1><hr class="wp-header-end">';
        self::tabs($tab, $filter, $search, $counts);
        self::whoLinks($tab, $filter, $search);

        echo '<form method="get" class="search-form"><input type="hidden" name="page" value="anoofy">'
            . '<input type="hidden" name="tab" value="' . esc_attr($tab) . '"><input type="hidden" name="who" value="' . esc_attr($filter) . '">'
            . '<p class="search-box"><label class="screen-reader-text" for="anoofy-search">' . esc_html__('Search orders', 'anoofy-woo') . '</label>'
            . '<input type="search" id="anoofy-search" name="s" value="' . esc_attr($search) . '"> '
            . '<button type="submit" class="button">' . esc_html__('Search orders', 'anoofy-woo') . '</button></p></form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="anoofy_bulk_assign">';
        wp_nonce_field('anoofy_bulk_assign', '_anoofy_nonce');
        $table->display();
        echo '</form></div>';
    }

    /** A query-string value as a string (an array such as ?tab[]=x is ignored instead of raising a warning). */
    private static function query(string $key, string $default): string
    {
        $value = $_GET[$key] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return is_string($value) ? wp_unslash($value) : $default;
    }

    /**
     * Search runs in WooCommerce (names, phone, email, number live in its tables, HPOS or posts). We cap the result
     * so a one-letter search cannot build a huge IN list. A purely numeric term also matches that order number.
     *
     * @return list<int>
     */
    private static function searchOrderIds(string $term): array
    {
        $ids = self::matchingOrderIds($term);
        if (ctype_digit($term)) {
            $ids[] = (int) $term;
        }

        $unique = array_values(array_unique($ids));
        if (count($unique) > self::MAX_SEARCH_RESULTS) {
            $unique = array_slice($unique, 0, self::MAX_SEARCH_RESULTS);
        }

        return $unique;
    }

    /**
     * In HPOS, wc_get_orders(['s' => ...]) natively searches customer fields and order numbers with limit and type.
     * In legacy CPT storage, wc_get_orders searches only wp_posts; wc_order_search queries postmeta for customer fields
     * without a limit, so we cap candidate IDs before querying shop_order posts.
     *
     * @return list<int>
     */
    private static function matchingOrderIds(string $term): array
    {
        $isHpos = class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
        if ($isHpos) {
            $ids = wc_get_orders([
                's' => $term,
                'limit' => self::MAX_SEARCH_RESULTS,
                'return' => 'ids',
                'type' => 'shop_order',
            ]);

            return array_map('intval', is_array($ids) ? $ids : []);
        }

        $found = function_exists('wc_order_search') ? wc_order_search($term) : [];
        if (!is_array($found) || $found === []) {
            return [];
        }

        // Cap candidate IDs before post__in so the SQL IN list never exceeds 501 items.
        $candidateIds = array_slice(array_map('intval', $found), 0, self::MAX_SEARCH_RESULTS + 1);
        $ids = wc_get_orders([
            'post__in' => $candidateIds,
            'limit' => self::MAX_SEARCH_RESULTS,
            'return' => 'ids',
            'type' => 'shop_order',
        ]);

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /** @param array<string, int> $counts */
    private static function tabs(string $current, string $who, string $search, array $counts): void
    {
        $links = [];
        foreach (StatusTabs::keys() as $key) {
            $statuses = StatusTabs::statusesFor($key);
            $n = $statuses === null
                ? array_sum($counts)
                : array_sum(array_map(static fn ($s): int => $counts[$s->value] ?? 0, $statuses));
            $url = add_query_arg(['page' => 'anoofy', 'tab' => $key, 'who' => $who, 's' => $search], admin_url('admin.php'));
            $links[] = sprintf(
                '<li><a href="%s"%s>%s <span class="count">(%d)</span></a></li>',
                esc_url($url),
                $key === $current ? ' class="current" aria-current="page"' : '',
                esc_html(Labels::tab($key)),
                $n,
            );
        }
        echo '<ul class="subsubsub">' . implode(' | ', $links) . '</ul><br class="clear">';
    }

    private static function whoLinks(string $tab, string $current, string $search): void
    {
        $options = [
            'all' => __('All employees', 'anoofy-woo'),
            'mine' => __('Assigned to me', 'anoofy-woo'),
            'unassigned' => __('Unassigned', 'anoofy-woo'),
        ];
        echo '<p class="anoofy-views">';
        foreach ($options as $key => $label) {
            $url = add_query_arg(['page' => 'anoofy', 'tab' => $tab, 'who' => $key, 's' => $search], admin_url('admin.php'));
            printf('<a href="%s"%s>%s</a>', esc_url($url), $key === $current ? ' class="current"' : '', esc_html($label));
        }
        echo '</p>';
    }

    private static function renderOrderView(int $orderId): void
    {
        $backUrl = admin_url('admin.php?page=anoofy');
        $order = wc_get_order($orderId);

        echo '<div class="wrap">';
        echo '<p><a href="' . esc_url($backUrl) . '" class="button">&larr; ' . esc_html__('Back to orders', 'anoofy-woo') . '</a></p>';

        if (!$order instanceof \WC_Order) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Order not found.', 'anoofy-woo') . '</p></div></div>';

            return;
        }

        echo '<h1>' . esc_html(sprintf(
            /* translators: %s: order number */
            __('Order #%s', 'anoofy-woo'),
            $order->get_order_number()
        )) . '</h1>';

        echo '<div class="anoofy-order-view-grid">';
        echo '<div class="anoofy-card"><h3>' . esc_html__('Customer & delivery', 'anoofy-woo') . '</h3>';
        echo '<p><strong>' . esc_html__('Customer:', 'anoofy-woo') . '</strong> ' . esc_html(trim($order->get_formatted_billing_full_name()) ?: '—') . '</p>';
        if (current_user_can(Action::OrderViewCustomerContact->capability())) {
            echo '<p><strong>' . esc_html__('Phone:', 'anoofy-woo') . '</strong> <span dir="ltr">' . esc_html($order->get_billing_phone() ?: '—') . '</span></p>';
            $city = $order->get_shipping_city() ?: $order->get_billing_city();
            $address = trim(($order->get_shipping_address_1() ?: $order->get_billing_address_1()) . ' ' . ($order->get_shipping_address_2() ?: $order->get_billing_address_2()));
            echo '<p><strong>' . esc_html__('City:', 'anoofy-woo') . '</strong> ' . esc_html($city ?: '—') . '</p>';
            echo '<p><strong>' . esc_html__('Address:', 'anoofy-woo') . '</strong> ' . esc_html($address ?: '—') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('Customer contact details are hidden for your role.', 'anoofy-woo') . '</p>';
        }
        echo '<p><strong>' . esc_html__('Total:', 'anoofy-woo') . '</strong> ' . wp_kses_post($order->get_formatted_order_total()) . '</p>';
        echo '</div>';

        echo '<div class="anoofy-card"><h3>' . esc_html__('Order items', 'anoofy-woo') . '</h3>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Product', 'anoofy-woo') . '</th><th>'
            . esc_html__('SKU', 'anoofy-woo') . '</th><th>' . esc_html__('Qty', 'anoofy-woo') . '</th></tr></thead><tbody>';
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';
            printf(
                '<tr><td>%s</td><td>%s</td><td>%d</td></tr>',
                esc_html($item->get_name()),
                esc_html($sku !== '' ? $sku : '—'),
                $item->get_quantity(),
            );
        }
        echo '</tbody></table></div>';
        echo '</div>';

        echo '<div class="anoofy-card">';
        OrderBox::renderOpsBox($order, 'ops');
        echo '</div></div>';
    }
}

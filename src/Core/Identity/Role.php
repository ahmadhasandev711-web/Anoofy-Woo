<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Identity;

/**
 * The five operational roles (same matrix as the Anoofy architecture doc, section 7).
 * Pure: no WordPress calls. The WordPress layer maps a WP user to one of these.
 */
enum Role: string
{
    case Owner = 'owner';
    case OrderManager = 'order_manager';
    case Warehouse = 'warehouse';
    case Shipping = 'shipping';
    case CustomerService = 'customer_service';

    /** WordPress role slug created/managed by this plugin (Owner uses the built-in shop_manager/administrator). */
    public function wpRoleSlug(): string
    {
        return 'anoofy_' . $this->value;
    }
}

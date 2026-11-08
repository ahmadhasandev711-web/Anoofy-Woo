<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Identity;

/** Maps a WordPress user's role slugs to the one operational Role used for permission checks. Pure. */
final class RoleResolver
{
    /** Most privileged first: a user holding several plugin roles gets the strongest one. */
    private const PRIORITY = [Role::OrderManager, Role::CustomerService, Role::Shipping, Role::Warehouse];

    /** @param list<string> $wpRoles e.g. ['administrator'] or ['anoofy_warehouse'] */
    public static function fromWpRoles(array $wpRoles): ?Role
    {
        if (array_intersect(['administrator', 'shop_manager'], $wpRoles) !== []) {
            return Role::Owner;
        }
        foreach (self::PRIORITY as $role) {
            if (in_array($role->wpRoleSlug(), $wpRoles, true)) {
                return $role;
            }
        }

        return null;
    }
}

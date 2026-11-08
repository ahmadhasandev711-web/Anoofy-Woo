<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Identity\Role;

/** Users who can be assigned to orders (owners and the four plugin roles). */
final class Staff
{
    /** @var array<int, string>|null */
    private static ?array $cache = null;

    /** @return array<int, string> user id => display name */
    public static function assignable(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $slugs = ['administrator', 'shop_manager'];
        foreach ([Role::OrderManager, Role::CustomerService, Role::Warehouse, Role::Shipping] as $role) {
            $slugs[] = $role->wpRoleSlug();
        }
        $out = [];
        foreach (get_users(['role__in' => $slugs, 'orderby' => 'display_name', 'fields' => ['ID', 'display_name']]) as $user) {
            $out[(int) $user->ID] = (string) $user->display_name;
        }

        return self::$cache = $out;
    }

    public static function name(?int $userId): string
    {
        if ($userId === null) {
            return '';
        }
        $names = self::assignable();
        if (isset($names[$userId])) {
            return $names[$userId];
        }
        $user = get_userdata($userId);

        return $user instanceof \WP_User ? (string) $user->display_name : '';
    }
}

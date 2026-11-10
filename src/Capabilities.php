<?php
declare(strict_types=1);

namespace Anoofy\Woo;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Identity\Permissions;
use Anoofy\Woo\Core\Identity\Role;

/**
 * Maps the permission matrix onto WordPress roles and capabilities.
 * Owner = the store's administrators/shop managers; the other four get their own WP role.
 */
final class Capabilities
{
    public const HASH_OPTION = 'anoofy_permissions_hash';

    public static function matrixHash(): string
    {
        return hash('sha256', serialize(Permissions::matrix()));
    }

    public static function maybeSync(): void
    {
        $currentHash = self::matrixHash();
        $storedHash = get_option(self::HASH_OPTION, '');

        if ($storedHash !== $currentHash || !self::adminHasCaps()) {
            self::install();
            update_option(self::HASH_OPTION, $currentHash, false);
        }
    }

    private static function adminHasCaps(): bool
    {
        $admin = get_role('administrator');
        if ($admin === null) {
            return true;
        }
        foreach (Permissions::actionsFor(Role::Owner) as $action) {
            if (!$admin->has_cap($action->capability())) {
                return false;
            }
        }
        return true;
    }

    public static function install(): void
    {
        foreach (['administrator', 'shop_manager'] as $slug) {
            $wpRole = get_role($slug);
            if ($wpRole === null) {
                continue;
            }
            foreach (Permissions::actionsFor(Role::Owner) as $action) {
                $wpRole->add_cap($action->capability());
            }
        }

        foreach (self::labels() as $role => $label) {
            $caps = ['read' => true];
            foreach (Permissions::actionsFor(Role::from($role)) as $action) {
                $caps[$action->capability()] = true;
            }
            // add_role() does nothing when the role exists, so refresh the caps explicitly.
            remove_role('anoofy_' . $role);
            add_role('anoofy_' . $role, $label, $caps);
        }

        update_option(self::HASH_OPTION, self::matrixHash(), false);
    }

    /** @return array<string, string> role value => label (Owner has no dedicated WP role) */
    private static function labels(): array
    {
        return [
            Role::OrderManager->value => __('Anoofy Order Manager', 'anoofy-woo'),
            Role::Warehouse->value => __('Anoofy Warehouse', 'anoofy-woo'),
            Role::Shipping->value => __('Anoofy Shipping', 'anoofy-woo'),
            Role::CustomerService->value => __('Anoofy Customer Service', 'anoofy-woo'),
        ];
    }

    public static function currentUserCan(Action $action): bool
    {
        return current_user_can($action->capability());
    }
}

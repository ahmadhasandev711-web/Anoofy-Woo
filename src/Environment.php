<?php
declare(strict_types=1);

namespace Anoofy\Woo;

/** Minimum supported versions. Pure, so it can be unit-tested without WordPress. */
final class Environment
{
    public const MIN_PHP = '8.1.0';
    public const MIN_WP = '7.0';
    public const MIN_WC = '11.2';

    /**
     * @param string|null $wooVersion null when WooCommerce is not active
     * @return list<string> human-readable problems (empty = OK)
     */
    public static function problems(string $phpVersion, string $wpVersion, ?string $wooVersion): array
    {
        $problems = [];
        if (version_compare($phpVersion, self::MIN_PHP, '<')) {
            $problems[] = sprintf('PHP %s or newer is required (found %s).', self::MIN_PHP, $phpVersion);
        }
        if (version_compare($wpVersion, self::MIN_WP, '<')) {
            $problems[] = sprintf('WordPress %s or newer is required (found %s).', self::MIN_WP, $wpVersion);
        }
        if ($wooVersion === null) {
            $problems[] = 'WooCommerce must be installed and active.';
        } elseif (version_compare($wooVersion, self::MIN_WC, '<')) {
            $problems[] = sprintf('WooCommerce %s or newer is required (found %s).', self::MIN_WC, $wooVersion);
        }

        return $problems;
    }
}

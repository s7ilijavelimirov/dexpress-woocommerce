<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Frontend\Shipping;

/**
 * Returns true if at least one D-Express shipping method is enabled in any
 * WooCommerce shipping zone (including "Rest of the World", zone_id = 0).
 *
 * Safe to call at plugins_loaded — queries wpdb directly, no WC class dependency.
 */
final class DexpressShippingGate
{
    public static function isActive(): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}woocommerce_shipping_zone_methods`
             WHERE method_id IN ('dexpress','dexpress_package_shop') AND is_enabled = 1",
        );

        return $count > 0;
    }
}

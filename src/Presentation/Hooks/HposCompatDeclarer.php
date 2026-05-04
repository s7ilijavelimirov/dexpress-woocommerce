<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Hooks;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

final class HposCompatDeclarer
{
    public static function declare(): void
    {
        add_action('before_woocommerce_init', static function (): void {
            if (class_exists(FeaturesUtil::class)) {
                FeaturesUtil::declare_compatibility('custom_order_tables', DEXPRESS_PLUGIN_FILE, true);
                FeaturesUtil::declare_compatibility('cart_checkout_blocks', DEXPRESS_PLUGIN_FILE, false);
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Metabox;

use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressPackageShopShippingMethod;

final class PackageShopInfoMetabox
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 10, 2);
    }

    /**
     * @param string $screenId
     * @param \WP_Post|\WC_Order $postOrOrder
     */
    public function addMetaBox(string $screenId, \WP_Post|\WC_Order $postOrOrder): void
    {
        $order = $postOrOrder instanceof \WC_Order
            ? $postOrOrder
            : wc_get_order($postOrOrder->ID);

        if (!$order instanceof \WC_Order) {
            return;
        }
        if (!DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order)) {
            return;
        }

        $screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        if (!in_array($screenId, $screens, true)) {
            return;
        }

        add_meta_box(
            'dexpress-package-shop',
            __('D-Express Paket Shop', 'dexpress-woocommerce'),
            [$this, 'render'],
            $screenId,
            'side',
            'high',
        );
    }

    /**
     * @param \WP_Post|\WC_Order $postOrOrder
     */
    public function render(\WP_Post|\WC_Order $postOrOrder): void
    {
        $order = $postOrOrder instanceof \WC_Order
            ? $postOrOrder
            : wc_get_order($postOrOrder->ID);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $locationType = trim((string) $order->get_meta('_dexpress_package_shop_location_type'));
        $locationTypeLabel = trim((string) ($order->get_meta('_dexpress_package_shop_location_type_label') ?: __('Paket Shop', 'dexpress-woocommerce')));
        $name = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
        $address = trim((string) $order->get_meta('_dexpress_package_shop_location_address'));
        $city = trim((string) $order->get_meta('_dexpress_package_shop_location_city'));
        $workingDays = trim((string) $order->get_meta('_dexpress_package_shop_location_working_days'));
        $workingHours = trim((string) $order->get_meta('_dexpress_package_shop_location_working_hours'));
        $payment = trim((string) $order->get_meta('_dexpress_package_shop_location_payment'));
        $locationId = trim((string) $order->get_meta('_dexpress_package_shop_location_id'));
        $worktime = trim($workingDays . ($workingDays !== '' && $workingHours !== '' ? ' | ' : '') . $workingHours);

        $icon = ($locationType === '2' || stripos($locationTypeLabel, 'paketomat') !== false) ? '📦' : '🏬';

        echo '<div class="dex-ps-card">';
        echo '<p class="dex-ps-title"><span class="dex-ps-icon">' . esc_html($icon) . '</span> ' . esc_html($locationTypeLabel) . '</p>';
        echo '<dl class="dex-ps-list">';
        echo '<dt>' . esc_html__('Naziv lokacije', 'dexpress-woocommerce') . '</dt><dd>' . esc_html($name !== '' ? $name : '—') . '</dd>';
        echo '<dt>' . esc_html__('Adresa', 'dexpress-woocommerce') . '</dt><dd>' . esc_html($address !== '' ? $address : '—') . '</dd>';
        echo '<dt>' . esc_html__('Grad', 'dexpress-woocommerce') . '</dt><dd>' . esc_html($city !== '' ? $city : '—') . '</dd>';
        echo '<dt>' . esc_html__('Radno vreme', 'dexpress-woocommerce') . '</dt><dd>' . esc_html($worktime !== '' ? $worktime : '—') . '</dd>';
        echo '<dt>' . esc_html__('Način plaćanja na lokaciji', 'dexpress-woocommerce') . '</dt><dd>' . esc_html($payment !== '' ? $payment : '—') . '</dd>';
        echo '<dt>' . esc_html__('Lokacija ID', 'dexpress-woocommerce') . '</dt><dd><code>' . esc_html($locationId !== '' ? $locationId : '—') . '</code></dd>';
        echo '</dl>';
        echo '</div>';
    }
}

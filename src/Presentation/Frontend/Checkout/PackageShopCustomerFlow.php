<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Frontend\Checkout;

use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressPackageShopShippingMethod;
use WC_Order;

final class PackageShopCustomerFlow
{
    /**
     * @var array<int, bool>
     */
    private array $renderedOrderIds = [];

    public function register(): void
    {
        add_filter('woocommerce_email_order_meta_fields', [$this, 'appendPackageShopMetaToEmails'], 20, 3);
        add_action('woocommerce_order_details_after_order_table', [$this, 'renderPackageShopLocationAfterOrderTable'], 20, 1);
        add_action('woocommerce_thankyou', [$this, 'renderPackageShopLocationOnThankYou'], 20, 1);
    }

    /**
     * @param array<string, array<string, string>> $fields
     * @return array<string, array<string, string>>
     */
    public function appendPackageShopMetaToEmails(array $fields, bool $sentToAdmin, WC_Order $order): array
    {
        if ($sentToAdmin) {
            return $fields;
        }

        if (!DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order)) {
            return $fields;
        }

        $location = $this->getLocationMeta($order);
        if ($location['id'] === '') {
            return $fields;
        }

        $locationName = $location['name'] !== '' ? $location['name'] : __('Paket Shop lokacija', 'dexpress-woocommerce');
        $addressLine = trim($location['address'] . ($location['city'] !== '' ? ', ' . $location['city'] : ''));

        $fields['dexpress_package_shop_location_name'] = [
            'label' => __('Lokacija preuzimanja', 'dexpress-woocommerce'),
            'value' => $locationName,
        ];

        if ($addressLine !== '') {
            $fields['dexpress_package_shop_location_address'] = [
                'label' => __('Adresa lokacije', 'dexpress-woocommerce'),
                'value' => $addressLine,
            ];
        }

        if ($location['type_label'] !== '') {
            $fields['dexpress_package_shop_location_type'] = [
                'label' => __('Tip lokacije', 'dexpress-woocommerce'),
                'value' => $location['type_label'],
            ];
        }

        if ($location['working'] !== '') {
            $fields['dexpress_package_shop_location_working'] = [
                'label' => __('Radno vreme', 'dexpress-woocommerce'),
                'value' => $location['working'],
            ];
        }

        return $fields;
    }

    public function renderPackageShopLocationOnThankYou(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $this->renderLocationPanel($order);
    }

    public function renderPackageShopLocationAfterOrderTable(WC_Order $order): void
    {
        $this->renderLocationPanel($order);
    }

    private function renderLocationPanel(WC_Order $order): void
    {
        $orderId = (int) $order->get_id();
        if ($orderId <= 0 || isset($this->renderedOrderIds[$orderId])) {
            return;
        }

        if (!DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order)) {
            return;
        }

        $location = $this->getLocationMeta($order);
        if ($location['id'] === '') {
            return;
        }

        $this->renderedOrderIds[$orderId] = true;
        ?>
        <section class="woocommerce-order-details dexpress-package-shop-order-location">
            <h2 class="woocommerce-order-details__title"><?php esc_html_e('Paket Shop lokacija za preuzimanje', 'dexpress-woocommerce'); ?></h2>
            <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
                <li>
                    <?php esc_html_e('Lokacija:', 'dexpress-woocommerce'); ?>
                    <strong><?php echo esc_html($location['name'] !== '' ? $location['name'] : __('Paket Shop lokacija', 'dexpress-woocommerce')); ?></strong>
                </li>
                <?php if ($location['type_label'] !== '') : ?>
                    <li>
                        <?php esc_html_e('Tip lokacije:', 'dexpress-woocommerce'); ?>
                        <strong><?php echo esc_html($location['type_label']); ?></strong>
                    </li>
                <?php endif; ?>
                <?php if ($location['address'] !== '' || $location['city'] !== '') : ?>
                    <li>
                        <?php esc_html_e('Adresa:', 'dexpress-woocommerce'); ?>
                        <strong><?php echo esc_html(trim($location['address'] . ($location['city'] !== '' ? ', ' . $location['city'] : ''))); ?></strong>
                    </li>
                <?php endif; ?>
                <?php if ($location['working'] !== '') : ?>
                    <li>
                        <?php esc_html_e('Radno vreme:', 'dexpress-woocommerce'); ?>
                        <strong><?php echo esc_html($location['working']); ?></strong>
                    </li>
                <?php endif; ?>
                <?php if ($location['payment'] !== '') : ?>
                    <li>
                        <?php esc_html_e('Plaćanje na lokaciji:', 'dexpress-woocommerce'); ?>
                        <strong><?php echo esc_html($location['payment']); ?></strong>
                    </li>
                <?php endif; ?>
            </ul>
        </section>
        <?php
    }

    /**
     * @return array{
     *   id: string,
     *   type_label: string,
     *   name: string,
     *   address: string,
     *   city: string,
     *   working: string,
     *   payment: string
     * }
     */
    private function getLocationMeta(WC_Order $order): array
    {
        $workingDays = trim((string) $order->get_meta('_dexpress_package_shop_location_working_days'));
        $workingHours = trim((string) $order->get_meta('_dexpress_package_shop_location_working_hours'));
        $working = trim($workingDays . ($workingDays !== '' && $workingHours !== '' ? ' | ' : '') . $workingHours);

        return [
            'id' => trim((string) $order->get_meta('_dexpress_package_shop_location_id')),
            'type_label' => trim((string) $order->get_meta('_dexpress_package_shop_location_type_label')),
            'name' => trim((string) $order->get_meta('_dexpress_package_shop_location_name')),
            'address' => trim((string) $order->get_meta('_dexpress_package_shop_location_address')),
            'city' => trim((string) $order->get_meta('_dexpress_package_shop_location_city')),
            'working' => $working,
            'payment' => trim((string) $order->get_meta('_dexpress_package_shop_location_payment')),
        ];
    }
}

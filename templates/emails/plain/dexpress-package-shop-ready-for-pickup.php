<?php
/**
 * D Express — Paket Shop spremno za preuzimanje (plain).
 *
 * @var string $email_heading
 * @var WC_Order $order
 * @var WC_Email $email
 */

defined('ABSPATH') || exit;

if (!isset($order) || !$order instanceof WC_Order) {
    return;
}

$locationName = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
$locationAddress = trim((string) $order->get_meta('_dexpress_package_shop_location_address'));
$locationCity = trim((string) $order->get_meta('_dexpress_package_shop_location_city'));
$locationType = trim((string) $order->get_meta('_dexpress_package_shop_location_type_label'));
$workingDays = trim((string) $order->get_meta('_dexpress_package_shop_location_working_days'));
$workingHours = trim((string) $order->get_meta('_dexpress_package_shop_location_working_hours'));
$payment = trim((string) $order->get_meta('_dexpress_package_shop_location_payment'));
$trackingCode = trim((string) $order->get_meta('_dexpress_last_tracking_code'));
$working = trim($workingDays . ($workingDays !== '' && $workingHours !== '' ? ' | ' : '') . $workingHours);

echo wp_strip_all_tags((string) $email_heading) . "\n\n";
printf(
    wp_strip_all_tags(__('Narudžbina #%s je spremna za preuzimanje.', 'dexpress-woocommerce')),
    wp_strip_all_tags((string) $order->get_order_number()),
);
echo "\n";
echo wp_strip_all_tags(__('Možete je preuzeti na odabranoj Paket Shop lokaciji:', 'dexpress-woocommerce')) . "\n\n";

if ($locationName !== '') {
    echo wp_strip_all_tags(__('Lokacija:', 'dexpress-woocommerce')) . ' ' . wp_strip_all_tags($locationName) . "\n";
}
if ($locationType !== '') {
    echo wp_strip_all_tags(__('Tip lokacije:', 'dexpress-woocommerce')) . ' ' . wp_strip_all_tags($locationType) . "\n";
}
if ($locationAddress !== '' || $locationCity !== '') {
    echo wp_strip_all_tags(__('Adresa:', 'dexpress-woocommerce')) . ' ' . wp_strip_all_tags(trim($locationAddress . ($locationCity !== '' ? ', ' . $locationCity : ''))) . "\n";
}
if ($working !== '') {
    echo wp_strip_all_tags(__('Radno vreme:', 'dexpress-woocommerce')) . ' ' . wp_strip_all_tags($working) . "\n";
}
if ($payment !== '') {
    echo wp_strip_all_tags(__('Plaćanje na lokaciji:', 'dexpress-woocommerce')) . ' ' . wp_strip_all_tags($payment) . "\n";
}
if ($trackingCode !== '') {
    echo wp_strip_all_tags(__('Kod za praćenje:', 'dexpress-woocommerce')) . ' ' . wp_strip_all_tags($trackingCode) . "\n";
}

echo "\n" . wp_strip_all_tags(__('Hvala na kupovini.', 'dexpress-woocommerce')) . "\n";

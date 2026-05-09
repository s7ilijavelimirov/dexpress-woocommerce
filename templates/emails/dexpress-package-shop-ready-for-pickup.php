<?php
/**
 * D Express — Paket Shop spremno za preuzimanje (HTML).
 *
 * @package DExpress
 * @var WC_Order $order
 * @var string $email_heading
 * @var bool $sent_to_admin
 * @var bool $plain_text
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

do_action('woocommerce_email_header', $email_heading, $email);
?>

<p style="margin:0 0 14px;">
    <?php
    printf(
        /* translators: %s: order number */
        esc_html__('Narudžbina #%s je spremna za preuzimanje.', 'dexpress-woocommerce'),
        esc_html($order->get_order_number()),
    );
    ?>
</p>

<p style="margin:0 0 18px;">
    <?php esc_html_e('Možete je preuzeti na odabranoj Paket Shop lokaciji:', 'dexpress-woocommerce'); ?>
</p>

<table cellspacing="0" cellpadding="8" style="width:100%; border:1px solid #e5e5e5; border-collapse:collapse; margin:0 0 18px;">
    <tbody>
    <?php if ($locationName !== '') : ?>
        <tr>
            <th scope="row" style="text-align:left; border:1px solid #e5e5e5;"><?php esc_html_e('Lokacija', 'dexpress-woocommerce'); ?></th>
            <td style="border:1px solid #e5e5e5;"><?php echo esc_html($locationName); ?></td>
        </tr>
    <?php endif; ?>
    <?php if ($locationType !== '') : ?>
        <tr>
            <th scope="row" style="text-align:left; border:1px solid #e5e5e5;"><?php esc_html_e('Tip lokacije', 'dexpress-woocommerce'); ?></th>
            <td style="border:1px solid #e5e5e5;"><?php echo esc_html($locationType); ?></td>
        </tr>
    <?php endif; ?>
    <?php if ($locationAddress !== '' || $locationCity !== '') : ?>
        <tr>
            <th scope="row" style="text-align:left; border:1px solid #e5e5e5;"><?php esc_html_e('Adresa', 'dexpress-woocommerce'); ?></th>
            <td style="border:1px solid #e5e5e5;"><?php echo esc_html(trim($locationAddress . ($locationCity !== '' ? ', ' . $locationCity : ''))); ?></td>
        </tr>
    <?php endif; ?>
    <?php if ($working !== '') : ?>
        <tr>
            <th scope="row" style="text-align:left; border:1px solid #e5e5e5;"><?php esc_html_e('Radno vreme', 'dexpress-woocommerce'); ?></th>
            <td style="border:1px solid #e5e5e5;"><?php echo esc_html($working); ?></td>
        </tr>
    <?php endif; ?>
    <?php if ($payment !== '') : ?>
        <tr>
            <th scope="row" style="text-align:left; border:1px solid #e5e5e5;"><?php esc_html_e('Plaćanje na lokaciji', 'dexpress-woocommerce'); ?></th>
            <td style="border:1px solid #e5e5e5;"><?php echo esc_html($payment); ?></td>
        </tr>
    <?php endif; ?>
    <?php if ($trackingCode !== '') : ?>
        <tr>
            <th scope="row" style="text-align:left; border:1px solid #e5e5e5;"><?php esc_html_e('Kod za praćenje', 'dexpress-woocommerce'); ?></th>
            <td style="border:1px solid #e5e5e5;"><?php echo esc_html($trackingCode); ?></td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<p style="margin:0;">
    <?php esc_html_e('Hvala na kupovini.', 'dexpress-woocommerce'); ?>
</p>

<?php do_action('woocommerce_email_footer', $email); ?>

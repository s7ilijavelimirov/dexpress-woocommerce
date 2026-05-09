<?php
/**
 * D Express — univerzalni mejl praćenja (HTML).
 *
 * @package DExpress
 * @var WC_Order $order
 * @var string $email_heading
 * @var string $tracking_codes_text
 * @var \S7codedesign\DExpress\Application\Email\ShipmentEmailRenderContext|null $shipment_context
 * @var bool $is_test_shipment
 * @var bool $sent_to_admin
 * @var bool $plain_text
 * @var WC_Email $email
 */

defined('ABSPATH') || exit;

use S7codedesign\DExpress\Application\Email\ShipmentEmailRenderContext;
use S7codedesign\DExpress\Presentation\Email\TrackingLinkBuilder;

/** @var WC_Order $order */
$ctx = $shipment_context instanceof ShipmentEmailRenderContext ? $shipment_context : null;
$packageShopLocationId = trim((string) $order->get_meta('_dexpress_package_shop_location_id'));
$packageShopLocationName = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
$packageShopLocationAddress = trim((string) $order->get_meta('_dexpress_package_shop_location_address'));
$packageShopLocationCity = trim((string) $order->get_meta('_dexpress_package_shop_location_city'));
$packageShopLocationType = trim((string) $order->get_meta('_dexpress_package_shop_location_type_label'));
$packageShopWorkingDays = trim((string) $order->get_meta('_dexpress_package_shop_location_working_days'));
$packageShopWorkingHours = trim((string) $order->get_meta('_dexpress_package_shop_location_working_hours'));
$packageShopWorking = trim($packageShopWorkingDays . ($packageShopWorkingDays !== '' && $packageShopWorkingHours !== '' ? ' | ' : '') . $packageShopWorkingHours);

/**
 * @param 'done'|'current'|'pending'|'problem' $state
 */
$dexpress_email_step_color = static function (string $state): string {
    return match ($state) {
        'done' => '#2e7d32',
        'current' => '#1565c0',
        'problem' => '#c62828',
        default => '#bdbdbd',
    };
};

?>
<?php do_action('woocommerce_email_header', $email_heading, $email); ?>

<p style="margin:0 0 16px;">
    <?php if ($is_test_shipment) : ?>
        <strong style="color:#b45309;"><?php esc_html_e('TEST — ovo je probna pošiljka.', 'dexpress-woocommerce'); ?></strong><br>
    <?php endif; ?>
    <?php
    printf(
        /* translators: %s: order number (HTML) */
        esc_html__('Narudžbina %s — aktuelno stanje vaše D Express pošiljke:', 'dexpress-woocommerce'),
        '<strong>' . esc_html($order->get_order_number()) . '</strong>',
    );
    ?>
</p>

<?php if ($packageShopLocationId !== '') : ?>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0 0 18px;border-collapse:collapse;background:#fff8da;border:1px solid #e5d48a;border-radius:6px;">
        <tr>
            <td style="padding:12px 14px;">
                <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#1a1a1a;">
                    <?php esc_html_e('Destinacija dostave: Paket Shop lokacija', 'dexpress-woocommerce'); ?>
                </p>
                <?php if ($packageShopLocationName !== '') : ?>
                    <p style="margin:0 0 4px;font-size:13px;line-height:1.5;"><strong><?php esc_html_e('Lokacija:', 'dexpress-woocommerce'); ?></strong> <?php echo esc_html($packageShopLocationName); ?></p>
                <?php endif; ?>
                <?php if ($packageShopLocationType !== '') : ?>
                    <p style="margin:0 0 4px;font-size:13px;line-height:1.5;"><strong><?php esc_html_e('Tip lokacije:', 'dexpress-woocommerce'); ?></strong> <?php echo esc_html($packageShopLocationType); ?></p>
                <?php endif; ?>
                <?php if ($packageShopLocationAddress !== '' || $packageShopLocationCity !== '') : ?>
                    <p style="margin:0 0 4px;font-size:13px;line-height:1.5;"><strong><?php esc_html_e('Adresa:', 'dexpress-woocommerce'); ?></strong> <?php echo esc_html(trim($packageShopLocationAddress . ($packageShopLocationCity !== '' ? ', ' . $packageShopLocationCity : ''))); ?></p>
                <?php endif; ?>
                <?php if ($packageShopWorking !== '') : ?>
                    <p style="margin:0;font-size:13px;line-height:1.5;"><strong><?php esc_html_e('Radno vreme:', 'dexpress-woocommerce'); ?></strong> <?php echo esc_html($packageShopWorking); ?></p>
                <?php endif; ?>
            </td>
        </tr>
    </table>
<?php endif; ?>

<?php if ($ctx !== null) : ?>
    <?php foreach ($ctx->rows as $row) : ?>
        <?php
        $trackUrl = TrackingLinkBuilder::publicTrackingUrl(trim($row->trackingCode));
        ?>
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0 0 24px;border-collapse:collapse;background:#f6f7f7;border-radius:6px;">
            <tr>
                <td style="padding:16px 18px;">
                    <?php if ($row->showProblemBanner) : ?>
                        <p style="margin:0 0 12px;padding:10px 12px;background:#ffebee;border-left:4px solid #c62828;color:#b71c1c;font-size:14px;line-height:1.45;">
                            <?php esc_html_e('Pažnja: prijavljen je problem u obradi ili isporuci pošiljke.', 'dexpress-woocommerce'); ?>
                        </p>
                    <?php endif; ?>

                    <p style="margin:0 0 6px;font-size:15px;line-height:1.5;">
                        <strong><?php esc_html_e('Kod za praćenje:', 'dexpress-woocommerce'); ?></strong>
                        <?php echo esc_html($row->trackingCode); ?>
                        <?php if ($trackUrl !== '') : ?>
                            <br>
                            <a href="<?php echo esc_url($trackUrl); ?>" style="color:#1565c0;">
                                <?php esc_html_e('Pratite na dexpress.rs', 'dexpress-woocommerce'); ?>
                            </a>
                        <?php endif; ?>
                    </p>

                    <p style="margin:0 0 12px;font-size:14px;line-height:1.5;color:#333;">
                        <strong><?php esc_html_e('Status (D Express):', 'dexpress-woocommerce'); ?></strong>
                        <?php echo esc_html($row->statusLabel); ?>
                    </p>

                    <p style="margin:0 0 14px;font-size:15px;line-height:1.55;color:#1a1a1a;">
                        <?php echo esc_html($row->leadMessage); ?>
                    </p>

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border-collapse:collapse;">
                        <tr>
                            <?php foreach ($row->steps as $idx => $step) : ?>
                                <?php $color = $dexpress_email_step_color($step['state']); ?>
                                <td style="vertical-align:top;width:25%;text-align:center;padding:6px 4px;">
                                    <div style="display:inline-block;width:24px;height:24px;line-height:24px;border-radius:50%;background:<?php echo esc_attr($color); ?>;color:#fff;font-size:12px;font-weight:bold;">
                                        <?php if ($step['state'] === 'done') : ?>✓<?php elseif ($step['state'] === 'problem') : ?>!<?php else : ?><?php echo esc_html((string) ($idx + 1)); ?><?php endif; ?>
                                    </div>
                                    <div style="margin-top:8px;font-size:11px;line-height:1.35;color:#444;">
                                        <?php echo esc_html($step['label']); ?>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    <?php endforeach; ?>
<?php endif; ?>

<?php do_action('woocommerce_email_footer', $email); ?>

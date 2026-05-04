<?php

declare(strict_types=1);

/**
 * D Express — customer shipment list (Moj nalog).
 *
 * @package S7codedesign\DExpress
 *
 * @var list<array{shipment: \S7codedesign\DExpress\Domain\Shipment\Shipment, status_label: string}> $shipment_rows
 */

defined('ABSPATH') || exit;

use S7codedesign\DExpress\Domain\Shipment\Shipment;

if (empty($shipment_rows)) {
    echo '<p>' . esc_html__('Nemate D Express pošiljke povezane sa vašim porudžbinama.', 'dexpress-woocommerce') . '</p>';

    return;
}
?>

<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
    <thead>
    <tr>
        <th class="woocommerce-orders-table__header" scope="col">
            <span class="nobr"><?php echo esc_html__('Porudžbina', 'dexpress-woocommerce'); ?></span>
        </th>
        <th class="woocommerce-orders-table__header" scope="col">
            <span class="nobr"><?php echo esc_html__('Kod za praćenje', 'dexpress-woocommerce'); ?></span>
        </th>
        <th class="woocommerce-orders-table__header" scope="col">
            <span class="nobr"><?php echo esc_html__('Status', 'dexpress-woocommerce'); ?></span>
        </th>
        <th class="woocommerce-orders-table__header" scope="col">
            <span class="nobr"><?php echo esc_html__('Kreirano', 'dexpress-woocommerce'); ?></span>
        </th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($shipment_rows as $row) { ?>
        <?php
        $shipment = $row['shipment'] ?? null;
        if (!$shipment instanceof Shipment) {
            continue;
        }
        $statusLabel = isset($row['status_label']) && is_string($row['status_label']) ? $row['status_label'] : '';
        $order     = wc_get_order($shipment->orderId);
        $viewUrl   = $order ? $order->get_view_order_url() : '';
        $orderText = $order
            /* translators: %s: order number with # */
            ? sprintf(__('Broj %s', 'dexpress-woocommerce'), $order->get_order_number())
            : (string) $shipment->orderId;
        $codes     = array_map(
            static fn ($p) => $p->code->value(),
            $shipment->packages,
        );
        $codesText = $codes !== [] ? implode(', ', $codes) : '—';
        ?>
        <tr>
            <td class="woocommerce-orders-table__cell" data-title="<?php echo esc_attr__('Porudžbina', 'dexpress-woocommerce'); ?>">
                <?php if ($viewUrl !== '') { ?>
                    <a href="<?php echo esc_url($viewUrl); ?>"><?php echo esc_html($orderText); ?></a>
                <?php } else { ?>
                    <?php echo esc_html($orderText); ?>
                <?php } ?>
            </td>
            <td class="woocommerce-orders-table__cell" data-title="<?php echo esc_attr__('Kod za praćenje', 'dexpress-woocommerce'); ?>">
                <?php echo esc_html($codesText); ?>
            </td>
            <td class="woocommerce-orders-table__cell" data-title="<?php echo esc_attr__('Status', 'dexpress-woocommerce'); ?>">
                <?php echo esc_html($statusLabel); ?>
            </td>
            <td class="woocommerce-orders-table__cell" data-title="<?php echo esc_attr__('Kreirano', 'dexpress-woocommerce'); ?>">
                <time datetime="<?php echo esc_attr($shipment->createdAt->format('c')); ?>">
                    <?php echo esc_html(wp_date(wc_date_format() . ' ' . wc_time_format(), $shipment->createdAt->getTimestamp())); ?>
                </time>
            </td>
        </tr>
    <?php } ?>
    </tbody>
</table>

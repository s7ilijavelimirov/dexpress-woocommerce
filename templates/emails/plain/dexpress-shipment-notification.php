<?php
/**
 * D Express — univerzalni mejl praćenja (plain).
 *
 * @var string $email_heading
 * @var WC_Order $order
 * @var string $tracking_codes_text
 * @var \S7codedesign\DExpress\Application\Email\ShipmentEmailRenderContext|null $shipment_context
 * @var bool $is_test_shipment
 * @var WC_Email $email
 */

defined('ABSPATH') || exit;

use S7codedesign\DExpress\Application\Email\ShipmentEmailRenderContext;
use S7codedesign\DExpress\Presentation\Email\TrackingLinkBuilder;

$email_heading       = $email_heading ?? '';
$tracking_codes_text = isset($tracking_codes_text) && is_string($tracking_codes_text) ? $tracking_codes_text : '';
$is_test_shipment    = (bool) ($is_test_shipment ?? false);
$ctx                 = $shipment_context instanceof ShipmentEmailRenderContext ? $shipment_context : null;

if (!isset($order) || !$order instanceof WC_Order) {
    return;
}

echo wp_strip_all_tags((string) $email_heading) . "\n\n";

if ($is_test_shipment) {
    echo wp_strip_all_tags(__('TEST — ovo je probna pošiljka.', 'dexpress-woocommerce')) . "\n\n";
}

printf(
    wp_strip_all_tags(
        /* translators: %s: order number */
        __('Narudžbina %s — aktuelno stanje D Express pošiljke:', 'dexpress-woocommerce'),
    ),
    wp_strip_all_tags((string) $order->get_order_number()),
);
echo "\n\n";

if ($ctx !== null) {
    foreach ($ctx->rows as $row) {
        if ($row->showProblemBanner) {
            echo wp_strip_all_tags(
                __('Pažnja: prijavljen je problem u obradi ili isporuci pošiljke.', 'dexpress-woocommerce'),
            ) . "\n";
        }

        echo wp_strip_all_tags(__('Kod za praćenje:', 'dexpress-woocommerce'))
            . ' ' . wp_strip_all_tags($row->trackingCode) . "\n";

        $trackUrl = TrackingLinkBuilder::publicTrackingUrl(trim($row->trackingCode));
        if ($trackUrl !== '') {
            echo wp_strip_all_tags(__('Praćenje:', 'dexpress-woocommerce')) . ' ' . $trackUrl . "\n";
        }

        echo wp_strip_all_tags(__('Status (D Express):', 'dexpress-woocommerce'))
            . ' ' . wp_strip_all_tags($row->statusLabel) . "\n";

        echo wp_strip_all_tags($row->leadMessage) . "\n";

        foreach ($row->steps as $step) {
            $mark = match ($step['state']) {
                'done' => '[✓]',
                'current' => '[→]',
                'problem' => '[!]',
                default => '[ ]',
            };
            echo $mark . ' ' . wp_strip_all_tags($step['label']) . "\n";
        }

        echo "\n";
    }
} elseif ($tracking_codes_text !== '') {
    echo wp_strip_all_tags(__('Kodovi za praćenje:', 'dexpress-woocommerce'))
        . ' ' . wp_strip_all_tags($tracking_codes_text) . "\n";
}

echo "\n----------------------------------------\n";

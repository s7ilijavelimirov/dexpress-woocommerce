<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Shipment;

/**
 * Order note text when a shipment status changes (webhook or simulation).
 */
final class ShipmentStatusOrderNoteFormatter
{
    private function __construct()
    {
    }

    public static function format(string $packageCode, string $labelSnapshot): string
    {
        return sprintf(
            /* translators: 1: package tracking code, 2: official status label from D Express codebook / snapshot */
            __('D Express: paket %1$s — %2$s', 'dexpress-woocommerce'),
            $packageCode,
            $labelSnapshot,
        );
    }
}

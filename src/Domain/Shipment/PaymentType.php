<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Shipment;

enum PaymentType: int
{
    case Cash    = 1;
    case Invoice = 2;

    public function label(): string
    {
        return match($this) {
            self::Cash    => __('Gotovina', 'dexpress-woocommerce'),
            self::Invoice => __('Faktura', 'dexpress-woocommerce'),
        };
    }
}

<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Shipment;

enum PaymentBy: int
{
    case Sender    = 0;
    case Pickup    = 1;
    case Recipient = 2;

    public function label(): string
    {
        return match($this) {
            self::Sender    => __('Nalogodavac', 'dexpress-woocommerce'),
            self::Pickup    => __('Mesto preuzimanja', 'dexpress-woocommerce'),
            self::Recipient => __('Primalac', 'dexpress-woocommerce'),
        };
    }
}

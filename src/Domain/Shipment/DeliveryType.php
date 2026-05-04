<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Shipment;

enum DeliveryType: int
{
    case Urgent  = 1;
    case Regular = 2;

    public function label(): string
    {
        return match($this) {
            self::Urgent  => __('Hitno (isti dan)', 'dexpress-woocommerce'),
            self::Regular => __('Standardna dostava', 'dexpress-woocommerce'),
        };
    }
}

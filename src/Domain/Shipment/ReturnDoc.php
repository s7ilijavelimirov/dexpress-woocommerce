<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Shipment;

enum ReturnDoc: int
{
    case None      = 0;
    case Documents = 1;
    case Pod       = 3;

    public function label(): string
    {
        return match($this) {
            self::None      => __('Bez povraćaja', 'dexpress-woocommerce'),
            self::Documents => __('Povraćaj dokumenata', 'dexpress-woocommerce'),
            self::Pod       => __('Potvrda o isporuci (POD)', 'dexpress-woocommerce'),
        };
    }
}

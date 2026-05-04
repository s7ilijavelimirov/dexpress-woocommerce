<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Shipment;

/**
 * Minimalna grupa za perzistenciju i WooCommerce email logiku — ne duplira D Express nazive.
 * Prikaz korisniku: {@see \S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository::resolveOfficialShipmentStatusLabel()} (šifarnik); snapshot samo kad nema reda u šifarniku.
 */
enum StatusEmailBucket: string
{
    case Delivered       = 'delivered';
    case InTransit       = 'in_transit';
    case OutForDelivery  = 'out_for_delivery';
    case ProblemFailed   = 'problem_failed';
    case Other           = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Delivered      => __('Isporučeno', 'dexpress-woocommerce'),
            self::InTransit      => __('U transportu', 'dexpress-woocommerce'),
            self::OutForDelivery => __('Na isporuci', 'dexpress-woocommerce'),
            self::ProblemFailed  => __('Problem / neuspeh', 'dexpress-woocommerce'),
            self::Other          => __('Ostalo', 'dexpress-woocommerce'),
        };
    }
}

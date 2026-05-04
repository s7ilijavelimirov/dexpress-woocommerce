<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Shipment;

use S7codedesign\DExpress\Domain\Shipment\DeliveryType;
use S7codedesign\DExpress\Domain\Shipment\PaymentType;
use S7codedesign\DExpress\Domain\Shipment\ReturnDoc;

final class CreateShipmentRequest
{
    /**
     * @param array<int, array{
     *   mass: int,
     *   dim_x: int|null,
     *   dim_y: int|null,
     *   dim_z: int|null,
     *   content?: string|null,
     *   items?: list<array{order_item_id: int, qty: int}>
     * }> $packages
     */
    public function __construct(
        public readonly int          $orderId,
        public readonly int          $senderLocationId,
        public readonly DeliveryType $deliveryType,
        public readonly PaymentType  $paymentType,
        public readonly ReturnDoc    $returnDoc,
        public readonly bool         $selfDropOff,
        public readonly string       $content,
        public readonly string       $note,
        public readonly array        $packages = [],
    ) {}
}

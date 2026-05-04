<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Events;

use S7codedesign\DExpress\Domain\Shipment\Shipment;
use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;

final class StatusUpdated
{
    public function __construct(
        public readonly Shipment $shipment,
        public readonly int $orderId,
        public readonly StatusEmailBucket $bucket,
        public readonly int $rawSid,
        public readonly string $labelSnapshot,
    ) {}
}

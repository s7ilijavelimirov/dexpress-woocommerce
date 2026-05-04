<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Shipment;

use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;

/**
 * Order note text when a shipment status changes (webhook or simulation).
 */
final class ShipmentStatusOrderNoteFormatter
{
    private function __construct()
    {
    }

    public static function format(string $packageCode, string $labelSnapshot, StatusEmailBucket $bucket): string
    {
        return match ($bucket) {
            StatusEmailBucket::InTransit => sprintf(
                'D Express: Pošiljka %s je u transportu. (%s)',
                $packageCode,
                $labelSnapshot,
            ),
            StatusEmailBucket::OutForDelivery => sprintf(
                'D Express: Pošiljka %s izlazi na isporuku. (%s)',
                $packageCode,
                $labelSnapshot,
            ),
            StatusEmailBucket::Delivered => sprintf(
                'D Express: Pošiljka %s je isporučena. (%s)',
                $packageCode,
                $labelSnapshot,
            ),
            StatusEmailBucket::ProblemFailed => sprintf(
                'D Express: Problem / neuspeh za pošiljku %s. (%s)',
                $packageCode,
                $labelSnapshot,
            ),
            default => sprintf(
                'D Express: Status pošiljke %s — %s.',
                $packageCode,
                $labelSnapshot,
            ),
        };
    }
}

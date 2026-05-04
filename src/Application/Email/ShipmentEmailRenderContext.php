<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Email;

/**
 * Kontekst za HTML/plain D Express mejl (slanje i WooCommerce pregled).
 */
final class ShipmentEmailRenderContext
{
    /**
     * @param list<ShipmentEmailRowContext> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly bool $isTestShipment,
    ) {}

    public function trackingCodesText(): string
    {
        $seen = [];
        $out  = [];
        foreach ($this->rows as $row) {
            $code = trim($row->trackingCode);
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = $code;
        }

        return implode(', ', $out);
    }
}

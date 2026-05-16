<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Shipment;

use S7codedesign\DExpress\Domain\Shipment\PackageCode;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;

/**
 * Central place for sequential package / tracking code allocation from admin prefix + numeric range.
 * Range end may be increased in settings at any time; allocation always continues from MAX(existing)+1.
 */
final class ShipmentCodeAllocator
{
    /** Same text as {@see ShipmentRepository::allocatePackageCode()} exhaustion path. */
    public const RANGE_EXHAUSTED_MESSAGE = 'D Express code range exhausted. Please extend range in settings.';

    public const USAGE_WARNING_TRANSIENT = 'dexpress_code_allocation_warning';

    /**
     * Dvoslovni prefiks koda pošiljke — uklanja sve što nije slovo, velika slova, maks. 2 znaka.
     */
    public static function normalizeShipmentPrefix(string $raw): string
    {
        return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $raw), 0, 2));
    }

    /**
     * @return string|null Poruka greške (lokalizovana) ako prefiks nije tačno dva latinična slova
     */
    public static function shipmentPrefixValidationError(string $normalized): ?string
    {
        if (strlen($normalized) !== 2 || !ctype_alpha($normalized)) {
            return __('Prefiks pošiljke mora biti tačno 2 latinična slova (npr. TT).', 'dexpress-woocommerce');
        }

        return null;
    }

    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly OptionsRepository $options,
        private readonly Logger $logger,
    ) {}

    /**
     * Next package code: first in a shipment (inside DB transaction) or subsequent package in the same batch.
     *
     * @param PackageCode|null $previousInBatch null for the first package; otherwise the last allocated code in this transaction
     */
    public function generateNextCode(?PackageCode $previousInBatch = null): PackageCode
    {
        $prefix     = $this->normalizedPrefix();
        $rangeStart = $this->rangeStart();
        $rangeEnd   = $this->rangeEnd();

        if ($prefix === '' || $rangeStart <= 0 || $rangeEnd < $rangeStart) {
            throw new \RuntimeException(self::RANGE_EXHAUSTED_MESSAGE);
        }

        if ($previousInBatch === null) {
            return $this->shipments->allocatePackageCode($prefix, $rangeStart, $rangeEnd);
        }

        if (strtoupper($previousInBatch->prefix()) !== $prefix) {
            throw new \RuntimeException('D Express: prefiks koda se ne poklapa sa podešavanjima.');
        }

        $next = $previousInBatch->number() + 1;
        if ($next > $rangeEnd) {
            throw new \RuntimeException(self::RANGE_EXHAUSTED_MESSAGE);
        }

        return PackageCode::fromPrefixAndIndex($prefix, $next);
    }

    /**
     * After API tab settings are saved: if usage is ≥ 80% of configured capacity, log and queue a dismissible notice on the settings screen.
     */
    public function evaluateRangeUsageAfterSettingsSaved(): void
    {
        $prefix     = $this->normalizedPrefix();
        $rangeStart = $this->rangeStart();
        $rangeEnd   = $this->rangeEnd();

        if ($prefix === '' || $rangeStart <= 0 || $rangeEnd < $rangeStart) {
            delete_transient(self::USAGE_WARNING_TRANSIENT);

            return;
        }

        $capacity = $rangeEnd - $rangeStart + 1;
        if ($capacity < 1) {
            return;
        }

        $used  = $this->shipments->countAllocatedCodesInRange($prefix, $rangeStart, $rangeEnd);
        $ratio = $used / $capacity;

        if ($ratio >= 1.0) {
            delete_transient(self::USAGE_WARNING_TRANSIENT);

            return;
        }

        if ($ratio >= 0.8) {
            $percent = (int) round($ratio * 100);
            $message = sprintf(
                /* translators: 1: percent used, 2: two-letter prefix */
                __('D Express: iskorišćeno je oko %1$d%% opsega kodova paketa (prefiks %2$s). Povećajte vrednost „Do“ u podešavanjima pre iscrpljenja.', 'dexpress-woocommerce'),
                $percent,
                $prefix,
            );
            set_transient(
                self::USAGE_WARNING_TRANSIENT,
                ['message' => $message],
                HOUR_IN_SECONDS,
            );
            $this->logger->warning(
                sprintf(
                    '[SHIPMENT CODES] Range usage %.1f%% (used=%d, capacity=%d, prefix=%s).',
                    $ratio * 100,
                    $used,
                    $capacity,
                    $prefix,
                ),
            );
        } else {
            delete_transient(self::USAGE_WARNING_TRANSIENT);
        }
    }

    /**
     * Formatted next free code ready for display (e.g. "ZS0000083331"), or null if exhausted / not configured.
     */
    public function nextFreeCodeInConfiguredRange(): ?string
    {
        $prefix     = $this->normalizedPrefix();
        $rangeStart = $this->rangeStart();
        $rangeEnd   = $this->rangeEnd();

        if ($prefix === '' || $rangeStart <= 0 || $rangeEnd < $rangeStart) {
            return null;
        }

        $n = $this->shipments->firstFreeNumericInRange($prefix, $rangeStart, $rangeEnd);
        if ($n === null) {
            return null;
        }

        return $prefix . str_pad((string) $n, 10, '0', STR_PAD_LEFT);
    }

    public static function consumeUsageWarningTransient(): ?string
    {
        $data = get_transient(self::USAGE_WARNING_TRANSIENT);
        if (!is_array($data) || !isset($data['message']) || !is_string($data['message']) || $data['message'] === '') {
            return null;
        }

        delete_transient(self::USAGE_WARNING_TRANSIENT);

        return $data['message'];
    }

    private function normalizedPrefix(): string
    {
        return self::normalizeShipmentPrefix($this->options->getString('shipment.prefix', ''));
    }

    private function rangeStart(): int
    {
        $v = $this->options->get('shipment.range_start');
        if ($v === null || $v === '') {
            return 0;
        }
        $i = (int) $v;

        return $i > 0 ? $i : 0;
    }

    private function rangeEnd(): int
    {
        $v = $this->options->get('shipment.range_end');
        if ($v === null || $v === '') {
            return 0;
        }
        $i = (int) $v;

        return $i > 0 ? $i : 0;
    }
}

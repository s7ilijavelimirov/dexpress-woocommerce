<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Shipment;

use InvalidArgumentException;

/**
 * Tracking code assigned from the merchant's D Express range.
 * Format: 2 uppercase letters + 10 digits (e.g. TT0000000001).
 */
final class PackageCode
{
    private const PATTERN = '/^[A-Z]{2}[0-9]{10}$/';

    private function __construct(private readonly string $value) {}

    public static function fromString(string $code): self
    {
        if (!preg_match(self::PATTERN, $code)) {
            throw new InvalidArgumentException(
                sprintf('Neispravan kod paketa "%s". Očekivan format: 2 slova + 10 cifara (npr. TT0000000001).', $code)
            );
        }

        return new self($code);
    }

    /**
     * Build a code from a 2-letter prefix and a numeric index (zero-padded to 10 digits).
     */
    public static function fromPrefixAndIndex(string $prefix, int $index): self
    {
        $prefix = strtoupper($prefix);

        if (!preg_match('/^[A-Z]{2}$/', $prefix)) {
            throw new InvalidArgumentException(
                sprintf('Neispravan prefiks koda "%s". Mora biti 2 velika slova.', $prefix)
            );
        }

        if ($index <= 0) {
            throw new InvalidArgumentException('Indeks koda paketa mora biti pozitivan broj.');
        }

        return new self($prefix . str_pad((string) $index, 10, '0', STR_PAD_LEFT));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function prefix(): string
    {
        return substr($this->value, 0, 2);
    }

    public function number(): int
    {
        return (int) substr($this->value, 2);
    }
}

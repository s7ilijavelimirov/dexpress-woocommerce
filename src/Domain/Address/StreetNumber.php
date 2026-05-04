<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Address;

use InvalidArgumentException;

final class StreetNumber
{
    // Valid: 15, 15a, 23/4, 23/4a, 5-7, 5-7a, bb, BB, b.b., bb/12
    private const PATTERN = '/^((bb|BB|b\.b\.|B\.B\.)(\/[\w\p{L}]+)*|(\d(-\d){0,1}[\w\p{L}]{0,2})+(\/[\w\p{L}]+)*)$/u';

    private function __construct(private readonly string $value) {}

    public static function fromString(string $input): self
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            throw new InvalidArgumentException(
                __('Broj ulice je obavezan.', 'dexpress-woocommerce')
            );
        }

        if (!preg_match(self::PATTERN, $trimmed)) {
            throw new InvalidArgumentException(
                __('Neispravan broj ulice (npr. 15, 15a, 23/4, bb).', 'dexpress-woocommerce')
            );
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }
}

<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Address;

use InvalidArgumentException;

final class PhoneNumber
{
    private function __construct(private readonly string $canonical) {}

    public static function fromString(string $input): self
    {
        $digits = (string) preg_replace('/\D/', '', $input);

        if ($digits === '') {
            throw new InvalidArgumentException(
                __('Broj telefona je obavezan.', 'dexpress-woocommerce')
            );
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (!str_starts_with($digits, '381')) {
            if (str_starts_with($digits, '0')) {
                $digits = '381' . substr($digits, 1);
            } else {
                $digits = '381' . $digits;
            }
        }

        if (!preg_match('/^381[1-9]\d{7,8}$/', $digits)) {
            throw new InvalidArgumentException(
                __('Broj telefona mora biti srpski (npr. +381 64 123 4567).', 'dexpress-woocommerce')
            );
        }

        return new self($digits);
    }

    public function canonical(): string
    {
        return $this->canonical;
    }

    public function isMobile(): bool
    {
        return (bool) preg_match('/^3816[0-9]/', $this->canonical);
    }

    public function forDisplay(): string
    {
        return '+' . substr($this->canonical, 0, 3)
            . ' ' . substr($this->canonical, 3, 2)
            . ' ' . substr($this->canonical, 5);
    }
}

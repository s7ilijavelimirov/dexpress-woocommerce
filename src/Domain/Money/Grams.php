<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Money;

use InvalidArgumentException;

final class Grams
{
    private function __construct(private readonly int $value) {}

    public static function fromGrams(int $grams): self
    {
        if ($grams <= 0) {
            throw new InvalidArgumentException('Masa mora biti veća od 0 grama.');
        }

        return new self($grams);
    }

    public function value(): int
    {
        return $this->value;
    }
}

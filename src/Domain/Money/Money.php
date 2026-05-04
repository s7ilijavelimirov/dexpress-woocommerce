<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Money;

use InvalidArgumentException;

final class Money
{
    private function __construct(private readonly int $para) {}

    public static function fromPara(int $para): self
    {
        if ($para < 0) {
            throw new InvalidArgumentException('Iznos ne može biti negativan.');
        }

        return new self($para);
    }

    public static function fromRsd(float $rsd): self
    {
        if ($rsd < 0.0) {
            throw new InvalidArgumentException('Iznos ne može biti negativan.');
        }

        return new self((int) round($rsd * 100));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function toPara(): int
    {
        return $this->para;
    }

    public function toRsd(): float
    {
        return $this->para / 100.0;
    }

    public function isZero(): bool
    {
        return $this->para === 0;
    }
}

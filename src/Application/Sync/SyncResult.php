<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Sync;

final class SyncResult
{
    public function __construct(
        public readonly string $type,
        public readonly RowChangeStats $changes,
        public readonly bool $success,
        public readonly string $errorMessage = '',
    ) {}

    public static function success(string $type, RowChangeStats $changes): self
    {
        return new self($type, $changes, true);
    }

    public static function failure(string $type, string $errorMessage): self
    {
        return new self($type, new RowChangeStats(), false, $errorMessage);
    }

    /** Broj stvarno dodatih ili izmenjenih redova (bez identičnih ponovnih upisa). */
    public function total(): int
    {
        return $this->changes->changedRows();
    }
}

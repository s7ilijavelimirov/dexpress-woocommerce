<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Sync;

final class SyncResult
{
    public function __construct(
        public readonly string $type,
        public readonly int    $inserted,
        public readonly int    $updated,
        public readonly bool   $success,
        public readonly string $errorMessage = '',
    ) {}

    public static function success(string $type, int $inserted, int $updated = 0): self
    {
        return new self($type, $inserted, $updated, true);
    }

    public static function failure(string $type, string $errorMessage): self
    {
        return new self($type, 0, 0, false, $errorMessage);
    }

    public function total(): int
    {
        return $this->inserted + $this->updated;
    }
}

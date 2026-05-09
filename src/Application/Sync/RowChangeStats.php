<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Sync;

/**
 * Brojevi stvarnih efekata nad bazom tokom sinhronizacije (ne „obrađenih“ API redova).
 */
final class RowChangeStats
{
    public function __construct(
        public readonly int $inserted = 0,
        public readonly int $updated = 0,
        public readonly int $unchanged = 0,
        public readonly int $deleted = 0,
    ) {}

    public static function merge(self $a, self $b): self
    {
        return new self(
            $a->inserted + $b->inserted,
            $a->updated + $b->updated,
            $a->unchanged + $b->unchanged,
            $a->deleted + $b->deleted,
        );
    }

    /** Redovi koji su zaista dodati ili izmenjeni (bez „identičnih“ ponovnih upisa). */
    public function changedRows(): int
    {
        return $this->inserted + $this->updated;
    }
}

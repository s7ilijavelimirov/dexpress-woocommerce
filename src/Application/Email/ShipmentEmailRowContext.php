<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Email;

/**
 * Jedna pošiljka u mejlu (više paketa / split = više redova).
 *
 * @phpstan-type StepState 'done'|'current'|'pending'|'problem'
 * @phpstan-type StepRow array{label: string, state: StepState}
 */
final class ShipmentEmailRowContext
{
    /**
     * @param list<array{label: string, state: 'done'|'current'|'pending'|'problem'}> $steps
     */
    public function __construct(
        public readonly string $trackingCode,
        public readonly string $statusLabel,
        public readonly string $leadMessage,
        public readonly array $steps,
        public readonly bool $showProblemBanner,
    ) {}
}

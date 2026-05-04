<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Webhook;

/**
 * Normalizovan ulaz statusa (iz webhook log reda ili ekvivalentnog izvora).
 */
final class InboundStatusNotification
{
    public function __construct(
        public readonly int $webhookLogId,
        public readonly string $packageCode,
        public readonly string $sidRaw,
        public readonly string $occurredAtUtcMysql,
    ) {}
}

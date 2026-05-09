<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Sync;

/**
 * Jedinstven redosled šifarnika za ručni „sync sve“, cron {@see \S7codedesign\DExpress\Infrastructure\Cron\WpCronScheduler::runAll()}
 * i admin sekvencijalni AJAX — prati hijerarhiju API polja.
 */
final class SyncCatalogOrder
{
    /** @var list<string> */
    public const ALL_SEQUENCE = [
        'municipalities',
        'centres',
        'towns',
        'streets',
        'status_codes',
        'dispensers',
        'locations',
        'payments',
        'shops',
    ];
}

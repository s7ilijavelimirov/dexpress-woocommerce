<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container\Providers;

use S7codedesign\DExpress\Application\Sync\SyncCentresService;
use S7codedesign\DExpress\Application\Sync\SyncDispensersService;
use S7codedesign\DExpress\Application\Sync\SyncLocationsService;
use S7codedesign\DExpress\Application\Sync\SyncMunicipalitiesService;
use S7codedesign\DExpress\Application\Sync\SyncPaymentsService;
use S7codedesign\DExpress\Application\Sync\SyncShopsService;
use S7codedesign\DExpress\Application\Sync\SyncStatusCodesService;
use S7codedesign\DExpress\Application\Sync\SyncStreetsService;
use S7codedesign\DExpress\Application\Sync\SyncTownsService;
use S7codedesign\DExpress\Container\Container;
use S7codedesign\DExpress\Container\ServiceProvider;
use S7codedesign\DExpress\Infrastructure\Cron\WpCronScheduler;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbWebhookLogRepository;

final class CronServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(
            WpCronScheduler::class,
            static fn (Container $c) => new WpCronScheduler(
                $c->get(SyncTownsService::class),
                $c->get(SyncStreetsService::class),
                $c->get(SyncMunicipalitiesService::class),
                $c->get(SyncStatusCodesService::class),
                $c->get(SyncDispensersService::class),
                $c->get(SyncLocationsService::class),
                $c->get(SyncPaymentsService::class),
                $c->get(SyncCentresService::class),
                $c->get(SyncShopsService::class),
                $c->get(WpdbWebhookLogRepository::class),
                $c->get(Logger::class),
            ),
        );
    }
}

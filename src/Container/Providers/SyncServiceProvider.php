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
use S7codedesign\DExpress\Infrastructure\Api\DExpressApiClient;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\CentreRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\DispenserRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\LocationRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\MunicipalityRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\ShopRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StreetRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\TownRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPaymentRepository;

final class SyncServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        // Repositories
        $container->singleton(
            TownRepository::class,
            static function (): TownRepository {
                global $wpdb;
                return new TownRepository($wpdb);
            },
        );

        $container->singleton(
            StreetRepository::class,
            static function (): StreetRepository {
                global $wpdb;
                return new StreetRepository($wpdb);
            },
        );

        $container->singleton(
            MunicipalityRepository::class,
            static function (): MunicipalityRepository {
                global $wpdb;
                return new MunicipalityRepository($wpdb);
            },
        );

        $container->singleton(
            StatusCodeRepository::class,
            static function (): StatusCodeRepository {
                global $wpdb;
                return new StatusCodeRepository($wpdb);
            },
        );

        $container->singleton(
            DispenserRepository::class,
            static function (): DispenserRepository {
                global $wpdb;
                return new DispenserRepository($wpdb);
            },
        );

        $container->singleton(
            LocationRepository::class,
            static function (): LocationRepository {
                global $wpdb;
                return new LocationRepository($wpdb);
            },
        );

        $container->singleton(
            CentreRepository::class,
            static function (): CentreRepository {
                global $wpdb;
                return new CentreRepository($wpdb);
            },
        );

        $container->singleton(
            ShopRepository::class,
            static function (): ShopRepository {
                global $wpdb;
                return new ShopRepository($wpdb);
            },
        );

        $container->singleton(
            WpdbPaymentRepository::class,
            static function (): WpdbPaymentRepository {
                global $wpdb;
                return new WpdbPaymentRepository($wpdb);
            },
        );

        // Sync services
        $container->singleton(
            SyncTownsService::class,
            static fn (Container $c) => new SyncTownsService(
                $c->get(DExpressApiClient::class),
                $c->get(TownRepository::class),
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            SyncStreetsService::class,
            static fn (Container $c) => new SyncStreetsService(
                $c->get(DExpressApiClient::class),
                $c->get(StreetRepository::class),
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            SyncMunicipalitiesService::class,
            static fn (Container $c) => new SyncMunicipalitiesService(
                $c->get(DExpressApiClient::class),
                $c->get(MunicipalityRepository::class),
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            SyncStatusCodesService::class,
            static fn (Container $c) => new SyncStatusCodesService(
                $c->get(DExpressApiClient::class),
                $c->get(StatusCodeRepository::class),
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            SyncDispensersService::class,
            static fn (Container $c) => new SyncDispensersService(
                $c->get(DExpressApiClient::class),
                $c->get(DispenserRepository::class),
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            SyncLocationsService::class,
            static fn (Container $c) => new SyncLocationsService(
                $c->get(DExpressApiClient::class),
                $c->get(LocationRepository::class),
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            SyncCentresService::class,
            static fn (Container $c) => new SyncCentresService(
                $c->get(DExpressApiClient::class),
                $c->get(CentreRepository::class),
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            SyncShopsService::class,
            static fn (Container $c) => new SyncShopsService(
                $c->get(DExpressApiClient::class),
                $c->get(ShopRepository::class),
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            SyncPaymentsService::class,
            static fn (Container $c) => new SyncPaymentsService(
                $c->get(DExpressApiClient::class),
                $c->get(WpdbPaymentRepository::class),
                $c->get(OptionsRepository::class),
            ),
        );
    }
}

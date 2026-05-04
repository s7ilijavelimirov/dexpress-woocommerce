<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container\Providers;

use S7codedesign\DExpress\Container\Container;
use S7codedesign\DExpress\Container\ServiceProvider;
use S7codedesign\DExpress\Application\Sync\SyncCentresService;
use S7codedesign\DExpress\Application\Sync\SyncDispensersService;
use S7codedesign\DExpress\Application\Sync\SyncLocationsService;
use S7codedesign\DExpress\Application\Sync\SyncMunicipalitiesService;
use S7codedesign\DExpress\Application\Sync\SyncShopsService;
use S7codedesign\DExpress\Application\Sync\SyncStatusCodesService;
use S7codedesign\DExpress\Application\Sync\SyncStreetsService;
use S7codedesign\DExpress\Application\Sync\SyncTownsService;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbStreetRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbTownRepository;
use S7codedesign\DExpress\Presentation\Admin\Ajax\ManualSyncController;
use S7codedesign\DExpress\Presentation\Admin\Ajax\SenderLocationController;
use S7codedesign\DExpress\Presentation\Admin\Ajax\TestConnectionController;
use S7codedesign\DExpress\Presentation\Admin\Handlers\SettingsSaveHandler;
use S7codedesign\DExpress\Presentation\Admin\Menu\AdminMenu;
use S7codedesign\DExpress\Presentation\Admin\Pages\DashboardPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\DiagnosticsPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\SettingsPage;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;
use S7codedesign\DExpress\Presentation\Admin\Pages\ShipmentsPage;

final class AdminServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(
            WpdbSenderLocationRepository::class,
            static function (): WpdbSenderLocationRepository {
                global $wpdb;
                return new WpdbSenderLocationRepository($wpdb);
            },
        );

        $container->singleton(
            WpdbTownRepository::class,
            static function (): WpdbTownRepository {
                global $wpdb;
                return new WpdbTownRepository($wpdb);
            },
        );

        $container->singleton(
            WpdbStreetRepository::class,
            static function (): WpdbStreetRepository {
                global $wpdb;
                return new WpdbStreetRepository($wpdb);
            },
        );

        $container->singleton(
            SettingsPage::class,
            static fn (Container $c) => new SettingsPage(
                $c->get(OptionsRepository::class),
                $c->get(WpdbSenderLocationRepository::class),
            ),
        );

        $container->singleton(
            DashboardPage::class,
            static fn (Container $c): DashboardPage => new DashboardPage(
                $c->get(ShipmentRepository::class),
            ),
        );

        $container->singleton(
            ShipmentsPage::class,
            static fn (Container $c): ShipmentsPage => new ShipmentsPage(
                $c->get(ShipmentRepository::class),
                $c->get(StatusCodeRepository::class),
            ),
        );

        $container->singleton(
            DiagnosticsPage::class,
            static fn (Container $c): DiagnosticsPage => new DiagnosticsPage(
                $c->get(OptionsRepository::class),
                $c->get(Logger::class),
            ),
        );

        $container->singleton(
            AdminMenu::class,
            static fn (Container $c) => new AdminMenu(
                $c->get(DashboardPage::class),
                $c->get(ShipmentsPage::class),
                $c->get(SettingsPage::class),
                $c->get(DiagnosticsPage::class),
            ),
        );

        $container->singleton(
            SettingsSaveHandler::class,
            static fn (Container $c) => new SettingsSaveHandler(
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            TestConnectionController::class,
            static fn (Container $c) => new TestConnectionController(
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            SenderLocationController::class,
            static fn (Container $c) => new SenderLocationController(
                $c->get(WpdbSenderLocationRepository::class),
                $c->get(WpdbTownRepository::class),
                $c->get(WpdbStreetRepository::class),
            ),
        );

        $container->singleton(
            ManualSyncController::class,
            static fn (Container $c) => new ManualSyncController(
                $c->get(SyncTownsService::class),
                $c->get(SyncStreetsService::class),
                $c->get(SyncMunicipalitiesService::class),
                $c->get(SyncStatusCodesService::class),
                $c->get(SyncDispensersService::class),
                $c->get(SyncLocationsService::class),
                $c->get(SyncCentresService::class),
                $c->get(SyncShopsService::class),
                $c->get(Logger::class),
            ),
        );
    }
}

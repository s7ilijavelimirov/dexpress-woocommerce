<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container\Providers;

use S7codedesign\DExpress\Container\Container;
use S7codedesign\DExpress\Container\ServiceProvider;
use S7codedesign\DExpress\Application\Sync\SyncCentresService;
use S7codedesign\DExpress\Application\Sync\SyncDispensersService;
use S7codedesign\DExpress\Application\Sync\SyncLocationsService;
use S7codedesign\DExpress\Application\Sync\SyncMunicipalitiesService;
use S7codedesign\DExpress\Application\Sync\SyncPaymentsService;
use S7codedesign\DExpress\Application\Sync\SyncShopsService;
use S7codedesign\DExpress\Application\Sync\SyncStatusCodesService;
use S7codedesign\DExpress\Application\Sync\SyncStreetsService;
use S7codedesign\DExpress\Application\Shipment\ShipmentCodeAllocator;
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
use S7codedesign\DExpress\Application\Simulation\SimulationService;
use S7codedesign\DExpress\Presentation\Admin\Pages\SettingsPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\PaymentsPage;
use S7codedesign\DExpress\Application\Shipment\CreateShipmentService;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageProfileRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPaymentRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbShipmentRepository;
use S7codedesign\DExpress\Presentation\Admin\Ajax\BulkShipmentController;
use S7codedesign\DExpress\Presentation\Admin\Ajax\PackageProfileController;
use S7codedesign\DExpress\Presentation\Admin\Hooks\OrdersBulkAction;
use S7codedesign\DExpress\Presentation\Admin\Pages\BulkShipmentPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\PackageProfilesPage;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;
use S7codedesign\DExpress\Presentation\Admin\Hooks\OrdersListDeliveryStatusColumn;
use S7codedesign\DExpress\Presentation\Admin\Pages\OnboardingPage;
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
            WpdbPackageProfileRepository::class,
            static function (): WpdbPackageProfileRepository {
                global $wpdb;
                return new WpdbPackageProfileRepository($wpdb);
            },
        );

        $container->singleton(
            PackageProfileController::class,
            static fn (Container $c) => new PackageProfileController(
                $c->get(WpdbPackageProfileRepository::class),
            ),
        );

        $container->singleton(
            BulkShipmentController::class,
            static fn (Container $c) => new BulkShipmentController(
                $c->get(CreateShipmentService::class),
            ),
        );

        $container->singleton(
            OrdersBulkAction::class,
            static fn (Container $c) => new OrdersBulkAction(
                $c->get(WpdbShipmentRepository::class),
            ),
        );

        $container->singleton(
            PackageProfilesPage::class,
            static fn (Container $c) => new PackageProfilesPage(
                $c->get(WpdbPackageProfileRepository::class),
            ),
        );

        $container->singleton(
            BulkShipmentPage::class,
            static fn (Container $c) => new BulkShipmentPage(
                $c->get(WpdbPackageProfileRepository::class),
                $c->get(WpdbSenderLocationRepository::class),
            ),
        );

        $container->singleton(
            SettingsPage::class,
            static fn (Container $c) => new SettingsPage(
                $c->get(OptionsRepository::class),
                $c->get(WpdbSenderLocationRepository::class),
                $c->get(ShipmentRepository::class),
                $c->get(SimulationService::class),
            ),
        );

        $container->singleton(
            DashboardPage::class,
            static fn (Container $c): DashboardPage => new DashboardPage(
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            ShipmentsPage::class,
            static function (Container $c): ShipmentsPage {
                global $wpdb;
                return new ShipmentsPage($wpdb, $c->get(WpdbPackageProfileRepository::class));
            },
        );

        $container->singleton(
            DiagnosticsPage::class,
            static fn (Container $c): DiagnosticsPage => new DiagnosticsPage(
                $c->get(OptionsRepository::class),
                $c->get(Logger::class),
            ),
        );

        $container->singleton(
            PaymentsPage::class,
            static fn (Container $c): PaymentsPage => new PaymentsPage(
                $c->get(WpdbPaymentRepository::class),
                $c->get(SyncPaymentsService::class),
                $c->get(OptionsRepository::class),
            ),
        );

        $container->singleton(
            OnboardingPage::class,
            static fn (Container $c): OnboardingPage => new OnboardingPage(
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
                $c->get(PaymentsPage::class),
                $c->get(OnboardingPage::class),
                $c->get(PackageProfilesPage::class),
                $c->get(BulkShipmentPage::class),
            ),
        );

        $container->singleton(
            SettingsSaveHandler::class,
            static fn (Container $c) => new SettingsSaveHandler(
                $c->get(OptionsRepository::class),
                $c->get(ShipmentCodeAllocator::class),
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
            OrdersListDeliveryStatusColumn::class,
            static fn (Container $c) => new OrdersListDeliveryStatusColumn(
                $c->get(ShipmentRepository::class),
                $c->get(StatusCodeRepository::class),
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
                $c->get(SyncPaymentsService::class),
                $c->get(SyncCentresService::class),
                $c->get(SyncShopsService::class),
                $c->get(Logger::class),
            ),
        );
    }
}

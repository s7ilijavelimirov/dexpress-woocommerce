<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container\Providers;

use S7codedesign\DExpress\Application\Address\RecipientAddressCheckService;
use S7codedesign\DExpress\Application\Shipment\CreateShipmentService;
use S7codedesign\DExpress\Application\Shipment\OrderRecipientResolver;
use S7codedesign\DExpress\Application\Shipment\ShipmentCodeAllocator;
use S7codedesign\DExpress\Container\Container;
use S7codedesign\DExpress\Container\ServiceProvider;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Api\DExpressApiClient;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\AddressSearchRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\TransactionRunner;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageItemRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbShipmentRepository;

final class ShipmentServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(
            AddressSearchRepository::class,
            static function (): AddressSearchRepository {
                global $wpdb;
                return new AddressSearchRepository($wpdb);
            },
        );

        $container->singleton(
            OrderRecipientResolver::class,
            static fn (Container $c): OrderRecipientResolver => new OrderRecipientResolver(
                $c->get(AddressSearchRepository::class),
            ),
        );

        $container->singleton(
            TransactionRunner::class,
            static function (): TransactionRunner {
                global $wpdb;
                return new TransactionRunner($wpdb);
            },
        );

        $container->singleton(
            WpdbPackageRepository::class,
            static function (): WpdbPackageRepository {
                global $wpdb;
                return new WpdbPackageRepository($wpdb);
            },
        );

        $container->singleton(
            WpdbPackageItemRepository::class,
            static function (): WpdbPackageItemRepository {
                global $wpdb;
                return new WpdbPackageItemRepository($wpdb);
            },
        );

        $container->singleton(
            WpdbShipmentRepository::class,
            static function (Container $c): WpdbShipmentRepository {
                global $wpdb;
                return new WpdbShipmentRepository($wpdb, $c->get(WpdbPackageRepository::class));
            },
        );

        // Bind the interface to the concrete implementation
        $container->singleton(
            ShipmentRepository::class,
            static fn (Container $c): WpdbShipmentRepository => $c->get(WpdbShipmentRepository::class),
        );

        $container->singleton(
            RecipientAddressCheckService::class,
            static fn (Container $c): RecipientAddressCheckService => new RecipientAddressCheckService(
                $c->get(DExpressApiClient::class),
                $c->get(OptionsRepository::class),
                $c->get(Logger::class),
            ),
        );

        $container->singleton(
            ShipmentCodeAllocator::class,
            static fn (Container $c): ShipmentCodeAllocator => new ShipmentCodeAllocator(
                $c->get(ShipmentRepository::class),
                $c->get(OptionsRepository::class),
                $c->get(Logger::class),
            ),
        );

        $container->singleton(
            CreateShipmentService::class,
            static fn (Container $c): CreateShipmentService => new CreateShipmentService(
                $c->get(ShipmentRepository::class),
                $c->get(WpdbSenderLocationRepository::class),
                $c->get(DExpressApiClient::class),
                $c->get(OptionsRepository::class),
                $c->get(TransactionRunner::class),
                $c->get(OrderRecipientResolver::class),
                $c->get(WpdbPackageRepository::class),
                $c->get(WpdbPackageItemRepository::class),
                $c->get(Logger::class),
                $c->get(RecipientAddressCheckService::class),
                $c->get(ShipmentCodeAllocator::class),
            ),
        );

    }
}

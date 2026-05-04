<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container\Providers;

use S7codedesign\DExpress\Application\Webhook\ProcessWebhookService;
use S7codedesign\DExpress\Application\Webhook\ShipmentStatusIngestionService;
use S7codedesign\DExpress\Container\Container;
use S7codedesign\DExpress\Container\ServiceProvider;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Domain\Status\StatusMapper;
use S7codedesign\DExpress\Infrastructure\Async\WebhookJobScheduler;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbShipmentStatusRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbWebhookLogRepository;
use S7codedesign\DExpress\Presentation\Rest\WebhookController;

final class WebhookServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(
            StatusMapper::class,
            static fn (): StatusMapper => new StatusMapper(),
        );

        $container->singleton(
            WpdbWebhookLogRepository::class,
            static function (): WpdbWebhookLogRepository {
                global $wpdb;
                return new WpdbWebhookLogRepository($wpdb);
            },
        );

        $container->singleton(
            WpdbShipmentStatusRepository::class,
            static function (): WpdbShipmentStatusRepository {
                global $wpdb;
                return new WpdbShipmentStatusRepository($wpdb);
            },
        );

        $container->singleton(
            WebhookJobScheduler::class,
            static fn (Container $c): WebhookJobScheduler => new WebhookJobScheduler(
                $c->get(Logger::class),
            ),
        );

        $container->singleton(
            ShipmentStatusIngestionService::class,
            static fn (Container $c): ShipmentStatusIngestionService => new ShipmentStatusIngestionService(
                $c->get(ShipmentRepository::class),
                $c->get(WpdbShipmentStatusRepository::class),
                $c->get(StatusMapper::class),
                $c->get(StatusCodeRepository::class),
                $c->get(Logger::class),
            ),
        );

        $container->singleton(
            ProcessWebhookService::class,
            static fn (Container $c): ProcessWebhookService => new ProcessWebhookService(
                $c->get(WpdbWebhookLogRepository::class),
                $c->get(ShipmentRepository::class),
                $c->get(ShipmentStatusIngestionService::class),
                $c->get(Logger::class),
            ),
        );

        $container->singleton(
            WebhookController::class,
            static fn (Container $c): WebhookController => new WebhookController(
                $c->get(WpdbWebhookLogRepository::class),
                $c->get(OptionsRepository::class),
                $c->get(Logger::class),
                $c->get(WebhookJobScheduler::class),
            ),
        );
    }
}

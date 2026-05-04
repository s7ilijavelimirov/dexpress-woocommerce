<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container\Providers;

use S7codedesign\DExpress\Application\Simulation\SimulationService;
use S7codedesign\DExpress\Container\Container;
use S7codedesign\DExpress\Container\ServiceProvider;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Async\WebhookJobScheduler;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbWebhookLogRepository;

final class SimulationServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(
            SimulationService::class,
            static function (Container $c): SimulationService {
                if (!$c->has(ShipmentRepository::class)) {
                    throw new \LogicException(
                        'ShipmentRepository is not registered. Register ShipmentServiceProvider before SimulationServiceProvider.',
                    );
                }
                if (!$c->has(WpdbWebhookLogRepository::class)) {
                    throw new \LogicException(
                        'WpdbWebhookLogRepository is not registered. Register WebhookServiceProvider before SimulationServiceProvider.',
                    );
                }
                if (!$c->has(WebhookJobScheduler::class)) {
                    throw new \LogicException(
                        'WebhookJobScheduler is not registered. Register WebhookServiceProvider before SimulationServiceProvider.',
                    );
                }

                return new SimulationService(
                    $c->get(ShipmentRepository::class),
                    $c->get(WpdbWebhookLogRepository::class),
                    $c->get(OptionsRepository::class),
                    $c->get(Logger::class),
                    $c->get(WebhookJobScheduler::class),
                );
            },
        );
    }
}

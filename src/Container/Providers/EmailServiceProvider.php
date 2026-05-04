<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container\Providers;

use S7codedesign\DExpress\Application\Email\EmailNotificationSubscriber;
use S7codedesign\DExpress\Application\Email\ShipmentEmailRenderContextFactory;
use S7codedesign\DExpress\Container\Container;
use S7codedesign\DExpress\Container\ServiceProvider;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Domain\Status\StatusMapper;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;

final class EmailServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(
            ShipmentEmailRenderContextFactory::class,
            static fn (Container $c): ShipmentEmailRenderContextFactory => new ShipmentEmailRenderContextFactory(
                $c->get(StatusCodeRepository::class),
                $c->get(StatusMapper::class),
            ),
        );

        $container->singleton(
            EmailNotificationSubscriber::class,
            static fn (Container $c): EmailNotificationSubscriber => new EmailNotificationSubscriber(
                $c->get(OptionsRepository::class),
                $c->get(ShipmentRepository::class),
                $c->get(StatusMapper::class),
                $c->get(ShipmentEmailRenderContextFactory::class),
                $c->get(Logger::class),
            ),
        );
    }
}

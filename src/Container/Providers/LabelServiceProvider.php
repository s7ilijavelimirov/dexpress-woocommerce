<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container\Providers;

use S7codedesign\DExpress\Application\Shipment\OrderRecipientResolver;
use S7codedesign\DExpress\Container\Container;
use S7codedesign\DExpress\Container\ServiceProvider;
use S7codedesign\DExpress\Infrastructure\Barcode\Code128;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbTownRepository;
use S7codedesign\DExpress\Presentation\Admin\Label\PrintLabelController;

final class LabelServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(
            Code128::class,
            static fn (): Code128 => new Code128(),
        );

        $container->singleton(
            PrintLabelController::class,
            static fn (Container $c): PrintLabelController => new PrintLabelController(
                $c->get(WpdbShipmentRepository::class),
                $c->get(WpdbSenderLocationRepository::class),
                $c->get(WpdbTownRepository::class),
                $c->get(Code128::class),
                $c->get(OrderRecipientResolver::class),
                $c->get(Logger::class),
            ),
        );
    }
}

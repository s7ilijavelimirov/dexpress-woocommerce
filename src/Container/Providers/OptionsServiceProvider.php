<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container\Providers;

use S7codedesign\DExpress\Container\Container;
use S7codedesign\DExpress\Container\ServiceProvider;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;

final class OptionsServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(
            OptionsRepository::class,
            static fn () => new OptionsRepository(),
        );

        $container->singleton(
            Logger::class,
            static fn (Container $c) => new Logger($c->get(OptionsRepository::class)),
        );
    }
}

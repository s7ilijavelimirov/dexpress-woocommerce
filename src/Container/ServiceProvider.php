<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container;

interface ServiceProvider
{
    public function register(Container $container): void;
}

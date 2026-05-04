<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container\Providers;

use S7codedesign\DExpress\Container\Container;
use S7codedesign\DExpress\Container\ServiceProvider;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Persistence\AddressSearchRepository;
use S7codedesign\DExpress\Presentation\Frontend\Ajax\AutocompleteController;
use S7codedesign\DExpress\Application\Address\RecipientAddressCheckService;
use S7codedesign\DExpress\Presentation\Frontend\Checkout\CheckoutFields;
use S7codedesign\DExpress\Presentation\Frontend\Checkout\CheckoutValidator;
use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressShippingMethod;
use S7codedesign\DExpress\Presentation\Frontend\Tracking\MyAccountTrackingTab;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;

final class FrontendServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(
            AutocompleteController::class,
            static fn (Container $c) => new AutocompleteController(
                $c->get(AddressSearchRepository::class),
            ),
        );

        $container->singleton(
            CheckoutFields::class,
            static fn (Container $c) => new CheckoutFields(
                $c->get(Logger::class),
                $c->get(AddressSearchRepository::class),
            ),
        );

        $container->singleton(
            CheckoutValidator::class,
            static fn (Container $c): CheckoutValidator => new CheckoutValidator(
                $c->get(RecipientAddressCheckService::class),
            ),
        );

        $container->singleton(
            MyAccountTrackingTab::class,
            static fn (Container $c) => new MyAccountTrackingTab(
                $c->get(OptionsRepository::class),
                $c->get(ShipmentRepository::class),
                $c->get(StatusCodeRepository::class),
            ),
        );

        // Register D Express as a WooCommerce shipping method
        add_filter('woocommerce_shipping_methods', static function (array $methods): array {
            $methods['dexpress'] = DexpressShippingMethod::class;
            return $methods;
        });
    }
}

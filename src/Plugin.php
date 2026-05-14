<?php

declare(strict_types=1);

namespace S7codedesign\DExpress;

use S7codedesign\DExpress\Container\Container;
use S7codedesign\DExpress\Application\Email\EmailNotificationSubscriber;
use S7codedesign\DExpress\Application\Simulation\SimulationService;
use S7codedesign\DExpress\Application\Webhook\ProcessWebhookService;
use S7codedesign\DExpress\Container\Providers\AdminServiceProvider;
use S7codedesign\DExpress\Container\Providers\ApiServiceProvider;
use S7codedesign\DExpress\Container\Providers\CronServiceProvider;
use S7codedesign\DExpress\Container\Providers\EmailServiceProvider;
use S7codedesign\DExpress\Container\Providers\FrontendServiceProvider;
use S7codedesign\DExpress\Container\Providers\LabelServiceProvider;
use S7codedesign\DExpress\Container\Providers\OptionsServiceProvider;
use S7codedesign\DExpress\Container\Providers\ShipmentServiceProvider;
use S7codedesign\DExpress\Container\Providers\SimulationServiceProvider;
use S7codedesign\DExpress\Container\Providers\SyncServiceProvider;
use S7codedesign\DExpress\Container\Providers\WebhookServiceProvider;
use S7codedesign\DExpress\Infrastructure\Cron\WpCronScheduler;
use S7codedesign\DExpress\Infrastructure\Persistence\DatabaseInstaller;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Presentation\Admin\Ajax\ShipmentWorkflowController;
use S7codedesign\DExpress\Presentation\Admin\Ajax\ManualSyncController;
use S7codedesign\DExpress\Presentation\Admin\Label\PrintLabelController;
use S7codedesign\DExpress\Presentation\Admin\Ajax\SenderLocationController;
use S7codedesign\DExpress\Presentation\Admin\Ajax\TestConnectionController;
use S7codedesign\DExpress\Presentation\Admin\Handlers\SettingsSaveHandler;
use S7codedesign\DExpress\Presentation\Admin\Menu\AdminMenu;
use S7codedesign\DExpress\Presentation\Admin\Ajax\BulkShipmentController;
use S7codedesign\DExpress\Presentation\Admin\Ajax\PackageProfileController;
use S7codedesign\DExpress\Presentation\Admin\Hooks\OrdersListDeliveryStatusColumn;
use S7codedesign\DExpress\Presentation\Admin\Metabox\OrderShipmentMetabox;
use S7codedesign\DExpress\Presentation\Admin\Pages\OnboardingPage;
use S7codedesign\DExpress\Presentation\Admin\Metabox\PackageShopInfoMetabox;
use S7codedesign\DExpress\Presentation\Admin\Pages\SettingsPage;
use S7codedesign\DExpress\Presentation\Frontend\Ajax\AutocompleteController;
use S7codedesign\DExpress\Presentation\Frontend\Ajax\PackageShopDispenserController;
use S7codedesign\DExpress\Presentation\Frontend\Checkout\CheckoutFields;
use S7codedesign\DExpress\Presentation\Frontend\Checkout\PackageShopCustomerFlow;
use S7codedesign\DExpress\Presentation\Frontend\Checkout\PackageShopInfoPanel;
use S7codedesign\DExpress\Presentation\Frontend\Checkout\CheckoutValidator;
use S7codedesign\DExpress\Presentation\Frontend\Tracking\MyAccountTrackingTab;
use S7codedesign\DExpress\Presentation\Hooks\HposCompatDeclarer;
use S7codedesign\DExpress\Presentation\Rest\WebhookController;

final class Plugin
{
    private static ?self $instance = null;

    private Container $container;

    private bool $booted = false;

    private function __construct()
    {
        $this->container = new Container();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        global $wpdb;
        DatabaseInstaller::maybeUpgrade($wpdb);

        $this->loadTextDomain();
        HposCompatDeclarer::declare();

        (new OptionsServiceProvider())->register($this->container);
        // WebhookServiceProvider before SimulationServiceProvider (simulacija koristi webhook log repozitorijum).
        (new ShipmentServiceProvider())->register($this->container);
        (new ApiServiceProvider())->register($this->container);
        (new SyncServiceProvider())->register($this->container);
        (new WebhookServiceProvider())->register($this->container);
        (new CronServiceProvider())->register($this->container);
        (new FrontendServiceProvider())->register($this->container);
        (new SimulationServiceProvider())->register($this->container);
        (new EmailServiceProvider())->register($this->container);

        $this->container->get(WebhookController::class)->registerRoutes();

        add_action('dexpress_process_webhook', static function (int $logId): void {
            try {
                self::getInstance()->getContainer()->get(ProcessWebhookService::class)->process($logId);
            } catch (\Throwable $e) {
                self::getInstance()->getContainer()->get(Logger::class)->error(
                    '[WEBHOOK ASYNC] ' . $e->getMessage(),
                );
            }
        }, 10, 1);

        $this->container->get(EmailNotificationSubscriber::class)->register();

        $this->registerFrontendHooks();

        if (is_admin()) {
            (new AdminServiceProvider())->register($this->container);
            (new LabelServiceProvider())->register($this->container);
            $this->registerAdminHooks();
        }

        $this->container->get(WpCronScheduler::class)->register();
        $this->container->get(SimulationService::class)->register();

        add_action('dexpress_simulation_settings_saved', function (): void {
            self::getInstance()->getContainer()->get(SimulationService::class)->unschedule();
        });
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    private function registerFrontendHooks(): void
    {
        // AJAX handlers for autocomplete — must register unconditionally (fired via admin-ajax.php)
        $this->container->get(AutocompleteController::class)->register();
        $this->container->get(PackageShopDispenserController::class)->register();

        $checkoutFields = $this->container->get(CheckoutFields::class);

        // Classic checkout only — closure must not be [$obj,'injectPostedData'] (avoids stale opcode/autoload callbacks).
        add_filter(
            'woocommerce_checkout_posted_data',
            static function ($posted_data) use ($checkoutFields) {
                if (!is_array($posted_data)) {
                    return $posted_data;
                }

                return $checkoutFields->injectPostedData($posted_data);
            },
            10,
            1,
        );

        // Checkout fields and validation — hooks fire only on frontend (is_checkout guard inside)
        $checkoutFields->register();
        $this->container->get(CheckoutValidator::class)->register();
        $this->container->get(PackageShopInfoPanel::class)->register();
        (new PackageShopCustomerFlow())->register();
        $this->container->get(MyAccountTrackingTab::class)->register();
    }

    private function registerAdminHooks(): void
    {
        $this->container->get(AdminMenu::class)->register();
        $this->container->get(OnboardingPage::class)->register();
        $this->container->get(SettingsSaveHandler::class)->register();
        $this->container->get(TestConnectionController::class)->register();
        $this->container->get(SenderLocationController::class)->register();
        $this->container->get(ManualSyncController::class)->register();
        $this->container->get(ShipmentWorkflowController::class)->register();
        $this->container->get(OrderShipmentMetabox::class)->register();
        $this->container->get(PackageShopInfoMetabox::class)->register();
        $this->container->get(PrintLabelController::class)->register();
        $this->container->get(OrdersListDeliveryStatusColumn::class)->register();
        $this->container->get(PackageProfileController::class)->register();
        $this->container->get(BulkShipmentController::class)->register();
    }

    private function loadTextDomain(): void
    {
        load_plugin_textdomain(
            'dexpress-woocommerce',
            false,
            dirname(DEXPRESS_PLUGIN_BASENAME) . '/languages'
        );
    }
}

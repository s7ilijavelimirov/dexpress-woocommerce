<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Email;

use S7codedesign\DExpress\Domain\Events\ShipmentCreated;
use S7codedesign\DExpress\Domain\Events\StatusUpdated;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;
use S7codedesign\DExpress\Domain\Status\StatusMapper;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Presentation\Email\AbstractDExpressEmail;
use S7codedesign\DExpress\Presentation\Email\EmailShipmentNotification;
use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressPackageShopShippingMethod;
use WC_Email;
use WC_Order;

final class EmailNotificationSubscriber
{
    private const META_CREATED          = '_dexpress_email_sent_created';
    private const META_IN_TRANSIT       = '_dexpress_email_sent_in_transit';
    private const META_OUT_FOR_DELIVERY = '_dexpress_email_sent_out_for_delivery';
    private const META_DELIVERED        = '_dexpress_email_sent_delivered';
    private const META_PROBLEM          = '_dexpress_email_sent_problem';
    private const META_PACKAGE_SHOP_READY_SENT = '_dexpress_package_shop_ready_email_sent';
    /** @var list<int> */
    private const PACKAGE_SHOP_READY_SIDS = [830, 842];
    private const PACKAGE_SHOP_READY_EMAIL_ID = 'dexpress_package_shop_ready_for_pickup';
    private const PACKAGE_SHOP_READY_EMAIL_CLASS = 'S7codedesign\\DExpress\\Presentation\\Email\\EmailPackageShopReadyForPickup';

    public function __construct(
        private readonly OptionsRepository $options,
        private readonly ShipmentRepository $shipments,
        private readonly StatusMapper $statusMapper,
        private readonly ShipmentEmailRenderContextFactory $renderContextFactory,
        private readonly Logger $logger,
    ) {}

    public function register(): void
    {
        add_action('plugins_loaded', [$this, 'registerEmailClassesFilter'], 20);
        add_action('woocommerce_init', [$this, 'registerShipmentEventHooks']);
        add_filter('woocommerce_order_actions', [$this, 'registerPackageShopOrderAction'], 10, 2);
        add_action('woocommerce_order_action_dexpress_send_package_shop_ready_email', [$this, 'handleManualPackageShopReadyEmail']);
    }

    public function registerEmailClassesFilter(): void
    {
        if (!class_exists(\WooCommerce::class)) {
            return;
        }

        add_filter('woocommerce_email_classes', function (array $emails): array {
            $emails['DExpress_Email_Shipment_Notification'] = new EmailShipmentNotification();

            $packageShopEmailClass = self::PACKAGE_SHOP_READY_EMAIL_CLASS;
            if (!class_exists($packageShopEmailClass) && defined('DEXPRESS_PLUGIN_DIR')) {
                $candidate = trailingslashit(DEXPRESS_PLUGIN_DIR) . 'src/Presentation/Email/EmailPackageShopReadyForPickup.php';
                if (is_readable($candidate)) {
                    require_once $candidate;
                }
            }
            if (class_exists($packageShopEmailClass)) {
                $emails['DExpress_Email_Package_Shop_Ready'] = new $packageShopEmailClass();
            }

            return $emails;
        });

        add_filter('woocommerce_locate_template', static function ($template, $template_name, $template_path) {
            $name = (string) $template_name;
            if ($name === '' || !str_starts_with($name, 'emails/dexpress-')) {
                return $template;
            }

            $override = trailingslashit(get_stylesheet_directory()) . 'dexpress-woocommerce/' . $name;

            return is_readable($override) ? $override : $template;
        }, 10, 3);

        add_filter('woocommerce_prepare_email_for_preview', [$this, 'prepareEmailForPreview']);
    }

    /**
     * WooCommerce Settings → Emails → Preview: isti šablon i ista polja kao posle slanja.
     */
    public function prepareEmailForPreview(WC_Email $email): WC_Email
    {
        if (!$email instanceof AbstractDExpressEmail) {
            return $email;
        }

        $order = $email->object;
        if (!$order instanceof WC_Order) {
            return $email;
        }

        $ctx = $this->renderContextFactory->forPreview();
        $email->hydrateTemplateContext($order, $ctx);

        return $email;
    }

    public function registerShipmentEventHooks(): void
    {
        add_action('dexpress/shipment.created', [$this, 'onShipmentCreated'], 10, 1);
        add_action('dexpress/shipment.status_updated', [$this, 'onStatusUpdated'], 10, 1);
    }

    public function onShipmentCreated(ShipmentCreated $event): void
    {
        if (!$this->options->getBool('email.auto_status_emails', true)) {
            return;
        }

        $order = wc_get_order($event->orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        if ($order->get_meta(self::META_CREATED) !== '') {
            return;
        }

        if (!$this->passRateLimit((int) $order->get_id(), 'created')) {
            return;
        }

        $recipient = $this->resolveRecipient($order);
        if ($recipient === '') {
            $this->logger->warning('[EMAIL] Created skipped — no billing email. Order ' . $order->get_id());

            return;
        }

        $email = $this->findEmail(EmailShipmentNotification::class);
        if (!$email instanceof EmailShipmentNotification) {
            return;
        }

        $ctx = $this->renderContextFactory->fromShipments([$event->shipment]);
        $email->trigger($order, $ctx, $recipient);

        $order->update_meta_data(self::META_CREATED, current_time('mysql'));
        $order->save();
        $this->touchRateLimit((int) $order->get_id(), 'created');
    }

    public function onStatusUpdated(StatusUpdated $event): void
    {
        if (!$this->options->getBool('email.auto_status_emails', true)) {
            return;
        }

        $order = wc_get_order($event->orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        if ($this->statusMapper->isDelayedSid($event->rawSid)) {
            return;
        }

        $this->maybeSendPackageShopReadyOnStatus($order, $event->rawSid);

        $bucket = $event->bucket;

        if ($bucket === StatusEmailBucket::Delivered) {
            if (!$this->allShipmentsDelivered($event->orderId)) {
                return;
            }

            if ($order->get_meta(self::META_DELIVERED) !== '') {
                return;
            }

            if (!$this->passRateLimit((int) $order->get_id(), 'delivered')) {
                return;
            }

            $recipient = $this->resolveRecipient($order);
            if ($recipient === '') {
                return;
            }

            $email = $this->findEmail(EmailShipmentNotification::class);
            if ($email instanceof EmailShipmentNotification) {
                $ctx = $this->renderContextFactory->fromShipments(
                    $this->shipments->findByOrderId($event->orderId),
                );
                $email->trigger($order, $ctx, $recipient);
                $order->update_meta_data(self::META_DELIVERED, current_time('mysql'));
                $order->save();
                $this->touchRateLimit((int) $order->get_id(), 'delivered');
            }

            return;
        }

        if ($bucket === StatusEmailBucket::ProblemFailed) {
            $this->maybeSendProblem($order);

            return;
        }

        if ($bucket === StatusEmailBucket::InTransit) {
            $this->maybeSendTransit($order);

            return;
        }

        if ($bucket === StatusEmailBucket::OutForDelivery) {
            $this->maybeSendOutForDelivery($order);
        }
    }

    private function maybeSendTransit(WC_Order $order): void
    {
        if ($order->get_meta(self::META_IN_TRANSIT) !== '') {
            return;
        }

        if (!$this->passRateLimit((int) $order->get_id(), 'in_transit')) {
            return;
        }

        $recipient = $this->resolveRecipient($order);
        if ($recipient === '') {
            return;
        }

        $email = $this->findEmail(EmailShipmentNotification::class);
        if ($email instanceof EmailShipmentNotification) {
            $ctx = $this->renderContextFactory->fromShipments(
                $this->shipments->findByOrderId((int) $order->get_id()),
            );
            $email->trigger($order, $ctx, $recipient);
            $order->update_meta_data(self::META_IN_TRANSIT, current_time('mysql'));
            $order->save();
            $this->touchRateLimit((int) $order->get_id(), 'in_transit');
        }
    }

    private function maybeSendOutForDelivery(WC_Order $order): void
    {
        if ($order->get_meta(self::META_OUT_FOR_DELIVERY) !== '') {
            return;
        }

        if (!$this->passRateLimit((int) $order->get_id(), 'out_for_delivery')) {
            return;
        }

        $recipient = $this->resolveRecipient($order);
        if ($recipient === '') {
            return;
        }

        $email = $this->findEmail(EmailShipmentNotification::class);
        if ($email instanceof EmailShipmentNotification) {
            $ctx = $this->renderContextFactory->fromShipments(
                $this->shipments->findByOrderId((int) $order->get_id()),
            );
            $email->trigger($order, $ctx, $recipient);
            $order->update_meta_data(self::META_OUT_FOR_DELIVERY, current_time('mysql'));
            $order->save();
            $this->touchRateLimit((int) $order->get_id(), 'out_for_delivery');
        }
    }

    private function maybeSendProblem(WC_Order $order): void
    {
        if ($order->get_meta(self::META_PROBLEM) !== '') {
            return;
        }

        if (!$this->passRateLimit((int) $order->get_id(), 'problem')) {
            return;
        }

        $recipient = $this->resolveRecipient($order);
        if ($recipient === '') {
            return;
        }

        $email = $this->findEmail(EmailShipmentNotification::class);
        if ($email instanceof EmailShipmentNotification) {
            $ctx = $this->renderContextFactory->fromShipments(
                $this->shipments->findByOrderId((int) $order->get_id()),
            );
            $email->trigger($order, $ctx, $recipient);
            $order->update_meta_data(self::META_PROBLEM, current_time('mysql'));
            $order->save();
            $this->touchRateLimit((int) $order->get_id(), 'problem');
        }
    }

    /**
     * @param array<string, string> $actions
     * @param mixed $orderOrPost
     * @return array<string, string>
     */
    public function registerPackageShopOrderAction(array $actions, mixed $orderOrPost = null): array
    {
        $order = $this->resolveOrderFromContext($orderOrPost);
        if (!$order instanceof WC_Order || !DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order)) {
            return $actions;
        }

        $actions['dexpress_send_package_shop_ready_email'] = __(
            'D Express: Pošalji Paket Shop email (spremno za preuzimanje)',
            'dexpress-woocommerce',
        );

        return $actions;
    }

    public function handleManualPackageShopReadyEmail(WC_Order $order): void
    {
        if (!DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order)) {
            $order->add_order_note(__('D Express: Paket Shop email nije poslat jer porudžbina ne koristi Paket Shop dostavu.', 'dexpress-woocommerce'));
            $order->save();

            return;
        }

        $locationId = trim((string) $order->get_meta('_dexpress_package_shop_location_id'));
        if ($locationId === '') {
            $order->add_order_note(__('D Express: Paket Shop email nije poslat jer lokacija preuzimanja nije sačuvana.', 'dexpress-woocommerce'));
            $order->save();

            return;
        }

        $recipient = $this->resolveRecipient($order);
        if ($recipient === '') {
            $order->add_order_note(__('D Express: Paket Shop email nije poslat jer email kupca nije dostupan.', 'dexpress-woocommerce'));
            $order->save();

            return;
        }

        $email = $this->findEmailById(self::PACKAGE_SHOP_READY_EMAIL_ID);
        if (!$email instanceof WC_Email) {
            $order->add_order_note(__('D Express: Paket Shop email nije poslat jer email klasa nije registrovana.', 'dexpress-woocommerce'));
            $order->save();

            return;
        }

        if (!is_callable([$email, 'trigger'])) {
            $order->add_order_note(__('D Express: Paket Shop email nije poslat jer trigger metoda nije dostupna.', 'dexpress-woocommerce'));
            $order->save();

            return;
        }

        $sent = (bool) call_user_func([$email, 'trigger'], $order, $recipient);
        if ($sent) {
            $order->update_meta_data(self::META_PACKAGE_SHOP_READY_SENT, current_time('mysql'));
            $order->add_order_note(__('D Express: Poslat email kupcu da je Paket Shop pošiljka spremna za preuzimanje.', 'dexpress-woocommerce'));
        } else {
            $order->add_order_note(__('D Express: Paket Shop email nije poslat (email je isključen ili je došlo do greške pri slanju).', 'dexpress-woocommerce'));
        }
        $order->save();
    }

    private function maybeSendPackageShopReadyOnStatus(WC_Order $order, int $rawSid): void
    {
        if (!in_array($rawSid, self::PACKAGE_SHOP_READY_SIDS, true)) {
            return;
        }

        $this->logger->info(
            '[EMAIL TRACE] Package Shop ready status received. order=' . $order->get_id() . ' sid=' . $rawSid
        );

        if (!DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order)) {
            return;
        }

        if ($order->get_meta(self::META_PACKAGE_SHOP_READY_SENT) !== '') {
            return;
        }

        $locationId = trim((string) $order->get_meta('_dexpress_package_shop_location_id'));
        if ($locationId === '') {
            return;
        }

        $recipient = $this->resolveRecipient($order);
        if ($recipient === '') {
            return;
        }

        $email = $this->findEmailById(self::PACKAGE_SHOP_READY_EMAIL_ID);
        if (!$email instanceof WC_Email || !is_callable([$email, 'trigger'])) {
            return;
        }

        $sent = (bool) call_user_func([$email, 'trigger'], $order, $recipient);
        if (!$sent) {
            $this->logger->warning(
                '[EMAIL TRACE] Package Shop ready email trigger returned false. order=' . $order->get_id()
            );
            return;
        }

        $order->update_meta_data(self::META_PACKAGE_SHOP_READY_SENT, current_time('mysql'));
        $order->add_order_note(
            sprintf(
                /* translators: %d: D Express status id */
                __('D Express: Automatski je poslat Paket Shop email „spremno za preuzimanje“ (sID: %d).', 'dexpress-woocommerce'),
                $rawSid,
            ),
        );
        $order->save();
        $this->logger->info(
            '[EMAIL TRACE] Package Shop ready email sent. order=' . $order->get_id() . ' sid=' . $rawSid
        );
    }

    private function allShipmentsDelivered(int $orderId): bool
    {
        $list = $this->shipments->findByOrderId($orderId);
        if ($list === []) {
            return false;
        }

        foreach ($list as $shipment) {
            if ($shipment->emailBucket() !== StatusEmailBucket::Delivered) {
                return false;
            }
        }

        return true;
    }

    private function resolveRecipient(WC_Order $order): string
    {
        $billing = (string) $order->get_billing_email();
        if ($billing === '') {
            return '';
        }

        $isTestEnv = $this->options->getString('api.environment', 'test') === 'test';
        $sendReal  = $this->options->getBool('emails.test_send_real_customer', false);

        if ($isTestEnv && !$sendReal) {
            return (string) get_option('admin_email');
        }

        return $billing;
    }

    private function passRateLimit(int $orderId, string $type): bool
    {
        $key = 'dexpress_email_rate_' . $orderId . '_' . $type;

        return get_transient($key) === false;
    }

    private function touchRateLimit(int $orderId, string $type): void
    {
        $key = 'dexpress_email_rate_' . $orderId . '_' . $type;
        set_transient($key, '1', 2 * HOUR_IN_SECONDS);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    private function findEmail(string $class): ?object
    {
        if (!function_exists('WC')) {
            return null;
        }

        $mailer = WC()->mailer();
        foreach ($mailer->get_emails() as $email) {
            if ($email instanceof $class) {
                return $email;
            }
        }

        return null;
    }

    private function findEmailById(string $id): ?WC_Email
    {
        if (!function_exists('WC')) {
            return null;
        }

        $mailer = WC()->mailer();
        foreach ($mailer->get_emails() as $email) {
            if (!$email instanceof WC_Email) {
                continue;
            }
            if ((string) $email->id === $id) {
                return $email;
            }
        }

        return null;
    }

    private function resolveOrderFromContext(mixed $orderOrPost): ?WC_Order
    {
        if ($orderOrPost instanceof WC_Order) {
            return $orderOrPost;
        }

        if ($orderOrPost instanceof \WP_Post) {
            $order = wc_get_order($orderOrPost->ID);
            return $order instanceof WC_Order ? $order : null;
        }

        if (isset($_GET['id'])) {
            $order = wc_get_order(absint($_GET['id']));
            return $order instanceof WC_Order ? $order : null;
        }

        if (isset($_GET['post'])) {
            $order = wc_get_order(absint($_GET['post']));
            return $order instanceof WC_Order ? $order : null;
        }

        return null;
    }
}

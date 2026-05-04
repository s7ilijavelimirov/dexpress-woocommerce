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
use WC_Email;
use WC_Order;

final class EmailNotificationSubscriber
{
    private const META_CREATED          = '_dexpress_email_sent_created';
    private const META_IN_TRANSIT       = '_dexpress_email_sent_in_transit';
    private const META_OUT_FOR_DELIVERY = '_dexpress_email_sent_out_for_delivery';
    private const META_DELIVERED        = '_dexpress_email_sent_delivered';
    private const META_PROBLEM          = '_dexpress_email_sent_problem';

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
    }

    public function registerEmailClassesFilter(): void
    {
        if (!class_exists(\WooCommerce::class)) {
            return;
        }

        add_filter('woocommerce_email_classes', function (array $emails): array {
            $emails['DExpress_Email_Shipment_Notification'] = new EmailShipmentNotification();

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
}

<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Email;

use S7codedesign\DExpress\Application\Email\ShipmentEmailRenderContext;

final class EmailShipmentNotification extends AbstractDExpressEmail
{
    public function __construct()
    {
        $this->id             = 'dexpress_shipment_notification';
        $this->customer_email = true;
        $this->title          = __('D Express — praćenje pošiljke', 'dexpress-woocommerce');
        $this->description    = __(
            'Jedno obaveštenje za kreiranje i ključna ažuriranja statusa (prema šifarniku D Express u bazi).',
            'dexpress-woocommerce',
        );
        $this->template_html  = 'emails/dexpress-shipment-notification.php';
        $this->template_plain = 'emails/plain/dexpress-shipment-notification.php';
        $this->placeholders   = [
            '{order_date}'   => '',
            '{order_number}' => '',
        ];

        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('[{site_title}] Narudžbina #{order_number} — D Express pošiljka', 'dexpress-woocommerce');
    }

    public function get_default_heading(): string
    {
        return __('D Express — status pošiljke', 'dexpress-woocommerce');
    }

    public function trigger(
        \WC_Order $order,
        ShipmentEmailRenderContext $context,
        string $recipient,
    ): void {
        $this->setup_locale();
        $this->recipient = $recipient;
        $this->hydrateTemplateContext($order, $context);

        if ($this->is_enabled() && $this->get_recipient()) {
            $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments(),
            );
        }

        $this->restore_locale();
    }
}

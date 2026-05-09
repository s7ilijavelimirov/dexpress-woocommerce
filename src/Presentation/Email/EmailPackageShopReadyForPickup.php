<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Email;

final class EmailPackageShopReadyForPickup extends \WC_Email
{
    public function __construct()
    {
        $this->id             = 'dexpress_package_shop_ready_for_pickup';
        $this->customer_email = true;
        $this->title          = __('D Express — Paket Shop spreman za preuzimanje', 'dexpress-woocommerce');
        $this->description    = __('Manualni email kupcu kada je Paket Shop pošiljka spremna za preuzimanje.', 'dexpress-woocommerce');
        $this->template_html  = 'emails/dexpress-package-shop-ready-for-pickup.php';
        $this->template_plain = 'emails/plain/dexpress-package-shop-ready-for-pickup.php';
        $this->placeholders   = [
            '{order_date}'   => '',
            '{order_number}' => '',
        ];

        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('[{site_title}] Narudžbina #{order_number} — pošiljka je spremna za preuzimanje', 'dexpress-woocommerce');
    }

    public function get_default_heading(): string
    {
        return __('Pošiljka je spremna za preuzimanje', 'dexpress-woocommerce');
    }

    public function get_template_base(): string
    {
        return trailingslashit(DEXPRESS_PLUGIN_DIR) . 'templates/';
    }

    public function trigger(\WC_Order $order, string $recipient): bool
    {
        $this->setup_locale();
        $this->object = $order;
        $this->recipient = $recipient;
        $this->placeholders['{order_date}']   = wc_format_datetime($order->get_date_created());
        $this->placeholders['{order_number}'] = $order->get_order_number();

        $sent = false;
        if ($this->is_enabled() && $this->get_recipient()) {
            $sent = $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments(),
            );
        }

        $this->restore_locale();

        return $sent;
    }

    public function get_content_html(): string
    {
        return wc_get_template_html(
            $this->template_html,
            [
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => false,
                'email'         => $this,
            ],
            '',
            $this->get_template_base(),
        );
    }

    public function get_content_plain(): string
    {
        return wc_get_template_html(
            $this->template_plain,
            [
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => true,
                'email'         => $this,
            ],
            '',
            $this->get_template_base(),
        );
    }
}

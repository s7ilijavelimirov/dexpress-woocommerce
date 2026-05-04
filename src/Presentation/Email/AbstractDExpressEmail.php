<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Email;

use S7codedesign\DExpress\Application\Email\ShipmentEmailRenderContext;
use WC_Email;

abstract class AbstractDExpressEmail extends WC_Email
{
    public string $tracking_codes_text = '';

    public bool $is_test_shipment = false;

    public ?ShipmentEmailRenderContext $shipment_context = null;

    public function hydrateTemplateContext(\WC_Order $order, ShipmentEmailRenderContext $context): void
    {
        $this->object               = $order;
        $this->shipment_context     = $context;
        $this->tracking_codes_text  = $context->trackingCodesText();
        $this->is_test_shipment     = $context->isTestShipment;
        $this->placeholders['{order_date}']   = wc_format_datetime($order->get_date_created());
        $this->placeholders['{order_number}'] = $order->get_order_number();
    }

    public function get_template_base(): string
    {
        return trailingslashit(DEXPRESS_PLUGIN_DIR) . 'templates/';
    }

    /**
     * @param array<string, string> $args
     */
    protected function get_content_field(string $field, array $args = []): string
    {
        $content = wc_get_template_html(
            $this->{'template_' . $field},
            array_merge(
                [
                    'order'               => $this->object,
                    'email_heading'       => $this->get_heading(),
                    'tracking_codes_text' => $this->tracking_codes_text,
                    'shipment_context'    => $this->shipment_context,
                    'is_test_shipment'    => $this->is_test_shipment,
                    'sent_to_admin'       => false,
                    'plain_text'          => $field === 'plain',
                    'email'               => $this,
                ],
                $args,
            ),
            '',
            $this->get_template_base(),
        );

        return $content;
    }

    public function get_content_html(): string
    {
        return $this->get_content_field('html');
    }

    public function get_content_plain(): string
    {
        return $this->get_content_field('plain');
    }
}

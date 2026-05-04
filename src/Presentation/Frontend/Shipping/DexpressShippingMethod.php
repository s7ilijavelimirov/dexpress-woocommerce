<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Frontend\Shipping;

final class DexpressShippingMethod extends \WC_Shipping_Method
{
    public const METHOD_ID = 'dexpress';

    public function __construct(int $instance_id = 0)
    {
        $this->id                 = self::METHOD_ID;
        $this->instance_id        = $instance_id;
        $this->method_title       = __('D Express dostava', 'dexpress-woocommerce');
        $this->method_description = __('D Express kurirska dostava za Srbiju.', 'dexpress-woocommerce');
        $this->supports           = [
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        ];

        $this->init();
    }

    public function init(): void
    {
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', __('D Express dostava', 'dexpress-woocommerce'));

        add_action(
            'woocommerce_update_options_shipping_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    public static function orderUsesDexpress(\WC_Order $order): bool
    {
        foreach ($order->get_items('shipping') as $item) {
            if ($item instanceof \WC_Order_Item_Shipping && $item->get_method_id() === self::METHOD_ID) {
                return true;
            }
        }

        return false;
    }

    public function init_form_fields(): void
    {
        $this->instance_form_fields = [
            'title'                   => [
                'title'   => __('Naziv', 'dexpress-woocommerce'),
                'type'    => 'text',
                'default' => __('D Express dostava', 'dexpress-woocommerce'),
            ],
            'cost'                    => [
                'title'       => __('Cena dostave (din)', 'dexpress-woocommerce'),
                'type'        => 'price',
                'default'     => '350',
                'description' => __('Cena dostave u dinarima bez PDV-a.', 'dexpress-woocommerce'),
            ],
            'free_shipping_threshold' => [
                'title'       => __('Besplatna dostava od (din)', 'dexpress-woocommerce'),
                'type'        => 'price',
                'default'     => '0',
                'description' => __('Iznos korpe (bez PDV-a) iznad koga je dostava besplatna. Unesite 0 da biste isključili.', 'dexpress-woocommerce'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $package
     */
    public function calculate_shipping($package = []): void
    {
        $country = $package['destination']['country'] ?? '';
        if ($country !== 'RS') {
            return;
        }

        $threshold  = (float) $this->get_option('free_shipping_threshold', 0);
        $cost       = (float) $this->get_option('cost', 350);
        $cartTotal  = (float) ($package['contents_cost'] ?? 0);

        if ($threshold > 0.0 && $cartTotal >= $threshold) {
            $cost = 0.0;
        }

        $label = $this->title;
        if ($cost === 0.0) {
            $label .= ' (' . __('Besplatna dostava', 'dexpress-woocommerce') . ')';
        }

        $this->add_rate([
            'id'    => $this->get_rate_id(),
            'label' => $label,
            'cost'  => $cost,
        ]);
    }
}

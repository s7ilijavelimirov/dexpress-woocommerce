<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Frontend\Shipping;

final class DexpressPackageShopShippingMethod extends \WC_Shipping_Method
{
    public const METHOD_ID = 'dexpress_package_shop';
    public const DEFAULT_INTRO_TEXT = 'D Paketomati su postavljeni na benziskim stanicama, supermarketima, šoping centrima i rade 24 časa dnevno, jednostavni su i bezbedni za upotrebu. Svoju porudžbinu možete preuzeti i u Paket shopu koji Vam najviše odgovara – radno vreme lokacije je prikazano prilikom odabira!';
    public const DEFAULT_DELIVERY_PRICE_FORMAT = 'Cena isporuke je %s RSD.';
    public const DEFAULT_DELIVERY_TIME_TEXT = '3 RADNA DANA';
    public const DEFAULT_STEPS_TEXT = "1. Odaberite paketomat/paket shop lokaciju i završite porudžbinu\n\n2. Kada je paket isporučen na željenu lokaciju, D Express će vam putem Viber/SMS poruke poslati kod za otvaranje paketomat ormarića\n\n3. Ukoliko plaćate pouzećem potrebno je da kasiru pokažete kod i platite pošiljku gotovinom ili platnom karticom\n\n4. Upišite kod na paketomatu\n\n5. Preuzmite paket iz ormarića koji će se automatski otvoriti\n\n* Rok za preuzimanje pošiljke sa paketomata je 2 radna dana i nakon odabira ovog tipa dostave pošiljku nije moguće preusmeriti na drugu adresu";
    public const DEFAULT_MODAL_INFO_TEXT = "Nakon što na dnu ovog prozora kliknete na dugme PAKETOMATI, paketomat/paket shop možete odabrati klikom na pin/obeleživač na mapi pa potom na dugme IZABERI ili odabirom iz liste klikom na dugme IZABERI.\n\nListu paketomata možete pretražiti po gradu, opštini ili ulici u kojoj se paketomat nalazi. Na računaru ćete je videti na levoj strani, dok ćete joj na mobilnom telefonu pristupiti klikom na ikonicu u gornjem levom uglu.";

    public function __construct(int $instance_id = 0)
    {
        $this->id                 = self::METHOD_ID;
        $this->instance_id        = $instance_id;
        $this->method_title       = __('D Express Paket Shop', 'dexpress-woocommerce');
        $this->method_description = __('Preuzimanje pošiljke u D Express Paket Shop lokaciji u Srbiji.', 'dexpress-woocommerce');
        $this->supports           = [
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        ];

        $this->init();

        if (is_admin()) {
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        }
    }

    public function init(): void
    {
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', __('D Express Paket Shop', 'dexpress-woocommerce'));

        add_action(
            'woocommerce_update_options_shipping_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    public static function orderUsesDexpressPackageShop(\WC_Order $order): bool
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
            'section_package_shop_content' => [
                'title'       => __('Package Shop Content', 'dexpress-woocommerce'),
                'type'        => 'title',
                'description' => __('Podešavanja sadržaja koji se prikazuje kupcu u checkout-u za Paket Shop dostavu.', 'dexpress-woocommerce'),
            ],
            'title'                   => [
                'title'   => __('Naziv', 'dexpress-woocommerce'),
                'type'    => 'text',
                'default' => __('D Express Paket Shop', 'dexpress-woocommerce'),
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
            'intro_text'              => [
                'title'       => __('Uvodni tekst (checkout)', 'dexpress-woocommerce'),
                'type'        => 'textarea',
                'default'     => __(self::DEFAULT_INTRO_TEXT, 'dexpress-woocommerce'),
                'description' => __('Tekst koji se odmah prikazuje kupcu kada izabere Paket Shop dostavu.', 'dexpress-woocommerce'),
                'desc_tip'    => true,
                'css'         => 'width:100%; max-width:100%; min-height:110px; line-height:1.45; padding:10px 12px; box-sizing:border-box;',
                'class'       => 'dexpress-package-shop-setting dexpress-package-shop-setting-intro',
                'custom_attributes' => [
                    'rows' => '5',
                ],
            ],
            'delivery_price_format'   => [
                'title'       => __('Format teksta cene isporuke', 'dexpress-woocommerce'),
                'type'        => 'text',
                'default'     => __(self::DEFAULT_DELIVERY_PRICE_FORMAT, 'dexpress-woocommerce'),
                'description' => __('Koristite %s kao mesto za dinamičku cenu (npr. "Cena isporuke je %s RSD.").', 'dexpress-woocommerce'),
                'desc_tip'    => true,
                'css'         => 'width:100%; max-width:100%;',
            ],
            'delivery_time_text'      => [
                'title'       => __('Rok isporuke (checkout)', 'dexpress-woocommerce'),
                'type'        => 'text',
                'default'     => __(self::DEFAULT_DELIVERY_TIME_TEXT, 'dexpress-woocommerce'),
                'description' => __('Kratak tekst roka isporuke koji se prikazuje pored cene.', 'dexpress-woocommerce'),
                'desc_tip'    => true,
                'css'         => 'width:100%; max-width:100%;',
            ],
            'steps_text'              => [
                'title'       => __('Instrukcije / koraci (checkout)', 'dexpress-woocommerce'),
                'type'        => 'textarea',
                'default'     => __(self::DEFAULT_STEPS_TEXT, 'dexpress-woocommerce'),
                'description' => __('Tekst sa koracima koji se prikazuje ispod dugmeta „ODABERI PAKETOMAT“. Podržani su pasusi, liste i osnovni HTML format.', 'dexpress-woocommerce'),
                'desc_tip'    => true,
                'css'         => 'width:100%; max-width:100%; min-height:280px; line-height:1.55; padding:12px 14px; box-sizing:border-box;',
                'class'       => 'dexpress-package-shop-setting dexpress-package-shop-setting-steps',
                'custom_attributes' => [
                    'rows' => '14',
                ],
            ],
            'modal_info_text'         => [
                'title'       => __('Tekst u modal prozoru (checkout)', 'dexpress-woocommerce'),
                'type'        => 'textarea',
                'default'     => __(self::DEFAULT_MODAL_INFO_TEXT, 'dexpress-woocommerce'),
                'description' => __('Informativni tekst koji se prikazuje u modal prozoru nakon klika na „ODABERI PAKETOMAT“.', 'dexpress-woocommerce'),
                'desc_tip'    => true,
                'css'         => 'width:100%; max-width:100%; min-height:220px; line-height:1.55; padding:12px 14px; box-sizing:border-box;',
                'class'       => 'dexpress-package-shop-setting dexpress-package-shop-setting-modal',
                'custom_attributes' => [
                    'rows' => '10',
                ],
            ],
            'section_package_shop_content_end' => [
                'type' => 'sectionend',
            ],
            'section_package_shop_maps' => [
                'title'       => __('Google Maps Settings', 'dexpress-woocommerce'),
                'type'        => 'title',
                'description' => __('Podešavanja Google mape za prikaz paketomata u checkout modalu.', 'dexpress-woocommerce'),
            ],
            'google_maps_api_key'     => [
                'title'       => __('Google Maps API ključ', 'dexpress-woocommerce'),
                'type'        => 'text',
                'default'     => '',
                'description' => __("API ključ za prikaz Google mape paketomata u checkout modalu. Mapa će se prikazati i bez API ključa, ali će Google prikazati upozorenje. Za produkcijsku upotrebu preporučuje se unos validnog ključa. Kreirajte ključ na: <a href='https://console.cloud.google.com/google/maps-apis/credentials' target='_blank'>Google Cloud Console</a> — potrebno je aktivirati Maps JavaScript API.", 'dexpress-woocommerce'),
                'desc_tip'    => false,
                'css'         => 'width:100%; max-width:100%;',
            ],
            'section_package_shop_maps_end' => [
                'type' => 'sectionend',
            ],
        ];
    }

    public function getIntroText(): string
    {
        $text = (string) $this->get_option('intro_text', __(self::DEFAULT_INTRO_TEXT, 'dexpress-woocommerce'));

        return trim(wp_kses_post($text));
    }

    public function getStepsText(): string
    {
        $text = (string) $this->get_option('steps_text', __(self::DEFAULT_STEPS_TEXT, 'dexpress-woocommerce'));

        return trim(wp_kses_post($text));
    }

    public function getDeliveryPriceFormat(): string
    {
        $text = (string) $this->get_option('delivery_price_format', __(self::DEFAULT_DELIVERY_PRICE_FORMAT, 'dexpress-woocommerce'));

        return trim(wp_kses_post($text));
    }

    public function getDeliveryTimeText(): string
    {
        $text = (string) $this->get_option('delivery_time_text', __(self::DEFAULT_DELIVERY_TIME_TEXT, 'dexpress-woocommerce'));

        return trim(wp_kses_post($text));
    }

    public function getModalInfoText(): string
    {
        $text = (string) $this->get_option('modal_info_text', __(self::DEFAULT_MODAL_INFO_TEXT, 'dexpress-woocommerce'));

        return trim(wp_kses_post($text));
    }

    public function getGoogleMapsApiKey(): string
    {
        return trim((string) $this->get_option('google_maps_api_key', ''));
    }

    /**
     * WooCommerce shipping settings API does not always provide sectionend renderer.
     * Ensure section separators never degrade into visible input fields.
     *
     * @param array<string, mixed> $data
     */
    public function generate_sectionend_html(string $key, array $data): string
    {
        unset($key, $data);

        return '</table>';
    }

    public function enqueueAdminAssets(): void
    {
        if (!isset($_GET['tab'])) {
            return;
        }

        $tab = sanitize_key(wp_unslash((string) $_GET['tab']));
        if ($tab !== 'shipping') {
            return;
        }

        wp_enqueue_style(
            'dexpress-package-shop-shipping-admin',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin-package-shop-shipping.css',
            [],
            DEXPRESS_VERSION,
        );
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

        $threshold = (float) $this->get_option('free_shipping_threshold', 0);
        $cost      = (float) $this->get_option('cost', 350);
        $cartTotal = (float) ($package['contents_cost'] ?? 0);

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

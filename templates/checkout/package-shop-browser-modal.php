<?php
/**
 * @var string $modal_title
 */

if (!defined('ABSPATH')) {
    exit;
}

$loadingFallbackText = get_bloginfo('name');
if (!is_string($loadingFallbackText) || $loadingFallbackText === '') {
    $loadingFallbackText = __('Učitavanje...', 'dexpress-woocommerce');
}
?>
<div
    class="dexpress-package-shop-modal dexpress-package-shop-modal--browser"
    data-dexpress-package-shop-browser-modal="1"
    role="dialog"
    aria-modal="true"
    aria-labelledby="dexpress-package-shop-browser-title"
    aria-hidden="true"
    hidden
>
    <div class="dexpress-package-shop-modal__backdrop" data-dexpress-package-shop-browser-close="1"></div>
    <div class="dexpress-package-shop-modal__dialog dexpress-package-shop-modal__dialog--browser">
        <div class="dexpress-package-shop-modal__header">
            <h3 id="dexpress-package-shop-browser-title" class="dexpress-package-shop-modal__title">
                <?php echo esc_html($modal_title); ?>
            </h3>
            <button
                type="button"
                class="dexpress-package-shop-modal__close"
                data-dexpress-package-shop-browser-close="1"
                aria-label="<?php esc_attr_e('Zatvori', 'dexpress-woocommerce'); ?>"
            >&times;</button>
        </div>
        <div class="dexpress-package-shop-modal__body">
            <div class="dexpress-package-shop-modal__loading" data-dexpress-package-shop-browser-loading="1">
                <div class="dexpress-package-shop-modal__loading-logo-wrap">
                    <img
                        src=""
                        alt="<?php esc_attr_e('Logo', 'dexpress-woocommerce'); ?>"
                        class="dexpress-package-shop-modal__loading-logo"
                        data-dexpress-package-shop-loading-logo="1"
                        hidden
                    />
                    <div
                        class="dexpress-package-shop-modal__loading-fallback"
                        data-dexpress-package-shop-loading-fallback="1"
                    ><?php echo esc_html($loadingFallbackText); ?></div>
                </div>
            </div>

            <div class="dexpress-package-shop-modal__layout" data-dexpress-package-shop-layout="1" hidden>
                <section class="dexpress-package-shop-modal__sidebar">
                    <h4 class="dexpress-package-shop-modal__sidebar-title">
                        <?php esc_html_e('Pretraga paketomata', 'dexpress-woocommerce'); ?>
                    </h4>
                    <p class="dexpress-package-shop-modal__sidebar-caption">
                        <?php esc_html_e('Pretražite po gradu, opštini, nazivu ili adresi lokacije.', 'dexpress-woocommerce'); ?>
                    </p>
                    <input
                        type="text"
                        class="dexpress-package-shop-modal__search"
                        data-dexpress-package-shop-search="1"
                        placeholder="<?php esc_attr_e('Pretražite po gradu ili opštini', 'dexpress-woocommerce'); ?>"
                    />
                    <div class="dexpress-package-shop-modal__list" data-dexpress-package-shop-list="1"></div>
                </section>
                <section class="dexpress-package-shop-modal__map-wrap">
                    <div class="dexpress-package-shop-modal__map" data-dexpress-package-shop-map="1"></div>
                </section>
            </div>
            <input type="hidden" id="dexpress-selected-dispenser-id" name="dexpress_selected_dispenser_id" value="" />
            <input type="hidden" id="dexpress-selected-location-type" name="dexpress_selected_location_type" value="" />
            <button
                type="button"
                class="dexpress-ps-mobile-toggle"
                id="dexpress-ps-mobile-toggle"
                data-dexpress-ps-mobile-toggle="1"
                hidden
            ><?php esc_html_e('☰ Lista paketomata', 'dexpress-woocommerce'); ?></button>
        </div>
        <div class="dexpress-package-shop-modal__footer">
            <button
                type="button"
                class="dexpress-package-shop-modal__cta"
                data-dexpress-package-shop-modal-cta="1"
            ><?php esc_html_e('PAKETOMATI', 'dexpress-woocommerce'); ?></button>
        </div>
        <div
            class="dexpress-package-shop-modal__future-slot"
            data-dexpress-package-shop-modal-future-slot="1"
        ></div>
    </div>
</div>

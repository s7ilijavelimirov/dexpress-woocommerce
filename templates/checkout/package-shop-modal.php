<?php
/**
 * @var string $modal_title
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div
    class="dexpress-package-shop-modal dexpress-package-shop-modal--onboarding"
    data-dexpress-package-shop-onboarding-modal="1"
    role="dialog"
    aria-modal="true"
    aria-labelledby="dexpress-package-shop-onboarding-title"
    aria-hidden="true"
    hidden
>
    <div class="dexpress-package-shop-modal__backdrop" data-dexpress-package-shop-onboarding-close="1"></div>
    <div class="dexpress-package-shop-modal__dialog">
        <div class="dexpress-package-shop-modal__header">
            <h3 id="dexpress-package-shop-onboarding-title" class="dexpress-package-shop-modal__title">
                <?php echo esc_html($modal_title); ?>
            </h3>
            <button
                type="button"
                class="dexpress-package-shop-modal__close"
                data-dexpress-package-shop-onboarding-close="1"
                aria-label="<?php esc_attr_e('Zatvori', 'dexpress-woocommerce'); ?>"
            >&times;</button>
        </div>
        <div class="dexpress-package-shop-modal__body">
            <div class="dexpress-package-shop-modal__onboarding" data-dexpress-package-shop-modal-content="1"></div>
        </div>
        <div class="dexpress-package-shop-modal__footer">
            <button
                type="button"
                class="dexpress-package-shop-modal__cta"
                data-dexpress-package-shop-onboarding-cta="1"
            ><?php esc_html_e('PAKETOMATI', 'dexpress-woocommerce'); ?></button>
        </div>
    </div>
</div>

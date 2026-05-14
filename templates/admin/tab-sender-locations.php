<?php
/**
 * Admin settings tab: Lokacije pošiljaoca
 *
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 * @var array<int, array<string, mixed>> $senderLocations
 */

defined('ABSPATH') || exit;
?>

<div class="dex-notice dex-notice--info dex-tab-intro">
    <div class="dex-notice__content">
        <p class="dex-notice__body"><?php esc_html_e('Lokacija pošiljaoca je adresa sa koje D-Express preuzima pakete. Možete dodati više lokacija — npr. različita skladišta.', 'dexpress-woocommerce'); ?></p>
    </div>
</div>

<div class="dex-locations-header">
    <h2><?php esc_html_e('Lokacije pošiljaoca', 'dexpress-woocommerce'); ?></h2>
    <button type="button" id="dex-add-location" class="dex-btn dex-btn--primary">
        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
        <?php esc_html_e('Dodaj lokaciju', 'dexpress-woocommerce'); ?>
    </button>
</div>

<?php if (empty($senderLocations)) : ?>
    <p id="dex-no-locations">
        <?php esc_html_e('Nema sačuvanih lokacija. Dodajte prvu lokaciju klikom na dugme iznad.', 'dexpress-woocommerce'); ?>
    </p>
<?php else : ?>
    <table class="widefat dex-locations-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Naziv', 'dexpress-woocommerce'); ?></th>
                <th><?php esc_html_e('Adresa', 'dexpress-woocommerce'); ?></th>
                <th><?php esc_html_e('Kontakt', 'dexpress-woocommerce'); ?></th>
                <th><?php esc_html_e('Status', 'dexpress-woocommerce'); ?></th>
                <th><?php esc_html_e('Akcije', 'dexpress-woocommerce'); ?></th>
            </tr>
        </thead>
        <tbody id="dex-locations-list">
            <?php foreach ($senderLocations as $loc) : ?>
                <tr data-id="<?php echo esc_attr($loc['id']); ?>">
                    <td><strong><?php echo esc_html($loc['name']); ?></strong></td>
                    <td>
                        <?php echo esc_html($loc['street_name'] . ' ' . $loc['street_number']); ?>
                        <?php if (!empty($loc['town_name'])) : ?>
                            <br><small><?php echo esc_html($loc['town_name']); ?></small>
                        <?php endif; ?>
                        <?php if (!empty($loc['address_desc'])) : ?>
                            <br><small><?php echo esc_html($loc['address_desc']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html($loc['contact_name']); ?>
                        <?php if (!empty($loc['contact_phone'])) : ?>
                            <br><small><?php echo esc_html($loc['contact_phone']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($loc['is_default']) : ?>
                            <span class="dex-badge dex-badge--default">
                                <?php esc_html_e('Podrazumevana', 'dexpress-woocommerce'); ?>
                            </span>
                        <?php else : ?>
                            <button type="button"
                                    class="dex-btn dex-btn--secondary dex-btn--sm dex-set-default"
                                    data-id="<?php echo esc_attr($loc['id']); ?>">
                                <?php esc_html_e('Postavi kao podrazumevanu', 'dexpress-woocommerce'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button"
                                class="dex-btn dex-btn--secondary dex-btn--sm dex-edit-location"
                                data-id="<?php echo esc_attr($loc['id']); ?>"
                                data-name="<?php echo esc_attr($loc['name']); ?>"
                                data-street-id="<?php echo esc_attr($loc['street_id'] ?? ''); ?>"
                                data-street-name="<?php echo esc_attr($loc['street_name']); ?>"
                                data-street-number="<?php echo esc_attr($loc['street_number']); ?>"
                                data-town-id="<?php echo esc_attr($loc['town_id']); ?>"
                                data-town-name="<?php echo esc_attr($loc['town_name'] ?? ''); ?>"
                                data-address-desc="<?php echo esc_attr($loc['address_desc'] ?? ''); ?>"
                                data-contact-name="<?php echo esc_attr($loc['contact_name'] ?? ''); ?>"
                                data-contact-phone="<?php echo esc_attr($loc['contact_phone'] ?? ''); ?>"
                                data-bank-account="<?php echo esc_attr($loc['bank_account'] ?? ''); ?>">
                            <?php esc_html_e('Izmeni', 'dexpress-woocommerce'); ?>
                        </button>
                        <button type="button"
                                class="dex-btn dex-btn--danger dex-btn--sm dex-delete-location"
                                data-id="<?php echo esc_attr($loc['id']); ?>">
                            <?php esc_html_e('Obriši', 'dexpress-woocommerce'); ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- Add/Edit location modal -->
<div id="dex-location-modal" class="dex-modal" role="dialog" aria-modal="true" aria-labelledby="dex-modal-title">
    <div class="dex-modal__backdrop"></div>
    <div class="dex-modal__dialog">
        <div class="dex-modal__header">
            <h3 id="dex-modal-title" class="dex-modal__title"><?php esc_html_e('Dodaj lokaciju', 'dexpress-woocommerce'); ?></h3>
        </div>
        <div class="dex-modal__body">
            <input type="hidden" id="dex-location-id" value="">

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="dex-loc-name"><?php esc_html_e('Naziv lokacije', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label></th>
                    <td>
                        <input type="text" id="dex-loc-name" class="regular-text"
                               placeholder="<?php esc_attr_e('npr. Magacin Beograd', 'dexpress-woocommerce'); ?>">
                        <p class="description"><?php esc_html_e('Interni naziv za lakšu identifikaciju.', 'dexpress-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-loc-town-name"><?php esc_html_e('Grad', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label></th>
                    <td>
                        <div class="dex-town-autocomplete">
                            <input type="text"
                                   id="dex-loc-town-name"
                                   class="regular-text"
                                   autocomplete="new-password"
                                   placeholder="<?php esc_attr_e('Počnite kucati naziv grada...', 'dexpress-woocommerce'); ?>">
                            <span class="spinner dex-ac-spinner" id="dex-town-spinner"></span>
                            <input type="hidden" id="dex-loc-town-id" value="">
                            <div id="dex-town-suggestions" class="dex-dropdown" role="listbox"></div>
                        </div>
                        <p class="description" id="dex-town-hint">
                            <?php esc_html_e('Unesite najmanje 2 slova za pretragu gradova iz šifarnika.', 'dexpress-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-loc-street-name"><?php esc_html_e('Ulica', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label></th>
                    <td>
                        <div class="dex-street-autocomplete">
                            <input type="text"
                                   id="dex-loc-street-name"
                                   class="regular-text"
                                   autocomplete="new-password"
                                   placeholder="<?php esc_attr_e('Počnite kucati naziv ulice...', 'dexpress-woocommerce'); ?>">
                            <span class="spinner dex-ac-spinner" id="dex-street-spinner"></span>
                            <input type="hidden" id="dex-loc-street-id" value="">
                            <div id="dex-street-suggestions" class="dex-dropdown" role="listbox"></div>
                        </div>
                        <p class="description"><?php esc_html_e('Unesite najmanje 2 slova. Pretraga radi i bez dijakritika.', 'dexpress-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-loc-number"><?php esc_html_e('Kućni broj', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label></th>
                    <td>
                        <input type="text" id="dex-loc-number" class="dex-number-input"
                               placeholder="<?php esc_attr_e('npr. 12a', 'dexpress-woocommerce'); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-loc-addr-desc"><?php esc_html_e('Opis adrese', 'dexpress-woocommerce'); ?></label></th>
                    <td>
                        <input type="text" id="dex-loc-addr-desc" class="regular-text"
                               placeholder="<?php esc_attr_e('npr. 2. sprat, stan 4', 'dexpress-woocommerce'); ?>">
                        <p class="description"><?php esc_html_e('Neobavezno. Dodatne napomene za kurira.', 'dexpress-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-loc-contact-name"><?php esc_html_e('Kontakt osoba', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label></th>
                    <td>
                        <input type="text" id="dex-loc-contact-name" class="regular-text">
                        <p class="description"><?php esc_html_e('Ime osobe za kontakt pri preuzimanju.', 'dexpress-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-loc-contact-phone"><?php esc_html_e('Telefon', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label></th>
                    <td>
                        <input type="tel"
                               id="dex-loc-contact-phone"
                               class="regular-text"
                               placeholder="381641234567">
                        <small id="dex-phone-error" class="dex-field__error"></small>
                        <p class="description"><?php esc_html_e('Format: 381641234567 (možete uneti i +381 ili 06x — biće automatski normalizovano).', 'dexpress-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-loc-bank-account"><?php esc_html_e('Tekući račun (za otkupninu)', 'dexpress-woocommerce'); ?></label></th>
                    <td>
                        <input type="text" id="dex-loc-bank-account" class="regular-text"
                               placeholder="<?php esc_attr_e('npr. 160-123456789-01', 'dexpress-woocommerce'); ?>">
                        <p class="description"><?php esc_html_e('Neobavezno. Popunite samo ako ova lokacija prima otkupninu na poseban račun.', 'dexpress-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <div class="dex-modal__footer">
            <button type="button" id="dex-save-location" class="dex-btn dex-btn--primary">
                <?php esc_html_e('Sačuvaj', 'dexpress-woocommerce'); ?>
            </button>
            <button type="button" id="dex-cancel-location" class="dex-btn dex-btn--secondary">
                <?php esc_html_e('Otkaži', 'dexpress-woocommerce'); ?>
            </button>
            <span id="dex-location-result" class="dex-inline-result" aria-live="polite"></span>
        </div>
    </div>
</div>

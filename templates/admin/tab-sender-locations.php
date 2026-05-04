<?php
/**
 * Admin settings tab: Lokacije pošiljaoca
 *
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 * @var array<int, array<string, mixed>> $senderLocations
 */

defined('ABSPATH') || exit;
?>
<div class="dexpress-locations-header">
    <h2 style="display:inline-block;margin-right:12px;"><?php esc_html_e('Lokacije pošiljaoca', 'dexpress-woocommerce'); ?></h2>
    <button type="button" id="dexpress-add-location" class="button button-primary">
        <?php esc_html_e('+ Dodaj lokaciju', 'dexpress-woocommerce'); ?>
    </button>
</div>

<p class="description" style="margin-bottom:16px;">
    <?php esc_html_e('Lokacije pošiljaoca su adrese sa kojih D Express preuzima pošiljke. Podrazumevana lokacija se koristi pri kreiranju nove pošiljke.', 'dexpress-woocommerce'); ?>
</p>

<?php if (empty($senderLocations)) : ?>
    <p id="dexpress-no-locations">
        <?php esc_html_e('Nema sačuvanih lokacija. Dodajte prvu lokaciju klikom na dugme iznad.', 'dexpress-woocommerce'); ?>
    </p>
<?php else : ?>
    <table class="widefat dexpress-locations-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Naziv', 'dexpress-woocommerce'); ?></th>
                <th><?php esc_html_e('Adresa', 'dexpress-woocommerce'); ?></th>
                <th><?php esc_html_e('Kontakt', 'dexpress-woocommerce'); ?></th>
                <th><?php esc_html_e('Status', 'dexpress-woocommerce'); ?></th>
                <th><?php esc_html_e('Akcije', 'dexpress-woocommerce'); ?></th>
            </tr>
        </thead>
        <tbody id="dexpress-locations-list">
            <?php foreach ($senderLocations as $loc) : ?>
                <tr data-id="<?php echo esc_attr($loc['id']); ?>">
                    <td><strong><?php echo esc_html($loc['name']); ?></strong></td>
                    <td>
                        <?php echo esc_html($loc['street_name'] . ' ' . $loc['street_number']); ?>
                        <?php if (!empty($loc['town_name'])) : ?>
                            <br><small style="color:#666;"><?php echo esc_html($loc['town_name']); ?></small>
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
                            <span class="dexpress-badge dexpress-badge-default">
                                <?php esc_html_e('Podrazumevana', 'dexpress-woocommerce'); ?>
                            </span>
                        <?php else : ?>
                            <button type="button"
                                    class="button button-small dexpress-set-default"
                                    data-id="<?php echo esc_attr($loc['id']); ?>">
                                <?php esc_html_e('Postavi kao podrazumevanu', 'dexpress-woocommerce'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button"
                                class="button button-small dexpress-edit-location"
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
                                class="button button-small dexpress-delete-location"
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
<div id="dexpress-location-modal" class="dexpress-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="dexpress-modal-title">
    <div class="dexpress-modal-content">
        <h3 id="dexpress-modal-title"><?php esc_html_e('Dodaj lokaciju', 'dexpress-woocommerce'); ?></h3>
        <input type="hidden" id="dexpress-location-id" value="">

        <table class="form-table" role="presentation">
            <tr>
                <th><label for="dexpress-loc-name"><?php esc_html_e('Naziv lokacije', 'dexpress-woocommerce'); ?> <span class="dexpress-required">*</span></label></th>
                <td>
                    <input type="text" id="dexpress-loc-name" class="regular-text"
                           placeholder="<?php esc_attr_e('npr. Magacin Beograd', 'dexpress-woocommerce'); ?>">
                    <p class="description"><?php esc_html_e('Interni naziv za lakšu identifikaciju.', 'dexpress-woocommerce'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="dexpress-loc-town-name"><?php esc_html_e('Grad', 'dexpress-woocommerce'); ?> <span class="dexpress-required">*</span></label></th>
                <td>
                    <div class="dexpress-town-autocomplete">
                        <input type="text"
                               id="dexpress-loc-town-name"
                               class="regular-text"
                               autocomplete="new-password"
                               placeholder="<?php esc_attr_e('Počnite kucati naziv grada...', 'dexpress-woocommerce'); ?>">
                        <span class="spinner dexpress-ac-spinner" id="dexpress-town-spinner"></span>
                        <input type="hidden" id="dexpress-loc-town-id" value="">
                        <div id="dexpress-town-suggestions" class="dexpress-suggestions" role="listbox" style="display:none;"></div>
                    </div>
                    <p class="description" id="dexpress-town-hint">
                        <?php esc_html_e('Unesite najmanje 2 slova za pretragu gradova iz šifarnika.', 'dexpress-woocommerce'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="dexpress-loc-street-name"><?php esc_html_e('Ulica', 'dexpress-woocommerce'); ?> <span class="dexpress-required">*</span></label></th>
                <td>
                    <div class="dexpress-street-autocomplete">
                        <input type="text"
                               id="dexpress-loc-street-name"
                               class="regular-text"
                               autocomplete="new-password"
                               placeholder="<?php esc_attr_e('Počnite kucati naziv ulice...', 'dexpress-woocommerce'); ?>">
                        <span class="spinner dexpress-ac-spinner" id="dexpress-street-spinner"></span>
                        <input type="hidden" id="dexpress-loc-street-id" value="">
                        <div id="dexpress-street-suggestions" class="dexpress-suggestions" role="listbox" style="display:none;"></div>
                    </div>
                    <p class="description"><?php esc_html_e('Unesite najmanje 2 slova. Pretraga radi i bez dijakritika.', 'dexpress-woocommerce'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="dexpress-loc-number"><?php esc_html_e('Kućni broj', 'dexpress-woocommerce'); ?> <span class="dexpress-required">*</span></label></th>
                <td>
                    <input type="text" id="dexpress-loc-number" class="dexpress-number-input"
                           placeholder="<?php esc_attr_e('npr. 12a', 'dexpress-woocommerce'); ?>">
                </td>
            </tr>
            <tr>
                <th><label for="dexpress-loc-addr-desc"><?php esc_html_e('Opis adrese', 'dexpress-woocommerce'); ?></label></th>
                <td>
                    <input type="text" id="dexpress-loc-addr-desc" class="regular-text"
                           placeholder="<?php esc_attr_e('npr. 2. sprat, stan 4', 'dexpress-woocommerce'); ?>">
                    <p class="description"><?php esc_html_e('Neobavezno. Dodatne napomene za kurira.', 'dexpress-woocommerce'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="dexpress-loc-contact-name"><?php esc_html_e('Kontakt osoba', 'dexpress-woocommerce'); ?> <span class="dexpress-required">*</span></label></th>
                <td>
                    <input type="text" id="dexpress-loc-contact-name" class="regular-text">
                    <p class="description"><?php esc_html_e('Ime osobe za kontakt pri preuzimanju.', 'dexpress-woocommerce'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="dexpress-loc-contact-phone"><?php esc_html_e('Telefon', 'dexpress-woocommerce'); ?> <span class="dexpress-required">*</span></label></th>
                <td>
                    <input type="tel"
                           id="dexpress-loc-contact-phone"
                           class="regular-text"
                           placeholder="381641234567">
                    <small id="dexpress-phone-error" class="dexpress-field-error"></small>
                    <p class="description"><?php esc_html_e('Format: 381641234567 (možete uneti i +381 ili 06x — biće automatski normalizovano).', 'dexpress-woocommerce'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="dexpress-loc-bank-account"><?php esc_html_e('Tekući račun (za otkupninu)', 'dexpress-woocommerce'); ?></label></th>
                <td>
                    <input type="text" id="dexpress-loc-bank-account" class="regular-text"
                           placeholder="<?php esc_attr_e('npr. 160-123456789-01', 'dexpress-woocommerce'); ?>">
                    <p class="description"><?php esc_html_e('Neobavezno. Popunite samo ako ova lokacija prima otkupninu na poseban račun.', 'dexpress-woocommerce'); ?></p>
                </td>
            </tr>
        </table>

        <div class="dexpress-modal-actions">
            <button type="button" id="dexpress-save-location" class="button button-primary">
                <?php esc_html_e('Sačuvaj', 'dexpress-woocommerce'); ?>
            </button>
            <button type="button" id="dexpress-cancel-location" class="button button-secondary">
                <?php esc_html_e('Otkaži', 'dexpress-woocommerce'); ?>
            </button>
            <span id="dexpress-location-result" class="dexpress-inline-result" aria-live="polite"></span>
        </div>
    </div>
</div>
<div id="dexpress-modal-overlay" class="dexpress-modal-overlay" style="display:none;"></div>

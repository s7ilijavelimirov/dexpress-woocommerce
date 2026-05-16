<?php
/**
 * Admin settings tab: Simulacija statusa
 *
 * Prikazuje se samo kada je mod rada "Probni rad" (dry_run).
 *
 * Available variables:
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 * @var array{quick: list<array<string, mixed>>, real: list<array<string, mixed>>, flow_labels: list<string>}|null $simulation_timeline
 */

defined('ABSPATH') || exit;

$api_mode           = isset($api_mode) && is_string($api_mode) ? $api_mode : 'dry_run';
$isLive             = $api_mode === 'live';
$simulation_enabled = !$isLive && (bool) $options->get('simulation.enabled', false);
$quick_timeline     = (bool) $options->get('simulation.quick_timeline', true);
$timing_wrap_hidden = ($isLive || !$simulation_enabled) ? ' is-hidden' : '';

$sim_tl = is_array($simulation_timeline ?? null) ? $simulation_timeline : ['quick' => [], 'real' => [], 'flow_labels' => []];
$flow   = isset($sim_tl['flow_labels']) && is_array($sim_tl['flow_labels']) ? $sim_tl['flow_labels'] : [];
$stepsQ = isset($sim_tl['quick']) && is_array($sim_tl['quick']) ? $sim_tl['quick'] : [];
$stepsR = isset($sim_tl['real']) && is_array($sim_tl['real']) ? $sim_tl['real'] : [];
?>

<div class="dex-notice dex-notice--info dex-tab-intro">
    <div class="dex-notice__content">
        <p class="dex-notice__body"><?php esc_html_e('Simulacija automatski menja statuse vaših test pošiljki — kao da kurir zaista preuzima i dostavlja paket. Koristite je da proverite da li stižu email obaveštenja i da sve radi ispravno pre nego što pređete na produkciju.', 'dexpress-woocommerce'); ?></p>
    </div>
</div>

<form method="post" action="">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1">
    <input type="hidden" name="dexpress_active_tab" value="simulation">

    <div id="dex-section-simulation" class="dex-card">
        <div class="dex-card__header">
            <h2 class="dex-card__title"><?php esc_html_e('Simulacija', 'dexpress-woocommerce'); ?></h2>
        </div>
        <?php if ($isLive) : ?>
        <div class="dex-card__body">
            <p class="description"><?php esc_html_e('U Produkciji D-Express kurir zaista preuzima pakete — simulacija nije potrebna niti dostupna. Prebacite se na Probni rad ako želite da testirate tok bez stvarnog slanja.', 'dexpress-woocommerce'); ?></p>
            <input type="hidden" name="simulation_enabled" value="0">
        </div>
        <?php else : ?>
        <div class="dex-card__body">
            <?php if (!$isLive && $simulation_enabled) : ?>
                <div class="dex-notice dex-notice--success">
                    <div class="dex-notice__content">
                        <p class="dex-notice__body"><?php esc_html_e('Simulacija je aktivna. Test pošiljke će automatski menjati status.', 'dexpress-woocommerce'); ?></p>
                    </div>
                </div>
            <?php elseif (!$isLive) : ?>
                <div class="dex-notice dex-notice--info">
                    <div class="dex-notice__content">
                        <p class="dex-notice__body"><?php esc_html_e('Simulacija je isključena. Statusi se neće automatski menjati.', 'dexpress-woocommerce'); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <p class="description dex-settings-section__lead">
                <?php esc_html_e('Simulacija omogućava testiranje promena statusa pošiljke i slanja email obaveštenja bez stvarne dostave. Kada je uključena, sistem automatski menja statuse za test pošiljke, kao da dolaze od kurirske službe.', 'dexpress-woocommerce'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="dex-simulation-enabled"><?php esc_html_e('Uključi simulaciju', 'dexpress-woocommerce'); ?></label>
                    </th>
                    <td>
                        <?php if ($isLive) : ?>
                            <p class="description" style="margin:0">
                                <?php esc_html_e('U Produkciji D-Express kurir zaista preuzima pakete — simulacija nije potrebna niti dostupna. Prebacite se na Probni rad ako želite da testirate tok bez stvarnog slanja.', 'dexpress-woocommerce'); ?>
                            </p>
                            <input type="hidden" name="simulation_enabled" value="0">
                        <?php else : ?>
                            <label>
                                <input type="checkbox"
                                       name="simulation_enabled"
                                       id="dex-simulation-enabled"
                                       value="1"
                                       <?php checked($simulation_enabled); ?>>
                                <?php esc_html_e('Automatski menja statuse test pošiljki, kao da kurir zaista preuzima i dostavlja paket.', 'dexpress-woocommerce'); ?>
                            </label>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <div id="dex-sim-timing-wrap" class="dex-sim-timing-wrap<?php echo esc_attr($timing_wrap_hidden); ?>">
                <p class="dex-sim-timing-wrap__label"><?php esc_html_e('Režim vremenskog rasporeda', 'dexpress-woocommerce'); ?></p>

                <input type="checkbox"
                       name="simulation_quick_timeline"
                       id="dex-sim-quick-checkbox"
                       value="1"
                       class="dex-sim-quick-checkbox"
                       <?php checked($quick_timeline); ?>>

                <div class="dex-sim-toggle" role="group" aria-labelledby="dex-sim-toggle-legend">
                    <span id="dex-sim-toggle-legend" class="screen-reader-text"><?php esc_html_e('Izaberite brzu ili realnu simulaciju', 'dexpress-woocommerce'); ?></span>
                    <button type="button"
                            class="dex-sim-toggle__btn"
                            id="dex-sim-mode-brza"
                            data-dexpress-sim-mode="brza"
                            aria-pressed="<?php echo $quick_timeline ? 'true' : 'false'; ?>">
                        <?php esc_html_e('Brza simulacija', 'dexpress-woocommerce'); ?>
                    </button>
                    <button type="button"
                            class="dex-sim-toggle__btn"
                            id="dex-sim-mode-realna"
                            data-dexpress-sim-mode="realna"
                            aria-pressed="<?php echo $quick_timeline ? 'false' : 'true'; ?>">
                        <?php esc_html_e('Realna simulacija', 'dexpress-woocommerce'); ?>
                    </button>
                </div>

                <div id="dex-sim-timing-brza" class="dex-sim-timing-panel" <?php echo $quick_timeline ? '' : 'hidden'; ?>>
                    <p class="description dex-sim-timing-panel__intro">
                        <?php esc_html_e('Statusi se menjaju veoma brzo kako biste odmah videli kompletan tok pošiljke i email obaveštenja. Idealno za testiranje.', 'dexpress-woocommerce'); ?>
                    </p>
                    <ul class="dex-sim-timing-list">
                        <?php foreach ($stepsQ as $row) : ?>
                            <li>
                                <?php
                                echo esc_html(
                                    (string) ($row['label'] ?? '') . ' — ' . (string) ($row['delay_phrase'] ?? '')
                                );
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div id="dex-sim-timing-realna" class="dex-sim-timing-panel" <?php echo $quick_timeline ? 'hidden' : ''; ?>>
                    <p class="description dex-sim-timing-panel__intro">
                        <?php esc_html_e('Statusi se menjaju u realnijim vremenskim intervalima, slično stvarnoj dostavi kurirske službe.', 'dexpress-woocommerce'); ?>
                    </p>
                    <ul class="dex-sim-timing-list">
                        <?php foreach ($stepsR as $row) : ?>
                            <li>
                                <?php
                                echo esc_html(
                                    (string) ($row['label'] ?? '') . ' — ' . (string) ($row['delay_phrase'] ?? '')
                                );
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="dex-sim-flow" aria-label="<?php esc_attr_e('Tok statusa u simulaciji', 'dexpress-woocommerce'); ?>">
                <?php
                $n = count($flow);
                foreach ($flow as $i => $label) :
                    ?>
                <div class="dex-sim-flow__step">
                    <span class="dex-sim-flow__box"><?php echo esc_html((string) $label); ?></span>
                </div>
                    <?php
                    if ($i < $n - 1) :
                        ?>
                <span class="dex-sim-flow__arrow" aria-hidden="true">→</span>
                        <?php
                    endif;
                endforeach;
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce')); ?>
</form>

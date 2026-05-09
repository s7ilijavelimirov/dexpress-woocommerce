<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Hooks;

use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\DatabaseInstaller;

final class PluginActivator
{
    public static function activate(): void
    {
        self::checkRequirements();

        global $wpdb;
        (new DatabaseInstaller($wpdb))->install();

        self::generateWebhookPasscode();
        self::scheduleCronEvents();

        // Prikazuje onboarding wizard novim instalacijama. Samo ako opcija još ne postoji (ne resetuje ponovnom aktivacijom).
        if (get_option('dexpress_onboarding_complete') === false) {
            update_option('dexpress_onboarding_complete', 'no');
            // Transient signalizuje admin_init da preusmeri odmah nakon aktivacije (plugins.php → wizard).
            set_transient('_dexpress_activation_redirect', true, 30);
        }

        // Forsira ponovno flush na prvom init-u nakon aktivacije (WC My Account endpointi se registruju tek kad je plugin boot-ovan).
        delete_option('dexpress_myaccount_endpoint_rewrite_mark');

        flush_rewrite_rules();
    }

    private static function scheduleCronEvents(): void
    {
        $schedules = wp_get_schedules();
        if (!isset($schedules['monthly']) || !isset($schedules['quarterly']) || !isset($schedules['semi_annual'])) {
            add_filter('cron_schedules', static function (array $s): array {
                if (!isset($s['monthly'])) {
                    $s['monthly'] = ['interval' => 30 * DAY_IN_SECONDS, 'display' => 'Jednom mesečno'];
                }
                if (!isset($s['quarterly'])) {
                    $s['quarterly'] = ['interval' => 90 * DAY_IN_SECONDS, 'display' => 'Na tri meseca'];
                }
                if (!isset($s['semi_annual'])) {
                    $s['semi_annual'] = ['interval' => 180 * DAY_IN_SECONDS, 'display' => 'Na šest meseci'];
                }
                return $s;
            });
        }

        if (!wp_next_scheduled('dexpress_cron_monthly')) {
            wp_schedule_event(strtotime('first day of next month 04:00:00'), 'monthly', 'dexpress_cron_monthly');
        }

        if (!wp_next_scheduled('dexpress_cron_daily')) {
            wp_schedule_event(strtotime('tomorrow 02:00:00'), 'daily', 'dexpress_cron_daily');
        }

        if (!wp_next_scheduled('dexpress_cron_quarterly')) {
            wp_schedule_event(strtotime('+90 days 02:00:00'), 'quarterly', 'dexpress_cron_quarterly');
        }

        if (!wp_next_scheduled('dexpress_cron_semi_annual')) {
            wp_schedule_event(strtotime('+180 days 03:00:00'), 'semi_annual', 'dexpress_cron_semi_annual');
        }
    }

    private static function generateWebhookPasscode(): void
    {
        $options = new OptionsRepository();

        if ($options->getString('webhook.passcode') !== '') {
            return;
        }

        $passcode = bin2hex(random_bytes(16));
        $options->set('webhook.passcode', $passcode);
        $options->save();
    }

    private static function checkRequirements(): void
    {
        if (version_compare(PHP_VERSION, DEXPRESS_MIN_PHP, '<')) {
            deactivate_plugins(DEXPRESS_PLUGIN_BASENAME);
            wp_die(
                sprintf(
                    /* translators: 1: required PHP version 2: current PHP version */
                    esc_html__('D Express WooCommerce Integration requires PHP %1$s or higher. Your server is running PHP %2$s.', 'dexpress-woocommerce'),
                    DEXPRESS_MIN_PHP,
                    PHP_VERSION
                ),
                esc_html__('Plugin activation error', 'dexpress-woocommerce'),
                ['back_link' => true]
            );
        }

        if (!defined('WC_VERSION') || version_compare(WC_VERSION, DEXPRESS_MIN_WC, '<')) {
            deactivate_plugins(DEXPRESS_PLUGIN_BASENAME);
            wp_die(
                sprintf(
                    /* translators: %s: required WooCommerce version */
                    esc_html__('D Express WooCommerce Integration requires WooCommerce %s or higher.', 'dexpress-woocommerce'),
                    DEXPRESS_MIN_WC
                ),
                esc_html__('Plugin activation error', 'dexpress-woocommerce'),
                ['back_link' => true]
            );
        }

        if (version_compare(get_bloginfo('version'), DEXPRESS_MIN_WP, '<')) {
            deactivate_plugins(DEXPRESS_PLUGIN_BASENAME);
            wp_die(
                sprintf(
                    /* translators: %s: required WordPress version */
                    esc_html__('D Express WooCommerce Integration requires WordPress %s or higher.', 'dexpress-woocommerce'),
                    DEXPRESS_MIN_WP
                ),
                esc_html__('Plugin activation error', 'dexpress-woocommerce'),
                ['back_link' => true]
            );
        }
    }
}

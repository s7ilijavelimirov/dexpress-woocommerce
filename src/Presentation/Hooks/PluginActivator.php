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

        // Forsira ponovno flush na prvom init-u nakon aktivacije (WC My Account endpointi se registruju tek kad je plugin boot-ovan).
        delete_option('dexpress_myaccount_endpoint_rewrite_mark');

        flush_rewrite_rules();
    }

    private static function scheduleCronEvents(): void
    {
        // Delegate to WpCronScheduler via Plugin container if already booted,
        // or schedule directly to avoid a circular instantiation dependency.
        if (!wp_next_scheduled('dexpress_cron_daily')) {
            wp_schedule_event(strtotime('tomorrow 02:00:00'), 'daily', 'dexpress_cron_daily');
        }

        if (!wp_next_scheduled('dexpress_cron_weekly')) {
            wp_schedule_event(strtotime('next sunday 03:00:00'), 'weekly', 'dexpress_cron_weekly');
        }

        if (!wp_next_scheduled('dexpress_cron_monthly')) {
            // Register monthly interval inline — cron_schedules filter may not have fired yet.
            if (!wp_get_schedules()['monthly'] ?? false) {
                add_filter('cron_schedules', static function (array $s): array {
                    $s['monthly'] = ['interval' => 30 * DAY_IN_SECONDS, 'display' => 'Jednom mesečno'];
                    return $s;
                });
            }

            wp_schedule_event(strtotime('first day of next month 04:00:00'), 'monthly', 'dexpress_cron_monthly');
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

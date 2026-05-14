<?php

declare(strict_types=1);

// Called by WordPress when the plugin is deleted from the Plugins screen.
defined('WP_UNINSTALL_PLUGIN') || exit;

$settings   = get_option('dexpress_settings', []);
$deleteData = !empty($settings['advanced']['delete_data_on_uninstall']);

if (!$deleteData) {
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;

$dexpress_cron_hooks = [
    'dexpress_cron_daily',
    'dexpress_cron_monthly',
    'dexpress_cron_quarterly',
    'dexpress_cron_semi_annual',
];

foreach ($dexpress_cron_hooks as $hook) {
    $timestamp = wp_next_scheduled($hook);
    if ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
    }
    wp_clear_scheduled_hook($hook);
}

wp_clear_scheduled_hook('dexpress/simulate_shipments');
wp_clear_scheduled_hook('dexpress/simulated_webhook');

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('dexpress/simulation/inject');
}

// Drop all plugin tables and dexpress_db_version.
(new \S7codedesign\DExpress\Infrastructure\Persistence\DatabaseInstaller($wpdb))->uninstall();

// Options and transients owned by this plugin.
$option_like_patterns = array_merge(
    [$wpdb->esc_like('dexpress_') . '%'],
    [
        $wpdb->esc_like('_transient_dexpress') . '%',
        $wpdb->esc_like('_transient__dexpress') . '%',
        $wpdb->esc_like('_transient_timeout_dexpress') . '%',
        $wpdb->esc_like('_transient_timeout__dexpress') . '%',
        $wpdb->esc_like('_site_transient_dexpress') . '%',
        $wpdb->esc_like('_site_transient__dexpress') . '%',
        $wpdb->esc_like('_site_transient_timeout_dexpress') . '%',
        $wpdb->esc_like('_site_transient_timeout__dexpress') . '%',
    ],
);

foreach ($option_like_patterns as $like) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
}

// WooCommerce order meta: samo ključevi koje ovaj plugin čuva pod prefiksom _dexpress_
// (ne diramo ostala polja porudžbine — billing/shipping adresa ostaje u WooCommerce-u).
$dexpress_meta_like = $wpdb->esc_like('_dexpress') . '%';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $dexpress_meta_like));

$orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_meta_table)) === $orders_meta_table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query($wpdb->prepare("DELETE FROM `{$orders_meta_table}` WHERE meta_key LIKE %s", $dexpress_meta_like));
}

// Per-user plugin flags (npr. onboarding notice).
$dexpress_user_meta_like = $wpdb->esc_like('_dexpress') . '%';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $dexpress_user_meta_like));

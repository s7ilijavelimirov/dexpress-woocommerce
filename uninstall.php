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

// Drop all plugin tables and dexpress_db_version.
(new \S7codedesign\DExpress\Infrastructure\Persistence\DatabaseInstaller($wpdb))->uninstall();

// Remove all remaining plugin options so that a fresh reinstall starts from a clean state.
delete_option('dexpress_settings');
delete_option('dexpress_onboarding_complete');
delete_option('dexpress_myaccount_endpoint_rewrite_mark');
delete_transient('_dexpress_activation_redirect');

// Remove per-user onboarding notice dismissal so the notice re-appears after reinstall.
$wpdb->delete($wpdb->usermeta, ['meta_key' => '_dexpress_onboarding_notice_dismissed']);

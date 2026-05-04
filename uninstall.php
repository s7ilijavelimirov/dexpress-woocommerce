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

(new \S7codedesign\DExpress\Infrastructure\Persistence\DatabaseInstaller($wpdb))->uninstall();

delete_option('dexpress_settings');

<?php
/**
 * Plugin Name:       D Express WooCommerce Integration
 * Plugin URI:        https://s7codedesign.com/
 * Description:       D Express courier service integration for WooCommerce. Shipment creation, PDF labels, webhook status updates, and customer tracking.
 * Version:           2.8.0
 * Author:            S7codedesign
 * Author URI:        https://s7codedesign.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dexpress-woocommerce
 * Domain Path:       /languages
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * WC requires at least: 8.0
 * WC tested up to:   9.9
 *
 * @package S7codedesign\DExpress
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('DEXPRESS_VERSION', '2.8.0');
define('DEXPRESS_PLUGIN_FILE', __FILE__);
define('DEXPRESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DEXPRESS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DEXPRESS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DEXPRESS_MIN_PHP', '8.1');
define('DEXPRESS_MIN_WP', '6.3');
define('DEXPRESS_MIN_WC', '8.0');

// Requirement check before autoloader (autoloader needs PHP 8.1 features).
if (version_compare(PHP_VERSION, DEXPRESS_MIN_PHP, '<')) {
    add_action('admin_notices', static function (): void {
        $message = sprintf(
            /* translators: 1: required PHP version 2: current PHP version */
            __('D Express WooCommerce Integration requires PHP %1$s or higher. Your server is running PHP %2$s.', 'dexpress-woocommerce'),
            DEXPRESS_MIN_PHP,
            PHP_VERSION
        );
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    });
    return;
}

if (!file_exists(DEXPRESS_PLUGIN_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('D Express WooCommerce Integration: Composer autoloader not found. Run composer install.', 'dexpress-woocommerce') . '</p></div>';
    });
    return;
}

require_once DEXPRESS_PLUGIN_DIR . 'vendor/autoload.php';

register_activation_hook(__FILE__, [\S7codedesign\DExpress\Presentation\Hooks\PluginActivator::class, 'activate']);
register_deactivation_hook(__FILE__, [\S7codedesign\DExpress\Presentation\Hooks\PluginDeactivator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            $message = sprintf(
                /* translators: %s: required WooCommerce version */
                __('D Express WooCommerce Integration requires WooCommerce %s or higher.', 'dexpress-woocommerce'),
                DEXPRESS_MIN_WC
            );
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        });
        return;
    }

    \S7codedesign\DExpress\Plugin::getInstance()->boot();
});

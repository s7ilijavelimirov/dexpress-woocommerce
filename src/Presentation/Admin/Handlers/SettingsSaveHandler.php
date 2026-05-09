<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Handlers;

use S7codedesign\DExpress\Application\Shipment\ShipmentCodeAllocator;
use S7codedesign\DExpress\Infrastructure\Options\EncryptedString;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;

final class SettingsSaveHandler
{
    public function __construct(
        private readonly OptionsRepository $options,
        private readonly ShipmentCodeAllocator $codeAllocator,
    ) {}

    public function register(): void
    {
        add_action('admin_init', [$this, 'handlePost']);
    }

    public function handlePost(): void
    {
        if (
            !isset($_POST['dexpress_save_settings']) ||
            !isset($_POST['dexpress_settings_nonce'])
        ) {
            return;
        }

        if (!check_admin_referer('dexpress_save_settings', 'dexpress_settings_nonce')) {
            $this->redirect('error', 'Nevažeći sigurnosni token. Pokušajte ponovo.');
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            $this->redirect('error', 'Nemate dozvolu za ovu akciju.');
            return;
        }

        $tab = isset($_POST['dexpress_active_tab']) ? sanitize_key($_POST['dexpress_active_tab']) : 'api';

        $error = match ($tab) {
            'api'        => $this->saveApiTab(),
            'webhook'    => $this->saveWebhookTab(),
            'checkout'   => $this->saveCheckoutTab(),
            'email'      => $this->saveEmailTab(),
            'logging'    => $this->saveLoggingTab(),
            'sifarnici'  => $this->saveSifarnici(),
            'simulation' => $this->saveSimulationTab(),
            default      => 'Nepoznat tab.',
        };

        if ($error !== null) {
            $this->redirect('error', $error, $tab);
            return;
        }

        $this->redirect('success', 'Podešavanja su sačuvana.', $tab);
    }

    private function saveApiTab(): ?string
    {
        $username    = sanitize_text_field($_POST['api_username'] ?? '');
        $rawPassword = $_POST['api_password'] ?? '';
        $clientId    = sanitize_text_field($_POST['api_client_id'] ?? '');
        $environment = sanitize_key($_POST['api_environment'] ?? 'test');
        $prefix      = sanitize_text_field($_POST['shipment_prefix'] ?? '');
        $rangeStart  = (int) ($_POST['shipment_range_start'] ?? 1);
        $rangeEnd    = (int) ($_POST['shipment_range_end'] ?? 99);

        $defaultDl   = (int) ($_POST['default_delivery_type'] ?? 2);
        $defaultPay  = (int) ($_POST['default_payment_type'] ?? 2);
        $defaultRet  = (int) ($_POST['default_return_doc'] ?? 0);

        if (!in_array($environment, ['test', 'production'], true)) {
            $environment = 'test';
        }

        if (!in_array($defaultDl, [1, 2], true)) {
            $defaultDl = 2;
        }
        if (!in_array($defaultPay, [1, 2], true)) {
            $defaultPay = 2;
        }
        if (!in_array($defaultRet, [0, 1, 3], true)) {
            $defaultRet = 0;
        }

        if ($rangeEnd < $rangeStart) {
            return 'Opseg kodova: vrednost „Do“ mora biti veća ili jednaka „Od“.';
        }

        $this->options->set('api.username', $username);
        $this->options->set('api.client_id', $clientId);
        $this->options->set('api.environment', $environment);
        $this->options->set('shipment.prefix', strtoupper($prefix));
        $this->options->set('shipment.range_start', $rangeStart);
        $this->options->set('shipment.range_end', $rangeEnd);
        $this->options->set('shipment.default_delivery_type', (string) $defaultDl);
        $this->options->set('shipment.default_payment_type', (string) $defaultPay);
        $this->options->set('shipment.default_return_doc', (string) $defaultRet);

        // Only update the password if the user typed a new one.
        // If the field is left blank, the existing encrypted password is kept.
        if ($rawPassword !== '') {
            $this->options->set('api.password', EncryptedString::encrypt($rawPassword)->toString());
        }

        $this->options->save();
        $this->codeAllocator->evaluateRangeUsageAfterSettingsSaved();

        return null;
    }

    private function saveWebhookTab(): ?string
    {
        $ipAddress = trim(sanitize_text_field((string) ($_POST['webhook_ip_address'] ?? '')));

        if ($ipAddress !== '' && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return 'Dozvoljena IP adresa mora biti validna IPv4 ili IPv6 adresa.';
        }

        $this->options->set('webhook.ip_address', $ipAddress);
        // Cleanup legacy keys from previous allowlist implementation.
        $this->options->set('webhook.ip_allowlist', '');
        $this->options->set('webhook.ip_bypass_test_mode', false);
        $this->options->save();

        return null;
    }

    private function saveCheckoutTab(): ?string
    {
        $this->options->set('validate_address.enabled', isset($_POST['validate_address_enabled']));
        $this->options->save();

        return null;
    }

    private function saveEmailTab(): ?string
    {
        $this->options->set('email.auto_status_emails', isset($_POST['auto_status_emails']));
        $this->options->set('email.myaccount_tracking_enabled', isset($_POST['myaccount_tracking_enabled']));
        $this->options->set('emails.test_send_real_customer', isset($_POST['emails_test_send_real_customer']));
        $this->options->save();
        return null;
    }

    private function saveLoggingTab(): ?string
    {
        $logLevel      = sanitize_key($_POST['log_level'] ?? 'error');
        $retentionDays = max(1, min(90, (int) ($_POST['log_retention_days'] ?? 30)));

        if (!in_array($logLevel, ['debug', 'info', 'warning', 'error', 'none'], true)) {
            $logLevel = 'error';
        }

        $this->options->set('logging.level', $logLevel);
        $this->options->set('logging.retention_days', $retentionDays);
        $this->options->save();
        return null;
    }

    private function saveSifarnici(): ?string
    {
        $this->options->set('advanced.delete_data_on_uninstall', isset($_POST['delete_data_on_uninstall']));
        $this->options->save();
        return null;
    }

    private function saveSimulationTab(): ?string
    {
        $enabled      = isset($_POST['simulation_enabled']);
        $quickTimeline = isset($_POST['simulation_quick_timeline']);

        $this->options->set('simulation.enabled', $enabled);
        $this->options->set('simulation.quick_timeline', $quickTimeline);
        $this->options->save();

        do_action('dexpress_simulation_settings_saved');

        return null;
    }

    private function redirect(string $type, string $message, string $tab = 'api'): void
    {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'             => 'dexpress-settings',
                    'tab'              => $tab,
                    'dexpress_notice'  => $type,
                    'dexpress_message' => rawurlencode($message),
                ],
                admin_url('admin.php'),
            )
        );
        exit;
    }
}

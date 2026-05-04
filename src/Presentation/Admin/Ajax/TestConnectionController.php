<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Ajax;

use S7codedesign\DExpress\Infrastructure\Options\EncryptedString;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;

final class TestConnectionController
{
    public function __construct(
        private readonly OptionsRepository $options,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_dexpress_test_connection', [$this, 'handle']);
    }

    public function handle(): void
    {
        check_ajax_referer('dexpress_test_connection', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nedovoljna prava.'], 403);
        }

        $username    = sanitize_text_field($_POST['username'] ?? '');
        $rawPassword = $_POST['password'] ?? '';

        if ($username === '') {
            // Use stored credentials if no new ones are posted.
            $username = $this->options->getString('api.username');

            $stored = $this->options->getString('api.password');
            if ($stored === '') {
                wp_send_json_error(['message' => 'API kredencijali nisu podešeni.']);
            }

            try {
                $rawPassword = EncryptedString::fromString($stored)->decrypt();
            } catch (\RuntimeException $e) {
                wp_send_json_error(['message' => $e->getMessage()]);
            }
        }

        if ($rawPassword === '') {
            wp_send_json_error(['message' => 'Lozinka je obavezna.']);
        }

        $response = wp_remote_get(
            'https://usersupport.dexpress.rs/ExternalApi/data/statuses',
            [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($username . ':' . $rawPassword),
                    'Accept'        => 'application/json',
                ],
                'timeout' => 15,
            ],
        );

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Greška: ' . $response->get_error_message()]);
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status === 401) {
            wp_send_json_error(['message' => 'Pogrešno korisničko ime ili lozinka (HTTP 401).']);
        }

        if ($status !== 200) {
            wp_send_json_error(['message' => sprintf('API je vratio HTTP %d.', $status)]);
        }

        wp_send_json_success(['message' => 'Konekcija uspešna! Kredencijali su validni.']);
    }
}

<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Ajax;

use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbStreetRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbTownRepository;

final class SenderLocationController
{
    public function __construct(
        private readonly WpdbSenderLocationRepository $repository,
        private readonly WpdbTownRepository           $towns,
        private readonly WpdbStreetRepository         $streets,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_dexpress_save_sender_location',   [$this, 'handleSave']);
        add_action('wp_ajax_dexpress_delete_sender_location', [$this, 'handleDelete']);
        add_action('wp_ajax_dexpress_set_default_location',   [$this, 'handleSetDefault']);
        add_action('wp_ajax_dexpress_admin_search_towns',    [$this, 'handleSearchTowns']);
        add_action('wp_ajax_dexpress_admin_search_streets',  [$this, 'handleSearchStreets']);
    }

    public function handleSave(): void
    {
        check_ajax_referer('dexpress_save_sender_location', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nedovoljna prava.'], 403);
        }

        $id          = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $name        = sanitize_text_field($_POST['name'] ?? '');
        $streetId    = (int) ($_POST['street_id'] ?? 0);
        $street      = sanitize_text_field($_POST['street_name'] ?? '');
        $number      = sanitize_text_field($_POST['street_number'] ?? '');
        $townId      = (int) ($_POST['town_id'] ?? 0);
        $contactName = sanitize_text_field($_POST['contact_name'] ?? '');
        $bankAccount = sanitize_text_field($_POST['bank_account'] ?? '');

        $rawPhone = sanitize_text_field($_POST['contact_phone'] ?? '');

        if ($name === '' || $streetId === 0 || $street === '' || $number === '' || $townId === 0 || $contactName === '' || $rawPhone === '') {
            wp_send_json_error(['message' => 'Sva polja osim opisa adrese i tekućeg računa su obavezna.']);
        }

        $data = [
            'name'          => $name,
            'street_id'     => $streetId,
            'street_name'   => $street,
            'street_number' => $number,
            'town_id'       => $townId,
            'address_desc'  => sanitize_text_field($_POST['address_desc'] ?? ''),
            'contact_name'  => $contactName,
            'contact_phone' => $this->normalizePhone($rawPhone),
            'bank_account'  => $bankAccount,
        ];

        if ($id > 0) {
            $data['id'] = $id;
        }

        $result = $this->repository->save($data);

        if (!$result) {
            wp_send_json_error(['message' => 'Greška pri čuvanju lokacije.']);
        }

        wp_send_json_success(['message' => 'Lokacija je sačuvana.']);
    }

    public function handleDelete(): void
    {
        check_ajax_referer('dexpress_delete_sender_location', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nedovoljna prava.'], 403);
        }

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        if ($id <= 0) {
            wp_send_json_error(['message' => 'Nevažeći ID lokacije.']);
        }

        $result = $this->repository->delete($id);

        if (!$result) {
            wp_send_json_error(['message' => 'Greška pri brisanju lokacije.']);
        }

        wp_send_json_success(['message' => 'Lokacija je obrisana.']);
    }

    public function handleSetDefault(): void
    {
        check_ajax_referer('dexpress_set_default_location', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nedovoljna prava.'], 403);
        }

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        if ($id <= 0) {
            wp_send_json_error(['message' => 'Nevažeći ID lokacije.']);
        }

        $result = $this->repository->setDefault($id);

        if (!$result) {
            wp_send_json_error(['message' => 'Greška pri postavljanju podrazumevane lokacije.']);
        }

        wp_send_json_success(['message' => 'Podrazumevana lokacija je postavljena.']);
    }

    public function handleSearchTowns(): void
    {
        check_ajax_referer('dexpress_admin_search_towns', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nedovoljna prava.'], 403);
        }

        $q = sanitize_text_field($_GET['q'] ?? '');
        wp_send_json_success($this->towns->search($q, 20));
    }

    /**
     * Returns streets matching a query within a specific town.
     * Requires GET params: town_id (int) and q (string, min 2 chars).
     */
    public function handleSearchStreets(): void
    {
        check_ajax_referer('dexpress_admin_search_streets', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nedovoljna prava.'], 403);
        }

        $townId = (int) ($_GET['town_id'] ?? 0);
        $q      = sanitize_text_field($_GET['q'] ?? '');

        if ($townId <= 0) {
            wp_send_json_error(['message' => 'Nevažeći grad.']);
        }

        if (mb_strlen(trim($q)) < 2) {
            wp_send_json_success([]);
            return;
        }

        wp_send_json_success($this->streets->search($townId, $q, 20));
    }

    /**
     * Strips formatting and normalises to canonical 381XXXXXXXXX form.
     * Handles: +381..., 0..., 381..., 00381...
     */
    private function normalizePhone(string $raw): string
    {
        $raw = (string) preg_replace('/[\s\-\(\)\+]/', '', $raw);

        if (str_starts_with($raw, '00381')) {
            $raw = '381' . substr($raw, 5);
        } elseif (str_starts_with($raw, '00')) {
            $raw = substr($raw, 2);
        } elseif (str_starts_with($raw, '0')) {
            $raw = '381' . substr($raw, 1);
        }

        return $raw;
    }
}

<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Ajax;

use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageProfileRepository;

final class PackageProfileController
{
    public function __construct(
        private readonly WpdbPackageProfileRepository $profiles,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_dexpress_save_package_profile',   [$this, 'handleSave']);
        add_action('wp_ajax_dexpress_delete_package_profile', [$this, 'handleDelete']);
        add_action('wp_ajax_dexpress_set_default_profile',    [$this, 'handleSetDefault']);
        add_action('wp_ajax_dexpress_list_package_profiles',  [$this, 'handleList']);
    }

    public function handleSave(): void
    {
        check_ajax_referer('dexpress_save_package_profile', 'nonce', false);

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Nemate dozvolu.', 'dexpress-woocommerce')], 403);
        }

        $name    = sanitize_text_field($_POST['name'] ?? '');
        $desc    = sanitize_textarea_field($_POST['description'] ?? '');
        $content = sanitize_text_field($_POST['default_content'] ?? '');

        if ($name === '') {
            wp_send_json_error(['message' => __('Naziv je obavezan.', 'dexpress-woocommerce')], 422);
        }

        // Ulaz: kg → pretvaramo u grame; cm → mm
        $weightG = (int) round((float) str_replace(',', '.', $_POST['weight_kg'] ?? '0') * 1000);
        $dimXRaw = trim($_POST['dim_x'] ?? '');
        $dimYRaw = trim($_POST['dim_y'] ?? '');
        $dimZRaw = trim($_POST['dim_z'] ?? '');
        $dimX    = $dimXRaw !== '' ? (int) round((float) str_replace(',', '.', $dimXRaw) * 10) : null;
        $dimY    = $dimYRaw !== '' ? (int) round((float) str_replace(',', '.', $dimYRaw) * 10) : null;
        $dimZ    = $dimZRaw !== '' ? (int) round((float) str_replace(',', '.', $dimZRaw) * 10) : null;

        $data = [
            'id'              => absint($_POST['id'] ?? 0),
            'name'            => $name,
            'description'     => $desc,
            'weight_grams'    => $weightG,
            'dim_x'           => $dimX,
            'dim_y'           => $dimY,
            'dim_z'           => $dimZ,
            'default_content' => $content !== '' ? $content : null,
        ];

        if (!$this->profiles->save($data)) {
            wp_send_json_error(['message' => __('Greška pri čuvanju profila.', 'dexpress-woocommerce')], 500);
        }

        wp_send_json_success([
            'message'  => __('Profil paketa sačuvan.', 'dexpress-woocommerce'),
            'profiles' => $this->profiles->findAll(),
        ]);
    }

    public function handleDelete(): void
    {
        check_ajax_referer('dexpress_delete_package_profile', 'nonce', false);

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Nemate dozvolu.', 'dexpress-woocommerce')], 403);
        }

        $id = absint($_POST['id'] ?? 0);
        if ($id === 0) {
            wp_send_json_error(['message' => __('Nevažeći ID.', 'dexpress-woocommerce')], 422);
        }

        if (!$this->profiles->delete($id)) {
            wp_send_json_error(['message' => __('Greška pri brisanju profila.', 'dexpress-woocommerce')], 500);
        }

        wp_send_json_success([
            'message'  => __('Profil obrisan.', 'dexpress-woocommerce'),
            'profiles' => $this->profiles->findAll(),
        ]);
    }

    public function handleSetDefault(): void
    {
        check_ajax_referer('dexpress_set_default_profile', 'nonce', false);

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Nemate dozvolu.', 'dexpress-woocommerce')], 403);
        }

        $id = absint($_POST['id'] ?? 0);
        if ($id === 0) {
            wp_send_json_error(['message' => __('Nevažeći ID.', 'dexpress-woocommerce')], 422);
        }

        if (!$this->profiles->setDefault($id)) {
            wp_send_json_error(['message' => __('Greška.', 'dexpress-woocommerce')], 500);
        }

        wp_send_json_success([
            'message'  => __('Podrazumevani profil postavljen.', 'dexpress-woocommerce'),
            'profiles' => $this->profiles->findAll(),
        ]);
    }

    public function handleList(): void
    {
        check_ajax_referer('dexpress_list_package_profiles', 'nonce', false);

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Nemate dozvolu.', 'dexpress-woocommerce')], 403);
        }

        wp_send_json_success(['profiles' => $this->profiles->findAll()]);
    }
}

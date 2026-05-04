<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Frontend\Ajax;

use S7codedesign\DExpress\Infrastructure\Persistence\AddressSearchRepository;

final class AutocompleteController
{
    public function __construct(
        private readonly AddressSearchRepository $repository,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_dexpress_search_towns', [$this, 'searchTowns']);
        add_action('wp_ajax_nopriv_dexpress_search_towns', [$this, 'searchTowns']);
        add_action('wp_ajax_dexpress_search_streets', [$this, 'searchStreets']);
        add_action('wp_ajax_nopriv_dexpress_search_streets', [$this, 'searchStreets']);
    }

    public function searchTowns(): void
    {
        check_ajax_referer('dexpress_checkout', 'nonce');

        $query = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));

        if (mb_strlen($query) < 2) {
            wp_send_json_success([]);
        }

        wp_send_json_success($this->repository->searchTowns($query, 10));
    }

    public function searchStreets(): void
    {
        check_ajax_referer('dexpress_checkout', 'nonce');

        $query  = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $townId = absint($_GET['town_id'] ?? 0);

        if (mb_strlen($query) < 2 || $townId <= 0) {
            wp_send_json_success([]);
        }

        wp_send_json_success($this->repository->searchStreets($query, $townId, 20));
    }
}

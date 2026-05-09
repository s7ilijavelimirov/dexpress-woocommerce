<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Frontend\Ajax;

use S7codedesign\DExpress\Infrastructure\Persistence\DispenserBrowserRepository;

final class PackageShopDispenserController
{
    public function __construct(
        private readonly DispenserBrowserRepository $repository,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_dexpress_package_shop_dispensers', [$this, 'handle']);
        add_action('wp_ajax_nopriv_dexpress_package_shop_dispensers', [$this, 'handle']);
    }

    public function handle(): void
    {
        check_ajax_referer('dexpress_package_shop', 'nonce');

        $query = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $items = $this->repository->search($query, 2000);

        wp_send_json_success([
            'items' => $items,
            'count' => count($items),
        ]);
    }
}

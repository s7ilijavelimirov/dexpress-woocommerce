<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Hooks;

final class PluginDeactivator
{
    private const CRON_HOOKS = [
        'dexpress_cron_daily',
        'dexpress_cron_monthly',
        'dexpress_cron_quarterly',
        'dexpress_cron_semi_annual',
    ];

    public static function deactivate(): void
    {
        self::unscheduleCronEvents();
        flush_rewrite_rules();
    }

    private static function unscheduleCronEvents(): void
    {
        foreach (self::CRON_HOOKS as $hook) {
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
    }
}

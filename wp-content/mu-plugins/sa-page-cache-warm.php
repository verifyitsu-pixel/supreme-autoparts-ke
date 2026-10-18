<?php
/**
 * SA page cache warmer: keeps the main entry pages cached so visitors rarely
 * hit a cold render.
 *
 * Two triggers:
 *  - recurring WP-Cron event every 5 minutes (the entrypoint's WP-CLI cron loop
 *    runs due events every minute; DISABLE_WP_CRON keeps this off web requests)
 *  - a single event ~10s after every full purge (import coalesces those to
 *    once a minute), so pages are re-primed almost immediately after a wipe
 *
 * Requests go to the public URL with X-Country-Code so the render is filed
 * under the right country bucket (KE + US, the two storefront regions). Pages
 * that are already cached answer from the file cache in ~10ms, so warm cycles
 * are cheap; only genuinely cold pages pay a render.
 */
if (!defined('ABSPATH')) {
    exit;
}

/** @return array<int,array{url:string,cc:string}> */
function sa_page_cache_warm_targets(): array {
    $paths = ['/', '/shop/'];

    // Top-level product categories, discovered live so renames/new ones follow.
    if (taxonomy_exists('product_cat')) {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'parent'     => 0,
            'hide_empty' => false,
            'number'     => 12,
            'orderby'    => 'count',
            'order'      => 'DESC',
            'fields'     => 'slugs',
        ]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $slug) {
                $paths[] = '/product-category/' . $slug . '/';
            }
        }
    }

    $targets = [];
    foreach (['KE', 'US'] as $cc) {
        foreach ($paths as $path) {
            $targets[] = ['url' => home_url($path), 'cc' => $cc];
        }
    }
    return $targets;
}

function sa_page_cache_warm_run(): void {
    // One warm run at a time: a post-purge re-prime may overlap the 5-min cycle.
    if (get_transient('sa_page_cache_warm_lock')) {
        return;
    }
    set_transient('sa_page_cache_warm_lock', '1', 10 * MINUTE_IN_SECONDS);
    foreach (sa_page_cache_warm_targets() as $target) {
        $res = wp_remote_get($target['url'], [
            'timeout'     => 20,
            'redirection' => 5,
            'headers'     => [
                'X-Country-Code' => $target['cc'],
                'User-Agent'     => 'SA-PageCacheWarmer/1.0',
            ],
        ]);
        // Never fatal the cron run on a warming failure; next cycle retries.
        unset($res);
    }
    delete_transient('sa_page_cache_warm_lock');
}

add_filter('cron_schedules', static function (array $schedules): array {
    if (!isset($schedules['sa_every_5_min'])) {
        $schedules['sa_every_5_min'] = [
            'interval' => 300,
            'display'  => 'Every 5 minutes (SA cache warmer)',
        ];
    }
    return $schedules;
});

add_action('init', static function (): void {
    if (!wp_next_scheduled('sa_page_cache_warm')) {
        wp_schedule_event(time() + 60, 'sa_every_5_min', 'sa_page_cache_warm');
    }
});

add_action('sa_page_cache_warm', 'sa_page_cache_warm_run');

// Re-prime shortly after each full purge instead of waiting for the 5-min cycle.
add_action('sa_page_cache_full_purge', static function (): void {
    if (!wp_next_scheduled('sa_page_cache_warm_once')) {
        wp_schedule_single_event(time() + 10, 'sa_page_cache_warm_once');
    }
});
add_action('sa_page_cache_warm_once', 'sa_page_cache_warm_run');

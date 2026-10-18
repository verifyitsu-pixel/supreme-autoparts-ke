<?php
/**
 * SA page cache purge: clears the advanced-cache.php drop-in's file cache on
 * content changes (products, posts, reviews, theme/plugin updates) and from
 * the admin bar. Cache TTL is 15 minutes; this makes edits visible at once.
 */
if (!defined('ABSPATH')) {
    exit;
}

function sa_page_cache_dir(): string {
    return WP_CONTENT_DIR . '/cache/sa-page';
}

function sa_page_cache_purge(bool $force = false): int {
    $dir = sa_page_cache_dir();
    if (!is_dir($dir)) {
        return 0;
    }
    // Coalesce automatic purges: bulk imports/updates save posts continuously and
    // a full wipe per save kept every visitor on a cold cache. Automatic purges
    // run at most once a minute; manual purges (admin bar, WP-CLI) stay instant.
    $marker = $dir . '/.last-purge';
    if (!$force && is_file($marker) && (time() - (int) filemtime($marker)) < 60) {
        return 0;
    }
    $cleared = 0;
    foreach (glob($dir . '/*.html') ?: [] as $file) {
        if (unlink($file)) {
            $cleared++;
        }
    }
    touch($marker);
    return $cleared;
}

foreach (['save_post', 'deleted_post', 'trashed_post', 'comment_post', 'wp_set_comment_status', 'woocommerce_update_product', 'woocommerce_product_set_stock', 'switch_theme', 'upgrader_process_complete', 'customize_save_after'] as $sa_pc_hook) {
    add_action($sa_pc_hook, static function () {
        sa_page_cache_purge(false);
    }, 20);
}

add_action('admin_bar_menu', static function (WP_Admin_Bar $bar): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $bar->add_node([
        'id'    => 'sa-purge-page-cache',
        'title' => 'Purge page cache',
        'href'  => wp_nonce_url(add_query_arg('sa_purge_page_cache', '1'), 'sa_purge_page_cache'),
    ]);
}, 100);

add_action('admin_init', static function (): void {
    if (!current_user_can('manage_options') || !isset($_GET['sa_purge_page_cache'])) {
        return;
    }
    check_admin_referer('sa_purge_page_cache');
    $cleared = sa_page_cache_purge(true);
    wp_safe_redirect(add_query_arg('sa_page_cache_purged', $cleared, remove_query_arg(['sa_purge_page_cache', '_wpnonce'])));
    exit;
});

add_action('admin_notices', static function (): void {
    if (isset($_GET['sa_page_cache_purged'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Page cache purged (' . esc_html((string) absint($_GET['sa_page_cache_purged'])) . ' entries).</p></div>';
    }
});

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('sa purge-page-cache', static function (): void {
        WP_CLI::success('Purged ' . sa_page_cache_purge(true) . ' cached pages.');
    });
}

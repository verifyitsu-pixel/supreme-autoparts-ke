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

function sa_page_cache_purge(): int {
    $dir = sa_page_cache_dir();
    $cleared = 0;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.html') ?: [] as $file) {
            if (unlink($file)) {
                $cleared++;
            }
        }
    }
    return $cleared;
}

foreach (['save_post', 'deleted_post', 'trashed_post', 'comment_post', 'wp_set_comment_status', 'woocommerce_update_product', 'woocommerce_product_set_stock', 'switch_theme', 'upgrader_process_complete', 'customize_save_after'] as $sa_pc_hook) {
    add_action($sa_pc_hook, 'sa_page_cache_purge', 20);
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
    $cleared = sa_page_cache_purge();
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
        WP_CLI::success('Purged ' . sa_page_cache_purge() . ' cached pages.');
    });
}

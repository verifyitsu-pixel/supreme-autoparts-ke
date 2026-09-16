<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Supreme Autoparts menu shell. Dashboard body lives in admin-ultra.php.
 */
add_action('admin_menu', static function (): void {
    add_menu_page(
        'Supreme Autoparts',
        'Supreme Autoparts',
        'manage_woocommerce',
        'supreme-autoparts',
        'sa_core_render_ultra_dashboard',
        'dashicons-car',
        56
    );

    add_submenu_page(
        'supreme-autoparts',
        'Dashboard',
        'Dashboard',
        'manage_woocommerce',
        'supreme-autoparts',
        'sa_core_render_ultra_dashboard'
    );

    add_submenu_page(
        'supreme-autoparts',
        'Import tools',
        'Import tools',
        'manage_woocommerce',
        'supreme-import',
        'sa_core_render_import_page'
    );

    add_submenu_page(
        'supreme-autoparts',
        'Policies',
        'Policies',
        'manage_woocommerce',
        'supreme-policies',
        'sa_core_render_policies_page'
    );
}, 9);

function sa_core_render_policies_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $email = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';
    $slugs = ['terms', 'privacy-policy', 'shipping-policy', 'refund-policy', 'privacy', 'enquire'];
    echo '<div class="wrap sa-ultra">';
    echo '<div class="sa-ultra__header"><div>';
    echo '<h1 class="sa-ultra__title">Store policies</h1>';
    echo '<p class="sa-ultra__sub">Edit seeded policy pages. Store email: ' . esc_html($email) . '</p>';
    echo '</div></div>';
    echo '<div class="sa-panel"><ul class="sa-note-list">';
    $seen = [];
    foreach ($slugs as $slug) {
        $page = get_page_by_path($slug);
        if ($page && empty($seen[$page->ID])) {
            $seen[$page->ID] = true;
            echo '<li><a href="' . esc_url(get_edit_post_link($page->ID)) . '">' . esc_html($page->post_title) . '</a>';
            echo ' — <a href="' . esc_url(get_permalink($page)) . '" target="_blank" rel="noopener">View</a></li>';
        }
    }
    $q = get_posts(['post_type' => 'page', 'numberposts' => 20, 's' => 'policy']);
    foreach ($q as $p) {
        if (!empty($seen[$p->ID])) {
            continue;
        }
        $seen[$p->ID] = true;
        echo '<li><a href="' . esc_url(get_edit_post_link($p->ID)) . '">' . esc_html($p->post_title) . '</a></li>';
    }
    echo '</ul>';
    echo '<p style="margin:12px 0 0;"><a class="button" href="' . esc_url(admin_url('admin.php?page=supreme-import')) . '">Re-seed pages via Import tools</a></p>';
    echo '</div></div>';
}

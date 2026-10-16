<?php
/**
 * Admin sidebar order for wp-admin.
 *
 * Groups the top-level menus by daily workflow (store operations first,
 * then content, then site administration) and puts each section's
 * sub-sections in a logical order. Nothing is removed or hidden: any menu
 * or sub-menu not listed below keeps its original relative order at the
 * end of its section, so items added by other plugins still appear.
 *
 * Loaded from supreme-autoparts-core.php inside a guarded require.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stable-sort helper: slugs named in $order come first in that order;
 * every other slug keeps its original relative place afterwards.
 * A desired entry ending in '*' matches by prefix (for slugs with a
 * changing query string, e.g. customize.php?return=...).
 */
function sa_core_menu_sort(array $slugs, array $order): array
{
    $rank = [];
    foreach (array_values($order) as $i => $want) {
        $rank[$want] = $i;
    }
    $rankOf = static function (string $slug) use ($rank): int {
        // Some plugins store sub-menu slugs URL-escaped (&amp;); compare
        // against the plain form so both spellings match.
        $norm = str_replace('&amp;', '&', $slug);
        if (isset($rank[$norm])) {
            return $rank[$norm];
        }
        foreach ($rank as $want => $i) {
            if (substr($want, -1) === '*' && strpos($norm, substr($want, 0, -1)) === 0) {
                return $i;
            }
        }
        return PHP_INT_MAX;
    };
    $decorated = [];
    foreach (array_values($slugs) as $i => $slug) {
        $decorated[] = [$rankOf((string) $slug), $i, $slug];
    }
    usort($decorated, static function (array $a, array $b): int {
        if ($a[0] === $b[0]) {
            return $a[1] <=> $b[1];
        }
        return $a[0] <=> $b[0];
    });
    return array_map(static function (array $d) {
        return $d[2];
    }, $decorated);
}

/*
 * Top-level menu order:
 *   1. Dashboard
 *   2. Store operations: Supreme Autoparts hub, WooCommerce, Products,
 *      Returns, Payments, TransactWorld, Analytics, Marketing, chatbot
 *   3. Content: Posts, Media, Pages, Comments
 *   4. Site administration: Appearance, Plugins, Users, Tools, Settings
 * Existing separators are reused between the groups; anything unlisted
 * (new plugin menus, separator-last) appends in its original order.
 */
add_filter('custom_menu_order', '__return_true');

add_filter('menu_order', static function ($menu_order) {
    if (!is_array($menu_order)) {
        return $menu_order;
    }
    $seps = array_values(array_filter($menu_order, static function ($s) {
        return strpos((string) $s, 'separator') === 0;
    }));
    $groups = [
        ['index.php'],
        [
            'supreme-autoparts',
            'woocommerce',
            'edit.php?post_type=product',
            'edit.php?post_type=sa_return',
            'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM',
            'twld-transactworld-reconciliation',
            'wc-admin&path=/analytics/overview',
            'woocommerce-marketing',
            'jotform_ai_chatbot',
        ],
        ['edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php'],
        ['themes.php', 'plugins.php', 'users.php', 'tools.php', 'options-general.php'],
    ];
    $new = [];
    $used = [];
    foreach ($groups as $gi => $slugs) {
        if ($gi > 0 && isset($seps[$gi - 1])) {
            $new[] = $seps[$gi - 1];
            $used[$seps[$gi - 1]] = true;
        }
        foreach ($slugs as $slug) {
            if (in_array($slug, $menu_order, true) && !isset($used[$slug])) {
                $new[] = $slug;
                $used[$slug] = true;
            }
        }
    }
    foreach ($menu_order as $slug) {
        if (!isset($used[$slug])) {
            $new[] = $slug;
            $used[$slug] = true;
        }
    }
    return $new;
}, 999);

/*
 * Sub-section order inside each section. Keys are parent menu slugs,
 * values are sub-menu slugs in the wanted order.
 */
add_action('admin_menu', static function (): void {
    global $submenu;
    if (!is_array($submenu)) {
        return;
    }
    $orders = [
        // Supreme Autoparts hub: sell and fulfil first, then catalog,
        // people, money and logistics, growth, and system pages last.
        'supreme-autoparts' => [
            'supreme-autoparts',      // Dashboard
            'sa-super-orders',
            'sa-super-bookings',
            'sa-super-roadside',
            'sa-super-workshop',
            'sa-super-products',
            'sa-super-inventory',
            'supreme-import',
            'sa-super-customers',
            'supreme-leads',
            'sa-super-vendors',
            'sa-super-tickets',
            'sa-super-reviews',
            'sa-super-payments',
            'sa-super-shipping',
            'sa-super-discounts',
            'sa-super-marketing',
            'sa-super-analytics',
            'sa-super-content',
            'sa-super-integrations',
            'sa-super-notifications',
            'supreme-policies',
            'sa-super-admins',
            'sa-super-security',
            'sa-super-fraud',
            'sa-super-settings',
        ],
        // WooCommerce: day-to-day first, configuration last.
        'woocommerce' => [
            'wc-admin',
            'wc-orders',
            'wc-admin&path=/customers',
            'coupons-moved',
            'wc-reports',
            'sa-brevo-settings',
            'wc-admin&path=/extensions',
            'wc-settings',
            'wc-status',
        ],
        // Products: list and add first, then taxonomy structure, then feedback.
        'edit.php?post_type=product' => [
            'edit.php?post_type=product',
            'post-new.php?post_type=product',
            'edit-tags.php?taxonomy=product_cat&post_type=product',
            'edit-tags.php?taxonomy=product_brand&post_type=product',
            'edit-tags.php?taxonomy=product_tag&post_type=product',
            'product_attributes',
            'product-reviews',
        ],
        // Analytics: money and orders first, configuration last.
        'wc-admin&path=/analytics/overview' => [
            'wc-admin&path=/analytics/overview',
            'wc-admin&path=/analytics/revenue',
            'wc-admin&path=/analytics/orders',
            'wc-admin&path=/analytics/products',
            'wc-admin&path=/analytics/categories',
            'wc-admin&path=/analytics/variations',
            'wc-admin&path=/analytics/stock',
            'wc-admin&path=/analytics/coupons',
            'wc-admin&path=/analytics/taxes',
            'wc-admin&path=/analytics/downloads',
            'wc-admin&path=/analytics/settings',
        ],
        'woocommerce-marketing' => [
            'wc-admin&path=/marketing',
            'edit.php?post_type=shop_coupon',
        ],
        // Appearance: theme first, then visual editors and menus.
        'themes.php' => [
            'themes.php',
            'customize.php*',
            'nav-menus.php',
            'site-editor.php*',
            'font-library.php',
        ],
        // Tools: ordinary tools, then importers, then health and privacy.
        'tools.php' => [
            'tools.php',
            'import.php',
            'export.php',
            'supreme-import',
            'sa-uk-import',
            'action-scheduler',
            'site-health.php',
            'export-personal-data.php',
            'erase-personal-data.php',
        ],
        // Settings: core first, privacy and connectors last.
        'options-general.php' => [
            'options-general.php',
            'options-writing.php',
            'options-reading.php',
            'options-discussion.php',
            'options-media.php',
            'options-permalink.php',
            'options-privacy.php',
            'options-connectors.php',
        ],
    ];
    foreach ($orders as $parent => $order) {
        if (empty($submenu[$parent]) || !is_array($submenu[$parent])) {
            continue;
        }
        $slugs = array_map(static function ($item) {
            return (string) ($item[2] ?? '');
        }, $submenu[$parent]);
        $sorted = sa_core_menu_sort($slugs, $order);
        $bySlug = [];
        foreach ($submenu[$parent] as $item) {
            $bySlug[(string) ($item[2] ?? '')][] = $item;
        }
        $rebuilt = [];
        foreach ($sorted as $slug) {
            if (!empty($bySlug[$slug])) {
                $rebuilt[] = array_shift($bySlug[$slug]);
            }
        }
        $submenu[$parent] = $rebuilt;
    }
}, 999);

// TEMP DEBUG: expose the real marketing sub-menu slugs in an HTML comment.
add_action('admin_footer', static function (): void {
    global $submenu;
    $dump = [];
    foreach ((array) ($submenu['woocommerce-marketing'] ?? []) as $item) {
        $dump[] = (string) ($item[2] ?? '');
    }
    echo '<!-- SA-DEBUG-MARKETING ' . esc_html(implode(' | ', $dump)) . ' -->';
});

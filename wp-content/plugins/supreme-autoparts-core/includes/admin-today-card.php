<?php
/**
 * "Today" card on the WordPress Dashboard (wp-admin index.php).
 *
 * One compact widget with the three numbers a store operator needs each
 * morning - new orders today, pending returns, low-stock products - each
 * with links into the matching admin screen. Read-only: it only queries
 * and links, it never changes data.
 *
 * Loaded from supreme-autoparts-core.php inside a guarded require.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_dashboard_setup', static function (): void {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    wp_add_dashboard_widget(
        'sa-today-card',
        'Today - Supreme Autoparts',
        'sa_core_render_today_card'
    );
    // Pin it to the top of the dashboard (it is the store's morning brief,
    // it should not sit under the default WordPress widgets).
    global $wp_meta_boxes;
    $core = $wp_meta_boxes['dashboard']['normal']['core'] ?? [];
    if (isset($core['sa-today-card'])) {
        $wp_meta_boxes['dashboard']['normal']['core'] = ['sa-today-card' => $core['sa-today-card']] + $core;
    }
});

function sa_core_render_today_card(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $tz = wp_timezone();
    $start = (new DateTimeImmutable('today', $tz))->setTime(0, 0, 0);
    $start_gmt = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    /* ---- New orders today ---- */
    $orders = [];
    if (function_exists('wc_get_orders')) {
        $found = wc_get_orders([
            'limit'        => 50,
            'status'       => ['pending', 'on-hold', 'processing', 'completed'],
            'date_created' => '>=' . $start_gmt,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'return'       => 'objects',
        ]);
        if (is_array($found)) {
            $orders = $found;
        }
    }
    $orders_today = count($orders);
    $orders_url = admin_url('admin.php?page=wc-orders');

    /* ---- Pending returns (any non-final status) ---- */
    $returns_total = 0;
    $return_ids = [];
    $rq = new WP_Query([
        'post_type'              => 'sa_return',
        'post_status'            => 'any',
        'posts_per_page'         => 5,
        'fields'                 => 'ids',
        'no_found_rows'          => false,
        'orderby'                => 'date',
        'order'                  => 'DESC',
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query'             => [
            [
                'key'     => '_sa_r_status',
                'value'   => ['rejected', 'refunded', 'cancelled'],
                'compare' => 'NOT IN',
            ],
        ],
    ]);
    $returns_total = (int) $rq->found_posts;
    $return_ids = array_map('intval', (array) $rq->posts);
    wp_reset_postdata();
    $returns_url = admin_url('edit.php?post_type=sa_return');

    /* ---- Low-stock products ---- */
    $low_total = 0;
    $low_ids = [];
    $low_threshold = (int) get_option('woocommerce_notify_low_stock_amount', 2);
    $lq = new WP_Query([
        'post_type'              => 'product',
        'post_status'            => 'publish',
        'posts_per_page'         => 5,
        'fields'                 => 'ids',
        'no_found_rows'          => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_key'               => '_stock',
        'orderby'                => 'meta_value_num',
        'order'                  => 'ASC',
        'meta_query'             => [
            'relation' => 'AND',
            [
                'key'     => '_manage_stock',
                'value'   => 'yes',
                'compare' => '=',
            ],
            [
                'key'     => '_stock',
                'value'   => $low_threshold,
                'compare' => '<=',
                'type'    => 'NUMERIC',
            ],
        ],
    ]);
    $low_total = (int) $lq->found_posts;
    $low_ids = array_map('intval', (array) $lq->posts);
    wp_reset_postdata();
    $low_url = admin_url('edit.php?post_type=product&orderby=stock&order=asc');

    /* ---- Render ---- */
    echo '<style>
        .sa-today-stats{display:flex;gap:10px;flex-wrap:wrap;margin:2px 0 10px}
        .sa-today-stat{flex:1;min-width:120px;border:1px solid #dcdcde;border-left:4px solid #d97706;border-radius:6px;padding:8px 10px;text-decoration:none;color:inherit;background:#fff}
        .sa-today-stat:hover{border-left-color:#b45309;background:#fffbeb}
        .sa-today-num{font-size:22px;font-weight:700;line-height:1.1;color:#111827}
        .sa-today-num.is-warn{color:#b45309}
        .sa-today-label{font-size:12px;color:#4b5563;margin-top:2px}
        .sa-today-list{margin:0 0 8px;padding:0;list-style:none;font-size:12px}
        .sa-today-list li{margin:0 0 4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .sa-today-h{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin:10px 0 4px}
        .sa-today-empty{color:#6b7280;font-size:12px;margin:0 0 8px}
    </style>';

    echo '<div class="sa-today-stats">';
    printf(
        '<a class="sa-today-stat" href="%s"><span class="sa-today-num">%d</span><span class="sa-today-label">%s</span></a>',
        esc_url($orders_url),
        (int) $orders_today,
        esc_html($orders_today === 1 ? 'new order today' : 'new orders today')
    );
    printf(
        '<a class="sa-today-stat" href="%s"><span class="sa-today-num%s">%d</span><span class="sa-today-label">%s</span></a>',
        esc_url($returns_url),
        $returns_total > 0 ? ' is-warn' : '',
        (int) $returns_total,
        esc_html($returns_total === 1 ? 'pending return' : 'pending returns')
    );
    printf(
        '<a class="sa-today-stat" href="%s"><span class="sa-today-num%s">%d</span><span class="sa-today-label">%s</span></a>',
        esc_url($low_url),
        $low_total > 0 ? ' is-warn' : '',
        (int) $low_total,
        esc_html($low_total === 1 ? 'low-stock product' : 'low-stock products')
    );
    echo '</div>';

    echo '<p class="sa-today-h">Today\'s orders</p>';
    if (!$orders) {
        echo '<p class="sa-today-empty">No orders yet today.</p>';
    } else {
        echo '<ul class="sa-today-list">';
        foreach (array_slice($orders, 0, 5) as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            $name = trim((string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name());
            if ($name === '') {
                $name = (string) $order->get_billing_email();
            }
            printf(
                '<li><a href="%s">#%s</a> - %s - %s (%s)</li>',
                esc_url($order->get_edit_order_url()),
                esc_html($order->get_order_number()),
                esc_html($name),
                esc_html(wp_strip_all_tags($order->get_formatted_order_total())),
                esc_html(wc_get_order_status_name($order->get_status()))
            );
        }
        echo '</ul>';
    }

    echo '<p class="sa-today-h">Pending returns</p>';
    if (!$return_ids) {
        echo '<p class="sa-today-empty">No open returns.</p>';
    } else {
        $labels = function_exists('sa_ret_statuses') ? sa_ret_statuses() : [];
        echo '<ul class="sa-today-list">';
        foreach ($return_ids as $rid) {
            $st = function_exists('sa_ret_status') ? sa_ret_status($rid) : '';
            $label = $labels[$st] ?? $st;
            printf(
                '<li><a href="%s">RT%d</a> - order #%s - %s</li>',
                esc_url(admin_url('post.php?post=' . $rid . '&action=edit')),
                (int) $rid,
                esc_html((string) get_post_meta($rid, '_sa_r_order', true)),
                esc_html((string) $label)
            );
        }
        echo '</ul>';
    }

    echo '<p class="sa-today-h">Low stock</p>';
    if (!$low_ids) {
        echo '<p class="sa-today-empty">Nothing low on stock.</p>';
    } else {
        echo '<ul class="sa-today-list">';
        foreach ($low_ids as $pid) {
            $stock = get_post_meta($pid, '_stock', true);
            printf(
                '<li><a href="%s">%s</a> - %s left</li>',
                esc_url(admin_url('post.php?post=' . $pid . '&action=edit')),
                esc_html((string) get_the_title($pid)),
                esc_html(is_numeric($stock) ? (string) (int) $stock : '0')
            );
        }
        echo '</ul>';
    }

    printf(
        '<p style="margin:6px 0 0;"><a href="%s">Open the full store dashboard &rarr;</a></p>',
        esc_url(admin_url('admin.php?page=supreme-autoparts'))
    );
}

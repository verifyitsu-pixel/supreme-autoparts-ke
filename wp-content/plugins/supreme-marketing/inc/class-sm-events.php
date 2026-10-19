<?php
/**
 * Store event triggers: carts, checkouts, orders, product views, restocks, new products.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Events
{
    public static function init(): void
    {
        add_action('user_register', [self::class, 'on_register']);
        add_action('woocommerce_checkout_create_order', [self::class, 'on_checkout_create_order'], 10, 2);
        add_action('woocommerce_order_status_processing', [self::class, 'on_order_paid']);
        add_action('woocommerce_order_status_completed', [self::class, 'on_order_paid']);
        add_action('woocommerce_order_status_on-hold', [self::class, 'on_order_paid']);
        add_action('woocommerce_cart_updated', [self::class, 'on_cart_updated']);
        add_action('template_redirect', [self::class, 'on_product_view']);
        add_action('wp_ajax_sm_capture_email', [self::class, 'ajax_capture_email']);
        add_action('wp_ajax_nopriv_sm_capture_email', [self::class, 'ajax_capture_email']);
        add_action('woocommerce_product_set_stock_status', [self::class, 'on_stock_status'], 10, 3);
        add_action('transition_post_status', [self::class, 'on_product_publish'], 10, 3);
        add_action('wp_footer', [self::class, 'checkout_capture_js']);
    }

    /** Current visitor's email when known: account > session capture > cookie. */
    public static function current_email(): string
    {
        if (is_user_logged_in()) {
            $u = wp_get_current_user();
            if (is_email($u->user_email)) {
                return strtolower($u->user_email);
            }
        }
        if (function_exists('WC') && WC()->session) {
            $e = (string) WC()->session->get('sm_email', '');
            if (is_email($e)) {
                return strtolower($e);
            }
        }
        $c = (string) ($_COOKIE['sm_email'] ?? '');
        return is_email($c) ? strtolower($c) : '';
    }

    public static function remember_email(string $email): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('sm_email', $email);
        }
        if (!headers_sent()) {
            setcookie('sm_email', $email, time() + 180 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
    }

    public static function on_register(int $user_id): void
    {
        $u = get_userdata($user_id);
        if (!$u || !is_email($u->user_email)) {
            return;
        }
        SM_Subscribers::ensure($u->user_email, [
            'user_id'    => $user_id,
            'first_name' => (string) get_user_meta($user_id, 'first_name', true),
            'last_name'  => (string) get_user_meta($user_id, 'last_name', true),
            'status'     => 'transactional_only',
            'source'     => 'account',
        ]);
        self::remember_email(strtolower($u->user_email));
    }

    /** Order created at checkout: record consent choice, cancel abandonment timers. */
    public static function on_checkout_create_order($order, $data): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        $email = strtolower((string) $order->get_billing_email());
        if (!is_email($email)) {
            return;
        }
        self::remember_email($email);
        SM_Subscribers::ensure($email, [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'region'     => $order->get_billing_country(),
            'source'     => 'checkout',
        ]);
        $opted_in = !empty($_POST['sm_marketing_optin']);
        SM_Consent::apply_checkout_choice($email, $opted_in, [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'region'     => $order->get_billing_country(),
        ]);
        SM_Queue::cancel_abandonment_for_email($email);
    }

    /** Order paid/fulfilled: stats, post-purchase series, reorder reminders. */
    public static function on_order_paid(int $order_id): void
    {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $email = strtolower((string) $order->get_billing_email());
        if (!is_email($email)) {
            return;
        }
        SM_Queue::cancel_abandonment_for_email($email);
        $sub = SM_Subscribers::ensure($email, [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'region'     => $order->get_billing_country(),
            'source'     => 'order',
        ]);
        SM_Subscribers::refresh_order_stats($email);
        if (!$sub) {
            return;
        }

        $order_ctx = [
            'order' => [
                'number' => $order->get_order_number(),
                'url'    => $order->get_view_order_url(),
            ],
            'no_order_since' => sm_now(),
        ];
        SM_Flows::start($sub, 'post_purchase', 'order|' . $order_id, $order_ctx);

        // Reorder reminder per product (deduped per order+product), only for opted-in customers.
        $days_default = max(7, (int) sm_setting('reorder_days'));
        foreach ($order->get_items() as $item) {
            $pid = (int) $item->get_product_id();
            if (!$pid) {
                continue;
            }
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }
            $days = (int) get_post_meta($pid, '_sm_reorder_days', true);
            if ($days < 7) {
                $days = $days_default;
            }
            SM_Queue::add([
                'subscriber_id' => (int) $sub['id'],
                'email'         => $email,
                'flow'          => 'reorder_reminder',
                'step'          => 'reorder_1',
                'template'      => 'reorder_1',
                'payload'       => [
                    'first_name'     => $sub['first_name'] ?? '',
                    'product_id'     => $pid,
                    'product_name'   => $product->get_name(),
                    'reorder_url'    => get_permalink($pid),
                    'products'       => [sm_product_payload($product, sm_subscriber_zone($sub))],
                    'no_order_since' => sm_now(),
                ],
                'send_at'       => sm_dt(time() + max(1, $days - 5) * DAY_IN_SECONDS),
                'dedupe_key'    => 'reorder|' . $email . '|' . $pid . '|' . $order_id,
            ]);
        }
    }

    /** Cart changed: (re)start the abandoned-cart timer when we know the email. */
    public static function on_cart_updated(): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        if (WC()->cart->is_empty()) {
            return;
        }
        $email = self::current_email();
        if ($email === '') {
            return;
        }
        $sub = SM_Subscribers::ensure($email, ['source' => 'cart']);
        if (!$sub || !SM_Subscribers::is_contactable($sub)) {
            return;
        }
        $ctx = [
            'cart'           => self::cart_snapshot(),
            'cart_url'       => wc_get_cart_url(),
            'no_order_since' => sm_now(),
        ];
        // Refresh an already-pending step 1 instead of stacking duplicates.
        global $wpdb;
        $bumped = $wpdb->query($wpdb->prepare(
            "UPDATE " . SM_DB::table('queue') . "
             SET send_at = %s, payload = %s
             WHERE email = %s AND flow = 'abandoned_cart' AND step = 'cart_1' AND status = 'pending'",
            sm_dt(time() + SM_Flows::step_delay('abandoned_cart', 'cart_1', HOUR_IN_SECONDS)),
            wp_json_encode(array_merge(['email' => $email, 'first_name' => $sub['first_name'] ?? '', '_refresh_products' => false], $ctx)),
            $email
        ));
        if (!$bumped) {
            SM_Flows::start($sub, 'abandoned_cart', 'day|' . gmdate('Y-m-d'), $ctx);
        }
    }

    private static function cart_snapshot(): array
    {
        $items = [];
        $total = '';
        if (function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $ci) {
                $p = $ci['data'] ?? null;
                if (!$p instanceof WC_Product) {
                    continue;
                }
                $items[] = [
                    'name'  => $p->get_name(),
                    'qty'   => (int) ($ci['quantity'] ?? 1),
                    'price' => html_entity_decode(wp_strip_all_tags(wc_price((float) $p->get_price() * (int) ($ci['quantity'] ?? 1))), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
                if (count($items) >= 5) {
                    break;
                }
            }
            $total = html_entity_decode(wp_strip_all_tags(wc_price((float) WC()->cart->get_total('edit'))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return ['items' => $items, 'total' => $total];
    }

    /** Product view by an identified, opted-in visitor: browse abandonment candidate. */
    public static function on_product_view(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $email = self::current_email();
        if ($email === '') {
            return;
        }
        $sub = SM_Subscribers::get_by_email($email);
        if (!SM_Subscribers::is_marketable($sub)) {
            return; // browse abandonment is marketing mail
        }
        $pid = get_the_ID();
        if (!$pid) {
            return;
        }
        global $wpdb;
        // Throttle: one event per product per 6 hours.
        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . SM_DB::table('events') . "
             WHERE email = %s AND type = 'product_view' AND ref_id = %d AND created_at >= DATE_SUB(%s, INTERVAL 6 HOUR)",
            $email, $pid, sm_now()
        ));
        if (!$recent) {
            $wpdb->insert(SM_DB::table('events'), [
                'email'         => $email,
                'subscriber_id' => (int) $sub['id'],
                'type'          => 'product_view',
                'ref_id'        => $pid,
                'payload'       => null,
                'created_at'    => sm_now(),
            ]);
        }
        // Frequency cap: skip if a browse email went out within the cap window.
        $cap = max(1, (int) sm_setting('browse_cap_days'));
        $sent_recently = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . SM_DB::table('sends') . "
             WHERE email = %s AND template = 'browse_1' AND sent_at >= DATE_SUB(%s, INTERVAL %d DAY)",
            $email, sm_now(), $cap
        ));
        if ($sent_recently) {
            return;
        }
        // Views so far today (most recent first, up to 4) become the product block at send time.
        $viewed = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ref_id FROM " . SM_DB::table('events') . "
             WHERE email = %s AND type = 'product_view' AND created_at >= DATE_SUB(%s, INTERVAL 24 HOUR)
             ORDER BY id DESC LIMIT 4",
            $email, sm_now()
        ));
        $products = [];
        foreach ((array) $viewed as $vid) {
            $p = wc_get_product((int) $vid);
            if ($p && $p->is_in_stock()) {
                $products[] = sm_product_payload($p, sm_zone_for_country(function_exists('sa_geo_country') ? sa_geo_country() : ''));
            }
        }
        if (!$products) {
            return;
        }
        SM_Queue::add([
            'subscriber_id' => (int) $sub['id'],
            'email'         => $email,
            'flow'          => 'browse_abandonment',
            'step'          => 'browse_1',
            'template'      => 'browse_1',
            'payload'       => [
                'first_name'     => $sub['first_name'] ?? '',
                'products'       => $products,
                'no_order_since' => sm_now(),
            ],
            'send_at'       => sm_dt(time() + SM_Flows::step_delay('browse_abandonment', 'browse_1', 20 * HOUR_IN_SECONDS)),
            'dedupe_key'    => 'browse|' . $email . '|' . gmdate('Y-m-d'),
        ]);
    }

    /** Checkout email capture (JS on blur): start the abandoned-checkout timer. */
    public static function ajax_capture_email(): void
    {
        $email = strtolower(trim(sanitize_email((string) ($_POST['email'] ?? ''))));
        if (!is_email($email)) {
            wp_send_json_error(['error' => 'invalid'], 400);
        }
        self::remember_email($email);
        $sub = SM_Subscribers::ensure($email, ['source' => 'checkout_start']);
        if ($sub && SM_Subscribers::is_contactable($sub)) {
            global $wpdb;
            $bumped = $wpdb->query($wpdb->prepare(
                "UPDATE " . SM_DB::table('queue') . "
                 SET send_at = %s
                 WHERE email = %s AND flow = 'abandoned_checkout' AND step = 'checkout_1' AND status = 'pending'",
                sm_dt(time() + SM_Flows::step_delay('abandoned_checkout', 'checkout_1', 2 * HOUR_IN_SECONDS)),
                $email
            ));
            if (!$bumped) {
                SM_Flows::start($sub, 'abandoned_checkout', 'day|' . gmdate('Y-m-d'), [
                    'no_order_since' => sm_now(),
                ]);
            }
        }
        wp_send_json_success(['ok' => true]);
    }

    /** Small script on the checkout page to capture the email field as it is filled in. */
    public static function checkout_capture_js(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }
        $ajax = admin_url('admin-ajax.php');
        echo '<script>(function(){var f=document.getElementById("billing_email");if(!f)return;var t=null;'
            . 'f.addEventListener("blur",function(){var v=f.value;if(!v||v.indexOf("@")<1)return;'
            . 'clearTimeout(t);t=setTimeout(function(){var d=new FormData();d.append("action","sm_capture_email");d.append("email",v);'
            . 'fetch(' . wp_json_encode($ajax) . ',{method:"POST",credentials:"same-origin",body:d}).catch(function(){});},400);});})();</script>';
    }

    /** Restock: notify opted-in past buyers and recent viewers of the product. */
    public static function on_stock_status(int $product_id, string $status, $product): void
    {
        if ($status !== 'instock' || !SM_Flows::enabled('restock_alert')) {
            return;
        }
        $p = wc_get_product($product_id);
        if (!$p) {
            return;
        }
        $emails = [];
        // Past buyers of this product.
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders(['status' => ['wc-processing', 'wc-completed'], 'limit' => 300, 'return' => 'objects']);
            foreach ($orders as $o) {
                foreach ($o->get_items() as $item) {
                    if ((int) $item->get_product_id() === $product_id) {
                        $emails[strtolower((string) $o->get_billing_email())] = true;
                        break;
                    }
                }
            }
        }
        // Recent viewers (last 60 days).
        global $wpdb;
        $viewers = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT email FROM " . SM_DB::table('events') . "
             WHERE type = 'product_view' AND ref_id = %d AND created_at >= DATE_SUB(%s, INTERVAL 60 DAY)",
            $product_id, sm_now()
        ));
        foreach ((array) $viewers as $e) {
            $emails[strtolower((string) $e)] = true;
        }
        foreach (array_keys($emails) as $email) {
            if (!is_email($email)) {
                continue;
            }
            $sub = SM_Subscribers::get_by_email($email);
            if (!SM_Subscribers::is_marketable($sub)) {
                continue;
            }
            SM_Flows::start($sub, 'restock_alert', 'restock|' . $product_id . '|' . gmdate('Y-m-d'), [
                'product_id'   => $product_id,
                'product_name' => $p->get_name(),
                'product_url'  => get_permalink($product_id),
                'products'     => [sm_product_payload($p, sm_subscriber_zone($sub))],
            ]);
        }
    }

    /** New product published: create a draft product-announcement campaign for approval. */
    public static function on_product_publish(string $new_status, string $old_status, $post): void
    {
        if ($new_status !== 'publish' || $old_status === 'publish' || !$post instanceof WP_Post || $post->post_type !== 'product') {
            return;
        }
        if (get_post_meta($post->ID, '_sm_campaign_created', true)) {
            return;
        }
        $p = wc_get_product($post->ID);
        if (!$p) {
            return;
        }
        update_post_meta($post->ID, '_sm_campaign_created', 1);
        SM_Campaigns::create([
            'name'        => 'New product: ' . $p->get_name(),
            'type'        => 'new_product',
            'template'    => 'new_product_1',
            'subject'     => 'New in the store: ' . $p->get_name(),
            'heading'     => 'Just listed: ' . $p->get_name(),
            'intro'       => 'New in the Supreme Autoparts store. Like everything we stock, fitment can be confirmed for your vehicle before you buy.',
            'segment_key' => 'all_active',
            'product_ids' => [$post->ID],
            'status'      => 'draft',
        ]);
    }
}

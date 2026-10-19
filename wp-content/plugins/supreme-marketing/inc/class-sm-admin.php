<?php
/**
 * wp-admin UI: Dashboard, Flows, Campaigns, Subscribers, Queue, Welcome 20, Settings, template previews.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Admin
{
    private const CAP = 'manage_woocommerce';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'maybe_preview'], 1);
        add_action('admin_init', [self::class, 'handle_posts']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_filter('plugin_action_links_' . plugin_basename(SUPM_FILE), [self::class, 'action_links']);
    }

    public static function action_links(array $links): array
    {
        $url = admin_url('admin.php?page=sm-dashboard');
        array_unshift($links, '<a href="' . esc_url($url) . '">Marketing dashboard</a>');
        return $links;
    }

    public static function menu(): void
    {
        add_menu_page('Supreme Marketing', 'Marketing', self::CAP, 'sm-dashboard', [self::class, 'page_dashboard'], 'dashicons-email-alt', 56);
        add_submenu_page('sm-dashboard', 'Dashboard', 'Dashboard', self::CAP, 'sm-dashboard', [self::class, 'page_dashboard']);
        add_submenu_page('sm-dashboard', 'Flows', 'Flows', self::CAP, 'sm-flows', [self::class, 'page_flows']);
        add_submenu_page('sm-dashboard', 'Campaigns', 'Campaigns', self::CAP, 'sm-campaigns', [self::class, 'page_campaigns']);
        add_submenu_page('sm-dashboard', 'Subscribers', 'Subscribers', self::CAP, 'sm-subscribers', [self::class, 'page_subscribers']);
        add_submenu_page('sm-dashboard', 'Queue', 'Queue', self::CAP, 'sm-queue', [self::class, 'page_queue']);
        add_submenu_page('sm-dashboard', 'Welcome 20', 'Welcome 20', self::CAP, 'sm-welcome', [self::class, 'page_welcome']);
        add_submenu_page('sm-dashboard', 'Settings', 'Settings', self::CAP, 'sm-settings', [self::class, 'page_settings']);
        add_submenu_page('sm-dashboard', 'System check', 'System check', self::CAP, 'sm-debug', [self::class, 'page_debug']);
    }

    public static function assets(string $hook): void
    {
        if (strpos($hook, 'sm-') === false) {
            return;
        }
        wp_enqueue_style('sm-admin', SUPM_URL . 'assets/sm-admin.css', [], SUPM_VERSION);
    }

    /* ---------------------------------------------------------------- */
    /* POST handlers                                                     */
    /* ---------------------------------------------------------------- */

    public static function handle_posts(): void
    {
        if (!isset($_POST['sm_admin_action']) || !current_user_can(self::CAP)) {
            return;
        }
        $action = sanitize_key((string) $_POST['sm_admin_action']);
        if (!check_admin_referer('sm_admin_' . $action)) {
            return;
        }
        $back = wp_get_referer() ?: admin_url('admin.php?page=sm-dashboard');

        switch ($action) {
            case 'toggle_master':
                sm_update_setting('master_enabled', sm_master_on() ? 'no' : 'yes');
                break;
            case 'toggle_test_mode':
                sm_update_setting('test_mode', sm_test_mode() ? 'no' : 'yes');
                break;
            case 'toggle_flow':
                $flow = sanitize_key((string) ($_POST['flow'] ?? ''));
                $flags = get_option('sm_flows_enabled', []);
                $flags = is_array($flags) ? $flags : [];
                $flags[$flow] = (($flags[$flow] ?? 'yes') === 'yes') ? 'no' : 'yes';
                update_option('sm_flows_enabled', $flags, false);
                break;
            case 'save_delays':
                $flow = sanitize_key((string) ($_POST['flow'] ?? ''));
                $delays = get_option('sm_flow_delays', []);
                $delays = is_array($delays) ? $delays : [];
                foreach ((array) ($_POST['delay_hours'] ?? []) as $step => $hours) {
                    $delays[$flow][sanitize_key((string) $step)] = (int) round(((float) $hours) * HOUR_IN_SECONDS);
                }
                update_option('sm_flow_delays', $delays, false);
                break;
            case 'save_settings':
                foreach (['test_recipients', 'from_name', 'from_email'] as $k) {
                    if (isset($_POST[$k])) {
                        sm_update_setting($k, sanitize_textarea_field((string) $_POST[$k]));
                    }
                }
                foreach (['reorder_days', 'winback_1_days', 'winback_2_days', 'browse_cap_days', 'send_batch', 'recommend_count', 'service_reminder_days'] as $k) {
                    if (isset($_POST[$k])) {
                        sm_update_setting($k, max(1, (int) $_POST[$k]));
                    }
                }
                break;
            case 'create_campaign':
                $type = sanitize_key((string) ($_POST['type'] ?? 'newsletter'));
                SM_Campaigns::create([
                    'name'        => (string) ($_POST['name'] ?? ''),
                    'type'        => $type,
                    'template'    => (string) (SM_Campaigns::types()[$type]['template'] ?? 'newsletter_1'),
                    'subject'     => (string) ($_POST['subject'] ?? ''),
                    'heading'     => (string) ($_POST['heading'] ?? ''),
                    'intro'       => (string) ($_POST['intro'] ?? ''),
                    'segment_key' => (string) ($_POST['segment_key'] ?? 'all_active'),
                    'product_ids' => preg_split('/[\s,]+/', (string) ($_POST['product_ids'] ?? '')) ?: [],
                    'category'    => (string) ($_POST['category'] ?? ''),
                    'coupon_code' => (string) ($_POST['coupon_code'] ?? ''),
                    'coupon_type' => (string) ($_POST['coupon_type'] ?? 'percent'),
                    'coupon_amount' => (string) ($_POST['coupon_amount'] ?? ''),
                    'coupon_days' => (int) ($_POST['coupon_days'] ?? 14),
                    'coupon_free_shipping' => !empty($_POST['coupon_free_shipping']),
                    'scheduled_at'=> !empty($_POST['scheduled_at']) ? sanitize_text_field((string) $_POST['scheduled_at']) : null,
                ]);
                break;
            case 'approve_campaign':
                $queued = SM_Campaigns::approve_and_enqueue((int) ($_POST['campaign_id'] ?? 0), get_current_user_id());
                $back = add_query_arg('sm_notice', $queued < 0 ? 'offer_no_amount' : 'approved_' . $queued, $back);
                break;
            case 'delete_campaign':
                $id = (int) ($_POST['campaign_id'] ?? 0);
                $c = SM_Campaigns::get($id);
                if ($c && $c['status'] === 'draft') {
                    global $wpdb;
                    $wpdb->delete(SM_DB::table('campaigns'), ['id' => $id]);
                }
                break;
            case 'add_subscriber':
                $email = strtolower(trim(sanitize_email((string) ($_POST['email'] ?? ''))));
                if (is_email($email)) {
                    SM_Consent::begin_double_optin($email, 'admin_add');
                }
                break;
            case 'send_repermission':
                global $wpdb;
                $rows = $wpdb->get_results(
                    "SELECT * FROM " . SM_DB::table('subscribers') . " WHERE status = 'transactional_only' AND orders_count > 0 LIMIT 500",
                    ARRAY_A
                );
                $n = 0;
                foreach ((array) $rows as $sub) {
                    $ok = SM_Queue::add([
                        'subscriber_id' => (int) $sub['id'],
                        'email'         => $sub['email'],
                        'flow'          => 'repermission',
                        'step'          => 'repermission',
                        'template'      => 'repermission',
                        'payload'       => ['first_name' => $sub['first_name'] ?? ''],
                        'send_at'       => sm_now(),
                        'dedupe_key'    => 'repermission|' . $sub['email'],
                    ]);
                    if ($ok) {
                        $n++;
                    }
                }
                $back = add_query_arg('sm_notice', 'repermission_' . $n, $back);
                break;
            case 'run_queue_now':
                try {
                    SM_Queue::process();
                    $back = add_query_arg('sm_notice', 'queue_run', $back);
                } catch (\Throwable $e) {
                    $back = add_query_arg('sm_notice', 'queue_err', $back);
                    $back = add_query_arg('sm_err', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), $back);
                }
                break;
            case 'cancel_queue_row':
                global $wpdb;
                $wpdb->update(SM_DB::table('queue'), ['status' => 'cancelled', 'last_error' => 'admin_cancelled'], ['id' => (int) ($_POST['queue_id'] ?? 0), 'status' => 'pending']);
                break;
            case 'revoke_welcome_code':
                global $wpdb;
                $id = (int) ($_POST['code_id'] ?? 0);
                $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SM_DB::table('welcome_codes') . ' WHERE id = %d', $id), ARRAY_A);
                if ($row && $row['status'] === 'active') {
                    $wpdb->update(SM_DB::table('welcome_codes'), ['status' => 'revoked'], ['id' => $id]);
                    if (function_exists('wc_get_coupon_id_by_code')) {
                        $cid = (int) wc_get_coupon_id_by_code((string) $row['code']);
                        if ($cid > 0) {
                            wp_trash_post($cid);
                        }
                    }
                }
                break;
        }
        wp_safe_redirect($back);
        exit;
    }

    /* ---------------------------------------------------------------- */
    /* Shared chrome                                                     */
    /* ---------------------------------------------------------------- */

    private static function header(string $title): void
    {
        $master = sm_master_on();
        $test = sm_test_mode();
        echo '<div class="wrap sm-wrap"><h1>' . esc_html($title) . '</h1>';
        echo '<div class="sm-statusbar">';
        echo '<span class="sm-pill ' . ($master ? 'sm-on' : 'sm-off') . '">Master switch: ' . ($master ? 'ON' : 'OFF') . '</span>';
        echo '<span class="sm-pill ' . ($test ? 'sm-warn' : 'sm-on') . '">Test mode: ' . ($test ? 'ON (all mail goes to test inbox)' : 'OFF (live recipients)') . '</span>';
        if (!$master) {
            echo '<span class="sm-note">Nothing sends while the master switch is OFF - flows keep queuing so you can review exactly what would go out.</span>';
        }
        echo '</div>';
        if (isset($_GET['sm_notice'])) {
            $n = sanitize_text_field((string) $_GET['sm_notice']);
            if (str_starts_with($n, 'approved_')) {
                echo '<div class="notice notice-success"><p>Campaign approved: ' . esc_html(substr($n, 9)) . ' emails queued (they send only when the master switch is ON).</p></div>';
            } elseif (str_starts_with($n, 'repermission_')) {
                echo '<div class="notice notice-success"><p>' . esc_html(substr($n, 13)) . ' re-permission emails queued.</p></div>';
            } elseif ($n === 'offer_no_amount') {
                echo '<div class="notice notice-error"><p>This offer has no discount amount. Edit the draft and set a percent or fixed amount first.</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Saved.</p></div>';
            }
        }
    }

    private static function toggle_button(string $action, string $label, string $class = 'button'): void
    {
        echo '<form method="post" style="display:inline-block;margin-right:6px;">'
            . wp_nonce_field('sm_admin_' . $action, '_wpnonce', true, false)
            . '<input type="hidden" name="sm_admin_action" value="' . esc_attr($action) . '">'
            . '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    /* ---------------------------------------------------------------- */
    /* Dashboard                                                         */
    /* ---------------------------------------------------------------- */

    public static function page_dashboard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('nope');
        }
        self::header('Supreme Marketing');
        $subs = SM_Subscribers::counts();
        $q = SM_Queue::stats();
        $w = SM_Welcome::stats();
        $active = (int) ($subs['active'] ?? 0);
        $transactional = (int) ($subs['transactional_only'] ?? 0);
        $unconfirmed = (int) ($subs['unconfirmed'] ?? 0);
        $unsub = (int) ($subs['unsubscribed'] ?? 0) + (int) ($subs['bounced'] ?? 0) + (int) ($subs['complained'] ?? 0);

        echo '<div class="sm-cards">';
        self::card('Opted-in subscribers', (string) $active, 'receive marketing emails');
        self::card('Customers (not opted in)', (string) $transactional, 'order emails only');
        self::card('Awaiting confirmation', (string) $unconfirmed, 'double opt-in pending');
        self::card('Unsubscribed', (string) $unsub, 'never emailed');
        self::card('Queued (waiting)', (string) $q['pending'], 'sends when master is ON');
        self::card('Sent (7 days)', (string) $q['sent_7d'], $q['opens_7d'] . ' opens / ' . $q['clicks_7d'] . ' clicks');
        self::card('Welcome 20 codes', (string) $w['active'] . ' active', $w['redeemed'] . ' redeemed / ' . $w['blocked'] . ' abuse blocks');
        echo '</div>';

        echo '<h2>Controls</h2><p>';
        self::toggle_button('toggle_master', sm_master_on() ? 'Turn master switch OFF' : 'Turn master switch ON', sm_master_on() ? 'button' : 'button button-primary');
        self::toggle_button('toggle_test_mode', sm_test_mode() ? 'Turn test mode OFF (go live)' : 'Turn test mode ON', 'button');
        echo '</p>';
        echo '<p class="description">Order of operations: review templates under <a href="' . esc_url(admin_url('admin.php?page=sm-flows')) . '">Flows</a>, '
            . 'keep test mode ON and flip the master switch to see exactly what customers would get (it all lands in the test inbox), '
            . 'then turn test mode OFF to go live.</p>';

        echo '<h2>Imported customers</h2><p>The customers imported from order history are marked <em>transactional only</em>: '
            . 'they get order and account emails but no marketing until they opt in. '
            . 'You can send them a one-time "want store updates?" email; only people who click yes become marketable.</p><p>';
        echo '<form method="post" onsubmit="return confirm(\'Queue the one-time re-permission email to all imported customers?\');">'
            . wp_nonce_field('sm_admin_send_repermission', '_wpnonce', true, false)
            . '<input type="hidden" name="sm_admin_action" value="send_repermission">'
            . '<button type="submit" class="button">Queue re-permission email</button></form></p>';

        echo '</div>';
    }

    private static function card(string $label, string $big, string $small): void
    {
        echo '<div class="sm-card"><div class="sm-card-label">' . esc_html($label) . '</div>'
            . '<div class="sm-card-big">' . esc_html($big) . '</div>'
            . '<div class="sm-card-small">' . esc_html($small) . '</div></div>';
    }

    /* ---------------------------------------------------------------- */
    /* Flows                                                             */
    /* ---------------------------------------------------------------- */

    public static function page_flows(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('nope');
        }
        self::header('Automated flows');
        $flags = get_option('sm_flows_enabled', []);
        $delays = get_option('sm_flow_delays', []);
        echo '<p>Each flow runs automatically when its trigger fires. Enabled flows queue emails; the master switch (dashboard) controls actual sending. Click a step to preview the exact email (add <code>&amp;sm_zone=ke</code> to the preview URL for the Kenya version).</p>';
        foreach (SM_Flows::definitions() as $key => $def) {
            $on = (($flags[$key] ?? 'yes') === 'yes');
            echo '<div class="sm-flow">';
            echo '<div class="sm-flow-head"><h2>' . esc_html($def['label']) . '</h2>';
            echo '<span class="sm-pill ' . ($on ? 'sm-on' : 'sm-off') . '">' . ($on ? 'Enabled' : 'Disabled') . '</span>';
            echo '<form method="post" style="display:inline-block;margin-left:8px;">'
                . wp_nonce_field('sm_admin_toggle_flow', '_wpnonce', true, false)
                . '<input type="hidden" name="sm_admin_action" value="toggle_flow">'
                . '<input type="hidden" name="flow" value="' . esc_attr($key) . '">'
                . '<button type="submit" class="button">' . ($on ? 'Disable' : 'Enable') . '</button></form></div>';
            echo '<p class="description"><strong>Trigger:</strong> ' . esc_html($def['trigger']) . ' '
                . '<strong>Audience:</strong> ' . ($def['audience'] === 'marketing' ? 'opted-in subscribers only' : 'shoppers with a live cart/order (soft opt-in)') . '</p>';
            echo '<form method="post">'
                . wp_nonce_field('sm_admin_save_delays', '_wpnonce', true, false)
                . '<input type="hidden" name="sm_admin_action" value="save_delays">'
                . '<input type="hidden" name="flow" value="' . esc_attr($key) . '">';
            echo '<table class="widefat striped sm-steps"><thead><tr><th>Email</th><th>Send after trigger</th><th>What it says</th><th>Preview</th></tr></thead><tbody>';
            foreach ($def['steps'] as $step) {
                $secs = isset($delays[$key][$step['key']]) ? (int) $delays[$key][$step['key']] : (int) $step['delay'];
                $hours = $secs / HOUR_IN_SECONDS;
                $preview = wp_nonce_url(admin_url('admin.php?page=sm-flows&sm_preview=' . $step['key']), 'sm_preview_' . $step['key'], 'sm_nonce');
                echo '<tr><td><code>' . esc_html($step['key']) . '</code></td>'
                    . '<td><input type="number" min="0" step="1" name="delay_hours[' . esc_attr($step['key']) . ']" value="' . esc_attr((string) $hours) . '" style="width:80px;"> hours</td>'
                    . '<td>' . esc_html($step['note']) . '</td>'
                    . '<td><a href="' . esc_url($preview) . '" target="_blank" class="button button-small">Preview</a></td></tr>';
            }
            echo '</tbody></table><p><button type="submit" class="button">Save timings</button></p></form></div>';
        }
        echo '</div>';
    }

    /* ---------------------------------------------------------------- */
    /* Campaigns                                                         */
    /* ---------------------------------------------------------------- */

    public static function page_campaigns(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('nope');
        }
        self::header('Campaigns');
        $segments = SM_Segments::definitions();
        $types = SM_Campaigns::types();
        $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 100]);
        if (!is_array($cats)) {
            $cats = [];
        }

        echo '<h2>New campaign</h2>';
        echo '<form method="post" class="sm-form">'
            . wp_nonce_field('sm_admin_create_campaign', '_wpnonce', true, false)
            . '<input type="hidden" name="sm_admin_action" value="create_campaign">'
            . '<table class="form-table"><tbody>'
            . '<tr><th>Name (internal)</th><td><input type="text" name="name" class="regular-text" required></td></tr>'
            . '<tr><th>Type</th><td><select name="type">';
        foreach ($types as $k => $t) {
            echo '<option value="' . esc_attr($k) . '">' . esc_html($t['label']) . '</option>';
        }
        echo '</select></td></tr>'
            . '<tr><th>Subject</th><td><input type="text" name="subject" class="large-text" required></td></tr>'
            . '<tr><th>Heading</th><td><input type="text" name="heading" class="large-text"></td></tr>'
            . '<tr><th>Intro text</th><td><textarea name="intro" rows="3" class="large-text"></textarea></td></tr>'
            . '<tr><th>Segment</th><td><select name="segment_key">';
        foreach ($segments as $k => $label) {
            echo '<option value="' . esc_attr($k) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>'
            . '<tr><th>Category</th><td><select name="category"><option value="">- none -</option>';
        foreach ($cats as $c) {
            echo '<option value="' . esc_attr($c->slug) . '">' . esc_html($c->name) . ' (' . (int) $c->count . ')</option>';
        }
        echo '</select><p class="description">Required for a category spotlight; optional scoping for offers.</p></td></tr>'
            . '<tr><th>Product IDs</th><td><input type="text" name="product_ids" class="regular-text" placeholder="e.g. 123 456 789"><p class="description">For product adverts and bundles. Blank = auto-pick best sellers. IDs are in Products &rarr; All Products.</p></td></tr>'
            . '<tr><th colspan="2"><hr><strong>Offer / promo code</strong> (offer campaigns only)</th></tr>'
            . '<tr><th>Discount</th><td><select name="coupon_type" style="width:140px;"><option value="percent">Percent off</option><option value="fixed_cart">Fixed amount off (USD)</option></select> '
            . '<input type="number" name="coupon_amount" min="0" step="0.01" style="width:110px;" placeholder="e.g. 15"> '
            . '<label><input type="checkbox" name="coupon_free_shipping" value="1"> include free shipping</label></td></tr>'
            . '<tr><th>Code (optional)</th><td><input type="text" name="coupon_code" class="regular-text" placeholder="Blank = auto-generate e.g. SUP4F8K2Q">'
            . ' &nbsp; Valid for <input type="number" name="coupon_days" min="1" value="14" style="width:70px;"> days</td></tr>'
            . '<tr><th colspan="2"><hr></th></tr>'
            . '<tr><th>Send at (optional)</th><td><input type="datetime-local" name="scheduled_at"><p class="description">Blank = send as soon as approved and the master switch is ON.</p></td></tr>'
            . '</tbody></table><p><button type="submit" class="button button-primary">Save draft</button></p></form>';

        echo '<h2>All campaigns</h2>';
        $campaigns = SM_Campaigns::all();
        if (!$campaigns) {
            echo '<p>No campaigns yet.</p></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Type</th><th>Segment</th><th>Code</th><th>Status</th><th>Stats</th><th>Actions</th></tr></thead><tbody>';
        foreach ($campaigns as $c) {
            SM_Campaigns::maybe_complete((int) $c['id']);
            $stats = json_decode((string) ($c['stats'] ?? ''), true);
            $preview = wp_nonce_url(admin_url('admin.php?page=sm-flows&sm_preview=' . $c['template']), 'sm_preview_' . $c['template'], 'sm_nonce');
            echo '<tr><td><strong>' . esc_html($c['name']) . '</strong><br><span class="description">' . esc_html($c['subject']) . '</span></td>'
                . '<td>' . esc_html($c['type']) . '</td>'
                . '<td>' . esc_html($segments[$c['segment_key']] ?? $c['segment_key']) . '</td>'
                . '<td><code>' . esc_html((string) ($c['coupon_code'] ?? '')) . '</code></td>'
                . '<td><span class="sm-pill ' . ($c['status'] === 'done' ? 'sm-on' : ($c['status'] === 'draft' ? 'sm-off' : 'sm-warn')) . '">' . esc_html($c['status']) . '</span></td>'
                . '<td>' . esc_html(is_array($stats) ? wp_json_encode($stats) : '-') . '</td><td>'
                . '<a href="' . esc_url($preview) . '" target="_blank" class="button button-small">Preview</a> ';
            if ($c['status'] === 'draft') {
                echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'Approve and queue this campaign to its segment? Emails send when the master switch is ON.\');">'
                    . wp_nonce_field('sm_admin_approve_campaign', '_wpnonce', true, false)
                    . '<input type="hidden" name="sm_admin_action" value="approve_campaign">'
                    . '<input type="hidden" name="campaign_id" value="' . (int) $c['id'] . '">'
                    . '<button type="submit" class="button button-primary button-small">Approve + queue</button></form> ';
                echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'Delete this draft?\');">'
                    . wp_nonce_field('sm_admin_delete_campaign', '_wpnonce', true, false)
                    . '<input type="hidden" name="sm_admin_action" value="delete_campaign">'
                    . '<input type="hidden" name="campaign_id" value="' . (int) $c['id'] . '">'
                    . '<button type="submit" class="button button-small">Delete</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /* ---------------------------------------------------------------- */
    /* Subscribers                                                       */
    /* ---------------------------------------------------------------- */

    public static function page_subscribers(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('nope');
        }
        self::header('Subscribers');
        global $wpdb;
        $t = SM_DB::table('subscribers');
        $status = sanitize_key((string) ($_GET['status'] ?? ''));
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $per = 50;
        $where = '';
        $args = [];
        if ($status !== '') {
            $where = 'WHERE status = %s';
            $args[] = $status;
        }
        $total = $args
            ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t $where", ...$args))
            : (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t $where ORDER BY id DESC LIMIT %d OFFSET %d",
            ...array_merge($args, [$per, ($paged - 1) * $per])
        ), ARRAY_A);

        echo '<p>Filters: ';
        $labels = ['' => 'All', 'active' => 'Opted-in', 'transactional_only' => 'Customers', 'unconfirmed' => 'Awaiting confirm', 'unsubscribed' => 'Unsubscribed'];
        foreach ($labels as $k => $label) {
            $url = admin_url('admin.php?page=sm-subscribers' . ($k ? '&status=' . $k : ''));
            echo '<a href="' . esc_url($url) . '" class="button button-small' . ($status === $k ? ' button-primary' : '') . '">' . esc_html($label) . '</a> ';
        }
        echo '</p>';

        echo '<form method="post" style="margin-bottom:14px;">'
            . wp_nonce_field('sm_admin_add_subscriber', '_wpnonce', true, false)
            . '<input type="hidden" name="sm_admin_action" value="add_subscriber">'
            . '<input type="email" name="email" placeholder="name@example.com" required> '
            . '<button type="submit" class="button">Add (sends confirmation email)</button></form>';

        echo '<table class="widefat striped"><thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Source</th><th>Region</th><th>Orders</th><th>Spent</th><th>Last order</th><th>Since</th></tr></thead><tbody>';
        foreach ((array) $rows as $r) {
            echo '<tr><td>' . esc_html($r['email']) . '</td>'
                . '<td>' . esc_html(trim($r['first_name'] . ' ' . $r['last_name'])) . '</td>'
                . '<td><span class="sm-pill ' . ($r['status'] === 'active' ? 'sm-on' : 'sm-off') . '">' . esc_html($r['status']) . '</span></td>'
                . '<td>' . esc_html($r['source']) . '</td>'
                . '<td>' . esc_html($r['region']) . '</td>'
                . '<td>' . (int) $r['orders_count'] . '</td>'
                . '<td>$' . number_format((float) $r['total_spent'], 2) . '</td>'
                . '<td>' . esc_html((string) $r['last_order_at']) . '</td>'
                . '<td>' . esc_html((string) $r['created_at']) . '</td></tr>';
        }
        echo '</tbody></table>';
        $pages = max(1, (int) ceil($total / $per));
        if ($pages > 1) {
            echo '<p>Page ' . $paged . ' of ' . $pages . ' ';
            if ($paged > 1) {
                echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=sm-subscribers&status=' . $status . '&paged=' . ($paged - 1))) . '">&laquo; Prev</a> ';
            }
            if ($paged < $pages) {
                echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=sm-subscribers&status=' . $status . '&paged=' . ($paged + 1))) . '">Next &raquo;</a>';
            }
            echo '</p>';
        }
        echo '</div>';
    }

    /* ---------------------------------------------------------------- */
    /* Queue                                                             */
    /* ---------------------------------------------------------------- */

    public static function page_queue(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('nope');
        }
        self::header('Send queue');
        global $wpdb;
        $q = SM_DB::table('queue');
        $next = wp_next_scheduled('sm_process_queue');
        $nextd = wp_next_scheduled('sm_daily_scan');
        echo '<p class="description">Cron health: <code>sm_process_queue</code> '
            . ($next ? 'next run ' . esc_html(gmdate('Y-m-d H:i:s', $next)) . ' UTC (in ' . (int) ($next - time()) . 's)' : '<strong style="color:#b32d2e;">NOT scheduled</strong>')
            . ' | <code>sm_daily_scan</code> ' . ($nextd ? 'next ' . esc_html(gmdate('Y-m-d H:i:s', $nextd)) . ' UTC' : '<strong style="color:#b32d2e;">NOT scheduled</strong>')
            . ' | server time ' . esc_html(gmdate('Y-m-d H:i:s')) . ' UTC</p>';
        if (isset($_GET['sm_notice']) && $_GET['sm_notice'] === 'queue_run') {
            echo '<div class="notice notice-success is-dismissible"><p>Queue processed manually.</p></div>';
        }
        if (isset($_GET['sm_notice']) && $_GET['sm_notice'] === 'queue_err') {
            echo '<div class="notice notice-error"><p>Queue error: ' . esc_html((string) ($_GET['sm_err'] ?? '')) . '</p></div>';
        }
        echo '<form method="post" style="margin-bottom:10px;">'
            . wp_nonce_field('sm_admin_run_queue_now', '_wpnonce', true, false)
            . '<input type="hidden" name="sm_admin_action" value="run_queue_now">'
            . '<button type="submit" class="button">Process queue now</button></form>';
        $status = sanitize_key((string) ($_GET['qstatus'] ?? 'pending'));
        if (!in_array($status, ['pending', 'sent', 'cancelled', 'failed'], true)) {
            $status = 'pending';
        }
        echo '<p>';
        foreach (['pending', 'sent', 'cancelled', 'failed'] as $st) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=sm-queue&qstatus=' . $st)) . '" class="button button-small' . ($status === $st ? ' button-primary' : '') . '">' . esc_html(ucfirst($st)) . '</a> ';
        }
        echo '</p>';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $q WHERE status = %s ORDER BY " . ($status === 'pending' ? 'send_at ASC' : 'id DESC') . ' LIMIT 100',
            $status
        ), ARRAY_A);
        echo '<table class="widefat striped"><thead><tr><th>Email</th><th>Template</th><th>Flow</th><th>Send at</th><th>Status</th><th>Note</th><th></th></tr></thead><tbody>';
        foreach ((array) $rows as $r) {
            echo '<tr><td>' . esc_html($r['email']) . '</td>'
                . '<td><code>' . esc_html($r['template']) . '</code></td>'
                . '<td>' . esc_html($r['flow'] !== '' ? $r['flow'] : ($r['campaign_id'] ? 'campaign #' . (int) $r['campaign_id'] : '-')) . '</td>'
                . '<td>' . esc_html($r['send_at']) . '</td>'
                . '<td>' . esc_html($r['status']) . '</td>'
                . '<td>' . esc_html((string) $r['last_error']) . '</td><td>';
            if ($r['status'] === 'pending') {
                echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'Cancel this queued email?\');">'
                    . wp_nonce_field('sm_admin_cancel_queue_row', '_wpnonce', true, false)
                    . '<input type="hidden" name="sm_admin_action" value="cancel_queue_row">'
                    . '<input type="hidden" name="queue_id" value="' . (int) $r['id'] . '">'
                    . '<button type="submit" class="button button-small">Cancel</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /* ---------------------------------------------------------------- */
    /* Welcome 20                                                        */
    /* ---------------------------------------------------------------- */

    public static function page_welcome(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('nope');
        }
        self::header('Welcome 20 - first-order discount');
        echo '<p>Every first-time customer who creates an account or signs in (Google or email code) gets a personal <strong>20% off</strong> code: '
            . 'one use, first order only, valid ' . SM_Welcome::EXPIRY_DAYS . ' days, locked to their own email, auto-applied at checkout while signed in. '
            . 'Abuse checks: normalized email (plus-tags, Gmail dots), disposable domains blocked, one code per device / IP / email, '
            . 'signup rate limits, and phone + address matching against past welcome orders. Blocks are logged below.</p>';
        $s = SM_Welcome::stats();
        echo '<div class="sm-cards">';
        self::card('Codes issued', (string) $s['issued'], 'personal, one per customer');
        self::card('Active', (string) $s['active'], 'not yet used');
        self::card('Redeemed', (string) $s['redeemed'], 'turned into orders');
        self::card('Blocked attempts', (string) $s['blocked'], 'abuse checks tripped');
        echo '</div>';

        echo '<h2>Recent codes</h2>';
        $codes = SM_Welcome::recent_codes();
        if (!$codes) {
            echo '<p>No codes issued yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Email</th><th>Code</th><th>Status</th><th>Issued</th><th>Expires</th><th>Order</th><th></th></tr></thead><tbody>';
            foreach ($codes as $r) {
                echo '<tr><td>' . esc_html($r['email']) . '</td>'
                    . '<td><code>' . esc_html($r['code']) . '</code></td>'
                    . '<td><span class="sm-pill ' . ($r['status'] === 'active' ? 'sm-on' : ($r['status'] === 'redeemed' ? 'sm-warn' : 'sm-off')) . '">' . esc_html($r['status']) . '</span></td>'
                    . '<td>' . esc_html($r['created_at']) . '</td>'
                    . '<td>' . esc_html((string) $r['expires_at']) . '</td>'
                    . '<td>' . ($r['order_id'] ? '<a href="' . esc_url(admin_url('post.php?post=' . (int) $r['order_id'] . '&action=edit')) . '">#' . (int) $r['order_id'] . '</a>' : '-') . '</td><td>';
                if ($r['status'] === 'active') {
                    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'Revoke this code?\');">'
                        . wp_nonce_field('sm_admin_revoke_welcome_code', '_wpnonce', true, false)
                        . '<input type="hidden" name="sm_admin_action" value="revoke_welcome_code">'
                        . '<input type="hidden" name="code_id" value="' . (int) $r['id'] . '">'
                        . '<button type="submit" class="button button-small">Revoke</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>Blocked attempts</h2>';
        $blocks = SM_Welcome::recent_blocks();
        if (!$blocks) {
            echo '<p>No blocked attempts.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>When</th><th>Check</th><th>What happened</th><th>Email</th><th>IP</th></tr></thead><tbody>';
            foreach ($blocks as $r) {
                echo '<tr><td>' . esc_html($r['created_at']) . '</td>'
                    . '<td><code>' . esc_html($r['kind']) . '</code></td>'
                    . '<td>' . esc_html($r['detail']) . '</td>'
                    . '<td>' . esc_html($r['email']) . '</td>'
                    . '<td>' . esc_html($r['ip']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    /* ---------------------------------------------------------------- */
    /* Settings                                                          */
    /* ---------------------------------------------------------------- */

    public static function page_settings(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('nope');
        }
        self::header('Marketing settings');
        $s = sm_settings();
        echo '<form method="post">'
            . wp_nonce_field('sm_admin_save_settings', '_wpnonce', true, false)
            . '<input type="hidden" name="sm_admin_action" value="save_settings">'
            . '<table class="form-table"><tbody>'
            . '<tr><th>Test recipients</th><td><input type="text" name="test_recipients" value="' . esc_attr($s['test_recipients']) . '" class="large-text"><p class="description">While test mode is ON, every email goes here instead of real customers. Separate multiple with commas.</p></td></tr>'
            . '<tr><th>From name</th><td><input type="text" name="from_name" value="' . esc_attr($s['from_name']) . '" class="regular-text"></td></tr>'
            . '<tr><th>From email</th><td><input type="text" name="from_email" value="' . esc_attr((string) $s['from_email']) . '" class="regular-text" placeholder="store default"><p class="description">Blank = the store\'s configured sender address.</p></td></tr>'
            . '<tr><th>Reorder reminder (days)</th><td><input type="number" name="reorder_days" value="' . (int) $s['reorder_days'] . '" min="7"><p class="description">Default lifetime of a consumable part; reminder goes out 5 days before. Per-product override: <code>_sm_reorder_days</code> product meta.</p></td></tr>'
            . '<tr><th>Service reminder (days)</th><td><input type="number" name="service_reminder_days" value="' . (int) $s['service_reminder_days'] . '" min="30"><p class="description">Days after a garage visit before the "time for your next service" email (Kenya only).</p></td></tr>'
            . '<tr><th>Win-back day 1 / day 2</th><td><input type="number" name="winback_1_days" value="' . (int) $s['winback_1_days'] . '" min="7" style="width:80px;"> / <input type="number" name="winback_2_days" value="' . (int) $s['winback_2_days'] . '" min="14" style="width:80px;"> days without an order</td></tr>'
            . '<tr><th>Browse email cap (days)</th><td><input type="number" name="browse_cap_days" value="' . (int) $s['browse_cap_days'] . '" min="1"></td></tr>'
            . '<tr><th>Send batch (per 5 min)</th><td><input type="number" name="send_batch" value="' . (int) $s['send_batch'] . '" min="1" max="500"></td></tr>'
            . '<tr><th>Recommendations per email</th><td><input type="number" name="recommend_count" value="' . (int) $s['recommend_count'] . '" min="2" max="4"></td></tr>'
            . '</tbody></table><p><button type="submit" class="button button-primary">Save settings</button></p></form>';
        echo '<h2>Sending rail</h2><p class="description">Email leaves through the site\'s existing WordPress mail setup (Brevo). '
            . 'The master switch on the dashboard is the final gate; test mode reroutes everything to the test inbox.</p>';
        echo '</div>';
    }

    /* ---------------------------------------------------------------- */
    /* Template preview                                                  */
    /* ---------------------------------------------------------------- */

    public static function maybe_preview(): void
    {
        $key = sanitize_key((string) ($_GET['sm_preview'] ?? ''));
        if ($key === '') {
            return;
        }
        if (!current_user_can(self::CAP)) {
            return;
        }
        if (!isset($_GET['sm_nonce']) || !wp_verify_nonce(sanitize_text_field((string) $_GET['sm_nonce']), 'sm_preview_' . $key)) {
            return;
        }
        $rendered = SM_Templates::render($key, SM_Templates::sample_context($key));
        if ($rendered === null) {
            echo '<div class="notice notice-error"><p>Unknown template.</p></div>';
            return;
        }
        // Output the raw email HTML (no wp-admin chrome) so it can be viewed/screenshotted exactly as sent.
        echo $rendered['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- own template output
        exit;
    }

    public static function page_debug() {
        if (!current_user_can(self::CAP)) { wp_die('denied'); }
        global $wpdb;
        $memory_limit = ini_get('memory_limit');
        $php_version  = phpversion();
        $wp_version   = get_bloginfo('version');
        $big = $wpdb->get_results("SELECT option_name, LENGTH(option_value) AS size_bytes, autoload FROM {$wpdb->options} ORDER BY LENGTH(option_value) DESC LIMIT 30");
        $total_options = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
        $total_bytes   = (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options}");
        $names  = $wpdb->get_col("SELECT option_name FROM {$wpdb->options}");
        $broken = array();
        foreach ($names as $name) {
            try { get_option($name); } catch (\Throwable $t) { $broken[] = array('name' => $name, 'error' => get_class($t) . ': ' . $t->getMessage()); }
        }
        $auto_bytes = (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','true','auto','auto-on')");
        $auto_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ('yes','on','true','auto','auto-on')");
        echo '<div class="wrap"><h1>Marketing system check</h1>';
        echo '<table class="widefat striped" style="max-width:760px;margin-bottom:24px"><tbody>';
        echo '<tr><td>PHP</td><td>' . esc_html($php_version) . '</td></tr>';
        echo '<tr><td>WordPress</td><td>' . esc_html($wp_version) . '</td></tr>';
        echo '<tr><td>PHP memory_limit</td><td>' . esc_html($memory_limit) . '</td></tr>';
        echo '<tr><td>Peak memory this request</td><td>' . esc_html(size_format(memory_get_peak_usage(true))) . '</td></tr>';
        echo '<tr><td>Total options</td><td>' . esc_html(number_format($total_options)) . ' (' . esc_html(size_format($total_bytes)) . ')</td></tr>';
        echo '<tr><td>Autoloaded options</td><td>' . esc_html(number_format($auto_count)) . ' (' . esc_html(size_format($auto_bytes)) . ')</td></tr>';
        echo '</tbody></table>';
        echo '<h2>Largest options</h2><table class="widefat striped" style="max-width:760px"><thead><tr><th>Option</th><th>Size</th><th>Autoload</th></tr></thead><tbody>';
        foreach ($big as $row) {
            echo '<tr><td>' . esc_html($row->option_name) . '</td><td>' . esc_html(size_format((int) $row->size_bytes)) . '</td><td>' . esc_html($row->autoload) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2 style="margin-top:24px">Options that throw when loaded</h2>';
        if (empty($broken)) {
            echo '<p>None - every option loads cleanly.</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Option</th><th>Error</th></tr></thead><tbody>';
            foreach ($broken as $b) {
                echo '<tr><td>' . esc_html($b['name']) . '</td><td>' . esc_html($b['error']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}

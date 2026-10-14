<?php
/**
 * Site forms: contact, request-a-part/quote, fitment check, return request.
 * Entries become support tickets (Super Admin > Tickets) and are emailed via wp_mail (Brevo SMTP), no footer.
 * Also: cross-links between Shop / Service / Roadside, and the track-order form action fix.
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

function sa_sf_types(): array
{
    return [
        'contact' => ['slug' => 'contact', 'title' => 'Contact us', 'lead' => 'Questions about an order, a part, a booking or roadside help. We reply by email or phone.'],
        'part'    => ['slug' => 'request-a-part', 'title' => 'Request a part or quote', 'lead' => 'Tell us the part and your vehicle. We will find it and send you a price before you pay anything.'],
        'fitment' => ['slug' => 'fitment-check', 'title' => 'Check fitment', 'lead' => 'Not sure a part fits your car? Send us your vehicle details and we will check before you order.'],
        'return'  => ['slug' => 'return-request', 'title' => 'Return or order issue', 'lead' => 'Wrong part, damaged item or something missing? Tell us what happened and we will sort it out. See our returns policy for the details.'],
    ];
}

/* ---------- routes ---------- */
add_action('init', static function (): void {
    foreach (sa_sf_types() as $type => $t) {
        if ($type === 'contact') {
            continue;
        }
        add_rewrite_rule('^' . $t['slug'] . '/?$', 'index.php?sa_sf_view=' . $type, 'top');
    }
});
add_filter('query_vars', static function (array $v): array {
    $v[] = 'sa_sf_view';
    return $v;
});

add_action('template_redirect', static function (): void {
    $view = (string) get_query_var('sa_sf_view');
    $types = sa_sf_types();
    if ($view === '' || !isset($types[$view])) {
        return;
    }
    status_header(200);
    add_filter('pre_get_document_title', static fn() => $types[$view]['title'] . ' | ' . get_bloginfo('name'), 99);
    get_header();
    echo '<main id="main" class="sa-svc sa-sf-page"><div class="sa-container sa-sf-narrow">';
    echo '<h1>' . esc_html($types[$view]['title']) . '</h1><p class="sa-svc-lead">' . esc_html($types[$view]['lead']) . '</p>';
    echo sa_sf_render($view); // phpcs:ignore
    echo '</div></main>';
    get_footer();
    exit;
});

/* Contact page gets the contact form; returns page gets a link to the return form. */
add_filter('the_content', static function (string $c): string {
    if (!is_main_query() || !in_the_loop()) {
        return $c;
    }
    if (is_page('contact')) {
        return $c . '<div class="sa-sf-embed"><h2>Send us a message</h2>' . sa_sf_render('contact') . '</div>';
    }
    if (is_page('returns')) {
        return $c . '<p class="sa-sf-cta"><a class="sa-btn" href="' . esc_url(home_url('/return-request/')) . '">Start a return or report an order issue</a></p>';
    }
    return $c;
}, 20);

add_shortcode('sa_form', static fn($a): string => sa_sf_render(sanitize_key((string) (shortcode_atts(['type' => 'contact'], (array) $a)['type']))));

/* ---------- render ---------- */
function sa_sf_render(string $type): string
{
    if (!isset(sa_sf_types()[$type])) {
        return '';
    }
    wp_enqueue_style('sa-services', SA_CORE_URL . 'assets/services.css', [], SA_CORE_VERSION);
    $u = wp_get_current_user();
    $name = $u->ID ? $u->display_name : '';
    $email = $u->ID ? $u->user_email : '';
    $phone = $u->ID ? (string) get_user_meta($u->ID, 'billing_phone', true) : '';
    $veh = ($u->ID && function_exists('sa_svc_vehicles')) ? sa_svc_vehicles($u->ID) : [];
    $v0 = $veh[0] ?? ['make' => '', 'model' => '', 'year' => '', 'reg' => ''];
    $pid = absint($_GET['product'] ?? 0);
    $pname = $pid && get_post_type($pid) === 'product' ? get_the_title($pid) : '';
    $order = sanitize_text_field((string) ($_GET['order'] ?? ''));
    $state = sanitize_key((string) ($_GET['sa_form'] ?? ''));
    $f = static fn(string $n, string $label, string $val = '', string $type = 'text', bool $req = false, string $ph = ''): string =>
        '<label>' . esc_html($label) . ($req ? ' *' : '') . '<input type="' . esc_attr($type) . '" name="' . esc_attr($n) . '" value="' . esc_attr($val) . '"' . ($req ? ' required' : '') . ($ph !== '' ? ' placeholder="' . esc_attr($ph) . '"' : '') . '></label>';
    $sel = static function (string $n, string $label, array $opts, bool $req = true): string {
        $o = '<option value="">Choose…</option>';
        foreach ($opts as $x) {
            $o .= '<option>' . esc_html($x) . '</option>';
        }
        return '<label>' . esc_html($label) . ($req ? ' *' : '') . '<select name="' . esc_attr($n) . '"' . ($req ? ' required' : '') . '>' . $o . '</select></label>';
    };
    ob_start();
    echo '<div class="sa-sf" id="sa-form">';
    if ($state === 'sent') {
        $ref = sanitize_text_field((string) ($_GET['ref'] ?? ''));
        echo '<div class="sa-sf-ok" role="status"><strong>Thanks, we have it' . ($ref ? ' (ref ' . esc_html($ref) . ')' : '') . '.</strong> A copy is in your inbox and our team will reply soon.</div>';
    } elseif ($state === 'error') {
        echo '<div class="sa-svc-alert" role="alert">Please fill in the required fields with a valid email and try again.</div>';
    } elseif ($state === 'slow') {
        echo '<div class="sa-svc-alert" role="alert">You have sent several messages in a short time. Please wait a few minutes and try again.</div>';
    }
    echo '<form class="sa-svc-form sa-sf-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="sa_site_form"><input type="hidden" name="sa_type" value="' . esc_attr($type) . '">';
    wp_nonce_field('sa_site_form', 'sa_sf_nonce');
    echo '<input type="hidden" name="sa_back" value="' . esc_attr(home_url(add_query_arg([]))) . '">';
    echo '<p class="sa-sf-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>';
    echo '<div class="sa-sf-grid">' . $f('name', 'Your name', $name, 'text', true) . $f('email', 'Email', $email, 'email', true) . $f('phone', 'Phone (optional)', $phone, 'tel') . '</div>';
    if ($type === 'contact') {
        echo $sel('topic', 'Topic', ['An order', 'A part', 'A service booking', 'Roadside help', 'Something else']);
    }
    if ($type === 'return') {
        echo '<div class="sa-sf-grid">' . $f('order', 'Order number', $order, 'text', true, 'e.g. 26488') . $f('item', 'Which item?', '', 'text', true) . '</div>';
        echo $sel('reason', 'What happened?', ['Wrong part / does not fit', 'Arrived damaged', 'Item missing', 'No longer needed', 'Something else']);
    }
    if ($type === 'part' || $type === 'fitment') {
        echo $f('part', $type === 'part' ? 'Part name or part number' : 'Part you want to check', $pname, 'text', true, 'e.g. Front brake pads, 04465-0K290');
        if ($pid) {
            echo '<input type="hidden" name="product_id" value="' . (int) $pid . '">';
        }
        if (count($veh) > 1) {
            echo '<label>Use a saved vehicle<select data-sa-sf-veh>';
            foreach ($veh as $v) {
                echo '<option data-make="' . esc_attr($v['make']) . '" data-model="' . esc_attr($v['model'] ?? '') . '" data-year="' . esc_attr($v['year'] ?? '') . '" data-reg="' . esc_attr($v['reg'] ?? '') . '">' . esc_html(trim(($v['year'] ?? '') . ' ' . $v['make'] . ' ' . ($v['model'] ?? '') . ' ' . ($v['reg'] ?? ''))) . '</option>';
            }
            echo '</select></label>';
        }
        echo '<div class="sa-sf-grid">' . $f('make', 'Make', (string) $v0['make'], 'text', true, 'e.g. Toyota') . $f('model', 'Model', (string) ($v0['model'] ?? ''), 'text', true, 'e.g. Premio') . $f('year', 'Year', (string) ($v0['year'] ?? ''), 'text', false, 'e.g. 2012') . '</div>';
        echo '<div class="sa-sf-grid">' . $f('engine', 'Engine (optional)', '', 'text', false, 'e.g. 1NZ-FE 1.5L') . $f('vin', 'Chassis / VIN (optional)', '', 'text', false, 'Best for an exact match') . $f('reg', 'Reg. plate (optional)', (string) ($v0['reg'] ?? '')) . '</div>';
    }
    $ml = ['contact' => 'Message', 'part' => 'Anything else? (optional)', 'fitment' => 'Anything else? (optional)', 'return' => 'Tell us more'];
    echo '<label>' . esc_html($ml[$type]) . (in_array($type, ['contact', 'return'], true) ? ' *' : '') . '<textarea name="message" rows="5"' . (in_array($type, ['contact', 'return'], true) ? ' required' : '') . '></textarea></label>';
    echo '<button type="submit" class="sa-svc-btn sa-svc-btn--primary">Send</button>';
    echo '<p class="sa-sf-note">No account needed. We only use your details to answer this request.</p>';
    echo '</form></div>';
    if (count($veh) > 1) {
        echo '<script>document.querySelectorAll("[data-sa-sf-veh]").forEach(function(s){s.addEventListener("change",function(){var o=s.options[s.selectedIndex],f=s.form;["make","model","year","reg"].forEach(function(k){if(f[k])f[k].value=o.dataset[k]||"";});});});</script>';
    }
    return (string) ob_get_clean();
}

/* ---------- submit ---------- */
add_action('admin_post_sa_site_form', 'sa_sf_handle');
add_action('admin_post_nopriv_sa_site_form', 'sa_sf_handle');

function sa_sf_handle(): void
{
    $back = esc_url_raw(wp_unslash((string) ($_POST['sa_back'] ?? '')));
    if ($back === '' || wp_parse_url($back, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) {
        $back = home_url('/contact/');
    }
    $back = remove_query_arg(['sa_form', 'ref'], $back);
    $go = static function (string $state, string $ref = '') use ($back): void {
        wp_safe_redirect(add_query_arg(array_filter(['sa_form' => $state, 'ref' => $ref]), $back) . '#sa-form');
        exit;
    };
    $type = sanitize_key((string) ($_POST['sa_type'] ?? ''));
    $types = sa_sf_types();
    if (!isset($types[$type]) || !wp_verify_nonce((string) ($_POST['sa_sf_nonce'] ?? ''), 'sa_site_form')) {
        $go('error');
    }
    if (trim((string) ($_POST['website'] ?? '')) !== '') { // honeypot: pretend success
        $go('sent');
    }
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
    $rk = 'sa_sf_rl_' . md5((string) $ip);
    $n = (int) get_transient($rk);
    if ($n >= 6) {
        $go('slow');
    }
    set_transient($rk, $n + 1, 10 * MINUTE_IN_SECONDS);

    $g = static fn(string $k, int $max = 200): string => mb_substr(sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? ''))), 0, $max);
    $d = [
        'name' => $g('name', 120), 'email' => sanitize_email(wp_unslash((string) ($_POST['email'] ?? ''))), 'phone' => $g('phone', 40),
        'topic' => $g('topic'), 'order' => $g('order', 40), 'item' => $g('item'), 'reason' => $g('reason'),
        'part' => $g('part'), 'make' => $g('make', 60), 'model' => $g('model', 60), 'year' => $g('year', 10),
        'engine' => $g('engine', 80), 'vin' => $g('vin', 40), 'reg' => $g('reg', 20),
        'message' => mb_substr(sanitize_textarea_field(wp_unslash((string) ($_POST['message'] ?? ''))), 0, 4000),
    ];
    $pid = absint($_POST['product_id'] ?? 0);
    $req = ['name', 'email'];
    $req = array_merge($req, ['contact' => ['topic', 'message'], 'part' => ['part', 'make', 'model'], 'fitment' => ['part', 'make', 'model'], 'return' => ['order', 'item', 'reason', 'message']][$type]);
    foreach ($req as $k) {
        if ($d[$k] === '') {
            $go('error');
        }
    }
    if (!is_email($d['email'])) {
        $go('error');
    }

    $labels = ['topic' => 'Topic', 'order' => 'Order', 'item' => 'Item', 'reason' => 'Reason', 'part' => 'Part', 'make' => 'Make', 'model' => 'Model', 'year' => 'Year', 'engine' => 'Engine', 'vin' => 'Chassis/VIN', 'reg' => 'Reg. plate', 'phone' => 'Phone', 'message' => 'Message'];
    $rows = [];
    foreach ($labels as $k => $l) {
        if ($d[$k] !== '') {
            $rows[$l] = $d[$k];
        }
    }
    if ($pid && get_post_type($pid) === 'product') {
        $rows['Product link'] = get_permalink($pid);
    }
    if ($type === 'return' && function_exists('wc_get_order')) {
        $o = wc_get_order(absint(preg_replace('/\D/', '', $d['order'])));
        $rows['Order check'] = ($o && strtolower($o->get_billing_email()) === strtolower($d['email'])) ? 'Order found, email matches' : 'Not matched to an order with this email, please verify';
    }
    $plain = '';
    foreach ($rows as $l => $v) {
        $plain .= $l . ': ' . $v . "\n";
    }

    $tid = function_exists('sa_core_ticket_create') ? sa_core_ticket_create([
        'email' => $d['email'], 'name' => $d['name'], 'subject' => $types[$type]['title'] . ' — ' . ($d['part'] ?: ($d['order'] ? 'Order ' . $d['order'] : $d['name'])),
        'body' => $plain, 'source' => 'form_' . $type, 'user_id' => get_current_user_id(),
    ]) : 0;
    $ref = $tid ? 'T' . $tid : strtoupper(wp_generate_password(6, false));

    // Shared vehicle details: remember the car on the customer's profile and garage record.
    if (in_array($type, ['part', 'fitment'], true) && $d['make'] !== '') {
        $uid = get_current_user_id();
        if (!$uid) {
            $uu = get_user_by('email', $d['email']);
            $uid = $uu ? (int) $uu->ID : 0;
        }
        if ($uid && function_exists('sa_svc_save_vehicle')) {
            sa_svc_save_vehicle($uid, ['make' => $d['make'], 'model' => $d['model'], 'year' => $d['year'], 'reg' => $d['reg']]);
        }
    }

    $table = '<table role="presentation" width="100%" style="border-collapse:collapse;font-size:14px;margin:8px 0">';
    foreach ($rows as $l => $v) {
        $table .= '<tr><td style="padding:6px 8px 6px 0;color:#666;vertical-align:top;white-space:nowrap">' . esc_html($l) . '</td><td style="padding:6px 0">' . nl2br(esc_html($v)) . '</td></tr>';
    }
    $table .= '</table>';
    $to = function_exists('sa_core_store_email') && is_email(sa_core_store_email()) ? sa_core_store_email() : (string) get_option('admin_email');
    $html = ['Content-Type: text/html; charset=UTF-8'];
    $layout = static fn(string $h, string $inner, string $cl = '', string $cu = ''): string => function_exists('sa_mail_layout') ? sa_mail_layout($h, $inner, $cl, $cu) : '<h1>' . esc_html($h) . '</h1>' . $inner;
    wp_mail($to, '[' . $ref . '] ' . $types[$type]['title'] . ' from ' . $d['name'], $layout($types[$type]['title'], '<p><strong>' . esc_html($d['name']) . '</strong> &lt;' . esc_html($d['email']) . '&gt;</p>' . $table, 'Open tickets', admin_url('admin.php?page=sa-super-tickets' . ($tid ? '&ticket=' . $tid : ''))), array_merge($html, ['Reply-To: ' . $d['name'] . ' <' . $d['email'] . '>']));
    wp_mail($d['email'], 'We received your request (' . $ref . ')', $layout('Thanks, ' . $d['name'], '<p>We received your ' . esc_html(['contact' => 'message', 'part' => 'part request', 'fitment' => 'fitment check', 'return' => 'return / order issue'][$type]) . ' and will reply soon. Your reference is <strong>' . esc_html($ref) . '</strong>.</p>' . $table, 'Visit Supreme Autoparts', home_url('/')), $html);
    $go('sent', $ref);
}

/* ---------- cross-links between the three services ---------- */
add_action('woocommerce_single_product_summary', static function (): void {
    global $product;
    if (!$product instanceof WC_Product) {
        return;
    }
    $id = $product->get_id();
    echo '<div class="sa-xlinks">';
    if ($product->get_price() === '' || !$product->is_purchasable()) {
        echo '<a class="sa-xlink sa-xlink--strong" href="' . esc_url(add_query_arg('product', $id, home_url('/request-a-part/'))) . '">Request a price for this part</a>';
    }
    echo '<a class="sa-xlink" href="' . esc_url(add_query_arg('product', $id, home_url('/fitment-check/'))) . '">Not sure it fits? Check fitment</a>';
    echo '<a class="sa-xlink" href="' . esc_url(home_url('/roadside/')) . '">Car trouble? Get roadside help</a>';
    echo '</div>';
}, 38);

function sa_sf_next_steps(string $ctx): string
{
    $items = [
        'parts'    => ['Shop parts', 'Order the parts your car needs, by part number or vehicle.', wc_get_page_permalink('shop')],
        'service'  => ['Book a service', 'Get it checked and fixed at our garage.', home_url('/book-service/')],
        'roadside' => ['Roadside Rescue', 'Towing, jump-starts and flat tyres when you need them.', home_url('/roadside/')],
        'fitment'  => ['Check fitment', 'Send your vehicle details and we confirm the right part.', home_url('/fitment-check/')],
    ];
    $pick = ['roadside' => ['service', 'parts'], 'booking' => ['parts', 'roadside'], 'order' => ['service', 'roadside', 'fitment']][$ctx] ?? [];
    if (!$pick) {
        return '';
    }
    $h = '<section class="sa-container sa-next" aria-label="What next"><h2>What next?</h2><div class="sa-next__grid">';
    foreach ($pick as $k) {
        [$t, $d, $u] = $items[$k];
        $h .= '<a class="sa-next__card" href="' . esc_url($u) . '"><strong>' . esc_html($t) . '</strong><span>' . esc_html($d) . '</span></a>';
    }
    return $h . '</div></section>';
}

add_action('get_footer', static function (): void {
    $rs = (string) get_query_var('sa_rs_view');
    $svc = (string) get_query_var('sa_svc_view');
    if ($rs === 'status') {
        echo sa_sf_next_steps('roadside'); // phpcs:ignore
    } elseif ($svc === 'confirmed') {
        echo sa_sf_next_steps('booking'); // phpcs:ignore
    } elseif ($svc !== '') {
        echo '<p class="sa-container sa-rsline">Car won’t start or can’t be driven? <a href="' . esc_url(home_url('/roadside/')) . '">Request roadside help</a> or call <a href="tel:+254714498451">+254 714 498 451</a>.</p>'; // phpcs:ignore
    }
});
add_action('woocommerce_thankyou', static function (): void {
    echo sa_sf_next_steps('order'); // phpcs:ignore
}, 30);

/* Woo's order-tracking form posts to the global post (was /hello-world/); point it at /track-order/. */
add_filter('do_shortcode_tag', static function ($out, $tag) {
    if ($tag === 'woocommerce_order_tracking' && is_string($out)) {
        $out = preg_replace('#(<form[^>]*action=")[^"]*(")#', '$1' . esc_url(home_url('/track-order/')) . '$2', $out, 1);
    }
    return $out;
}, 10, 2);

add_action('wp_head', static function (): void {
    echo '<style id="sa-sf-css">.sa-sf-narrow{max-width:760px;padding-top:24px;padding-bottom:40px}.sa-sf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}.sa-sf-hp{position:absolute!important;left:-9999px;width:1px;height:1px;overflow:hidden}.sa-sf-ok{background:#ecfdf3;border:1px solid #a7f3c4;color:#065f46;border-radius:12px;padding:14px 16px;margin:12px 0}.sa-sf-note{font-size:13px;color:#6b7280;margin:0}.sa-sf-embed{margin-top:28px;max-width:760px}.sa-sf-embed .sa-svc-form label,.sa-sf .sa-svc-form label{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:600;color:#333333}.sa-xlinks{display:flex;flex-direction:column;gap:6px;margin:12px 0 4px}.sa-xlink{font-size:14px;font-weight:600;color:#0a0a0a;text-decoration:none}.sa-xlink:hover{text-decoration:underline}.sa-xlink--strong{display:inline-block;background:#F5A623;color:#111111;border-radius:10px;padding:10px 14px;align-self:flex-start}.sa-next{margin:28px auto}.sa-next h2{font-size:20px;margin:0 0 12px}.sa-next__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}.sa-next__card{display:flex;flex-direction:column;gap:4px;padding:16px;border:1px solid #e4e3de;border-radius:14px;background:#fff;color:#111111;text-decoration:none!important;transition:border-color .15s,transform .15s}.sa-next__card:hover{border-color:#0a0a0a;transform:translateY(-2px)}.sa-next__card span{color:#555;font-size:14px}</style>';
});

/* Hero search suggestions with real (CDN) product images. */
function sa_sf_suggest(): void
{
    $q = trim(sanitize_text_field(wp_unslash((string) ($_GET['q'] ?? ''))));
    if (mb_strlen($q) < 2) {
        wp_send_json_success([]);
    }
    $ck = 'sa_sugg_' . md5(strtolower($q));
    $out = get_transient($ck);
    if (!is_array($out)) {
        $ids = (new WP_Query(['post_type' => 'product', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 6, 'fields' => 'ids', 'no_found_rows' => true]))->posts;
        $out = [];
        foreach ($ids as $id) {
            $p = wc_get_product($id);
            if (!$p || !$p->is_visible()) {
                continue;
            }
            $img = function_exists('sa_core_get_product_email_image_url') ? sa_core_get_product_email_image_url($p, 96) : '';
            $out[] = ['name' => html_entity_decode($p->get_name(), ENT_QUOTES, 'UTF-8'), 'permalink' => get_permalink($id), 'images' => $img !== '' ? [['thumbnail' => $img]] : []];
        }
        set_transient($ck, $out, 10 * MINUTE_IN_SECONDS);
    }
    wp_send_json_success($out);
}
add_action('wp_ajax_sa_suggest', 'sa_sf_suggest');
add_action('wp_ajax_nopriv_sa_suggest', 'sa_sf_suggest');

/* One-line roadside mention in customer order emails. */
add_action('woocommerce_email_after_order_table', static function ($order, $sent_to_admin, $plain_text = false): void {
    if ($sent_to_admin) {
        return;
    }
    if ($plain_text) {
        echo "\nCar trouble? Roadside help: " . esc_url_raw(home_url('/roadside/')) . " or call +254 714 498 451\n";
        return;
    }
    echo '<p style="margin:16px 0 0;font-size:13px;color:#555">Car trouble? <a href="' . esc_url(home_url('/roadside/')) . '" style="color:#111">Request roadside help</a> or call <a href="tel:+254714498451" style="color:#111">+254 714 498 451</a>.</p>';
}, 40, 3);

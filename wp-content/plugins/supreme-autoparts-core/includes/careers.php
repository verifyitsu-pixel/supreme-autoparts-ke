<?php
/**
 * Careers page: open roles at the Supreme Autoparts garage (Njiru, Nairobi) + application form.
 * Applications become support tickets (Super Admin > Tickets) and are emailed via wp_mail (Brevo SMTP).
 * Page body lives in page-content.php ('careers'); this module renders [sa_careers].
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Open roles. Copy rule: no pay figures or benefits unless the owner states them.
 *
 * @return array<string, array{title:string, blurb:string, duties:string[]}>
 */
function sa_careers_roles(): array
{
    return [
        'delivery-rider' => [
            'title' => 'Delivery Rider',
            'blurb' => 'Get parts orders to our customers across Nairobi, on time and in good order.',
            'duties' => [
                'Deliver customer orders across Nairobi and confirm each handover',
                'Return signed delivery notes and order paperwork to the office',
                'Plan routes so deliveries arrive on time',
                'Support the garage with supplier pickups and errands',
            ],
        ],
        'mazda-specialist' => [
            'title' => 'Mazda Specialist',
            'blurb' => 'Service and repair technician focused on Mazda vehicles.',
            'duties' => [
                'Diagnose, service and repair Mazda models',
                'Work across engines, suspension, brakes and electrics',
                'Advise on the correct genuine and aftermarket parts for each job',
                'Road-test and quality-check completed work',
            ],
        ],
        'custom-mods-technician' => [
            'title' => 'Custom Mods Technician',
            'blurb' => 'Fit performance and custom parts — exhausts, suspension, wheels, styling and more.',
            'duties' => [
                'Install performance and aftermarket parts: exhausts, suspension, wheels and body work',
                'Plan custom builds with customers from idea to finished car',
                'Fit and finish custom work neatly and safely',
                'Keep fitment and workmanship to a high standard',
            ],
        ],
        'online-orders-coordinator' => [
            'title' => 'Online Orders & Receipts Coordinator',
            'blurb' => 'Keep our online orders, receipts and customers in order.',
            'duties' => [
                'Process online orders and issue receipts and invoices',
                'Coordinate dispatch with couriers and the delivery rider',
                'Update customers on their order status',
                'Keep order and payment records accurate',
            ],
        ],
    ];
}

/** Location line shared by the page and the emails. */
function sa_careers_location(): string
{
    return 'Midax Plaza, Kangundo Rd, Njiru, Nairobi';
}

/* ---------- render ---------- */
add_shortcode('sa_careers', static function (): string {
    wp_enqueue_style('sa-services', SA_CORE_URL . 'assets/services.css', [], SA_CORE_VERSION);
    $roles = sa_careers_roles();
    $state = sanitize_key((string) ($_GET['sa_careers'] ?? ''));
    $want  = sanitize_key((string) ($_GET['role'] ?? ''));

    ob_start();
    echo '<div class="sa-careers" id="sa-careers">';

    echo '<div class="sa-careers__grid">';
    foreach ($roles as $slug => $r) {
        echo '<article class="sa-careers__card">';
        echo '<h2>' . esc_html($r['title']) . '</h2>';
        echo '<p class="sa-careers__blurb">' . esc_html($r['blurb']) . '</p>';
        echo '<ul>';
        foreach ($r['duties'] as $d) {
            echo '<li>' . esc_html($d) . '</li>';
        }
        echo '</ul>';
        echo '<p class="sa-careers__meta">Nairobi · Based at our garage</p>';
        echo '<a class="sa-careers__apply" href="' . esc_url(add_query_arg('role', $slug) . '#sa-careers-apply') . '">Apply for this role</a>';
        echo '</article>';
    }
    echo '</div>';

    echo '<div class="sa-careers__form" id="sa-careers-apply">';
    echo '<h2>Apply now</h2>';
    if ($state === 'sent') {
        $ref = sanitize_text_field((string) ($_GET['ref'] ?? ''));
        echo '<div class="sa-sf-ok" role="status"><strong>Thanks, we have your application' . ($ref ? ' (ref ' . esc_html($ref) . ')' : '') . '.</strong> A copy is in your inbox and we will be in touch.</div>';
    } elseif ($state === 'phone') {
        echo '<div class="sa-svc-alert" role="alert">That phone number does not look right. Use digits with an optional +, for example 0712 345 678. We kept what you typed.</div>';
    } elseif ($state === 'error') {
        echo '<div class="sa-svc-alert" role="alert">Something was missing or the email looks wrong. We kept what you typed, please check and try again.</div>';
    } elseif ($state === 'slow') {
        echo '<div class="sa-svc-alert" role="alert">You have sent several applications in a short time. Please wait a few minutes and try again.</div>';
    }

    // Jotform application form (CV upload lives there); native form below is the automatic fallback.
    echo '<div class="sa-careers__jf" id="sa-jotform-wrap"><script src="https://form.jotform.com/jsform/262897629019169"></script></div>';
    echo '<div id="sa-careers-native" hidden>';
    echo '<form class="sa-svc-form sa-sf-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="sa_careers_apply">';
    wp_nonce_field('sa_careers_apply', 'sa_careers_nonce');
    echo '<input type="hidden" name="sa_back" value="' . esc_attr(home_url(add_query_arg([]))) . '">';
    echo '<p class="sa-sf-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>';
    echo '<div class="sa-sf-grid">';
    echo '<label>Your name *<input type="text" name="name" required></label>';
    echo '<label>Email *<input type="email" name="email" required></label>';
    echo '<label>Phone *<input type="tel" name="phone" required placeholder="e.g. 0712 345 678"></label>';
    echo '</div>';
    echo '<label>Role *<select name="role" required><option value="">Choose…</option>';
    foreach ($roles as $slug => $r) {
        echo '<option value="' . esc_attr($slug) . '"' . ($want === $slug ? ' selected' : '') . '>' . esc_html($r['title']) . '</option>';
    }
    echo '<option value="general"' . ($want === 'general' ? ' selected' : '') . '>General application</option>';
    echo '</select></label>';
    echo '<label>Tell us about your experience *<textarea name="message" rows="5" required placeholder="What you have done, where, and why this role fits you"></textarea></label>';
    echo '<button type="submit" class="sa-svc-btn sa-svc-btn--primary">Send application</button>';
    echo '<p class="sa-sf-note">No account needed. We only use your details to review your application.</p>';
    echo '</form>';
    echo '</div>'; // #sa-careers-native
    echo '<script>' . <<<'SAJFJS'
(function(){var w=document.getElementById('sa-jotform-wrap');if(!w)return;function showNative(){var n=document.getElementById('sa-careers-native');if(n)n.hidden=false;w.style.display='none';}window.addEventListener('load',function(){setTimeout(function(){var f=w.querySelector('iframe');if(!f||f.offsetHeight<80)showNative();},6000);});})();
SAJFJS
        . '</script>';
    echo '</div>'; // .sa-careers__form

    echo '<script>' . <<<'SAJS'
(function(){var f=document.querySelector('#sa-careers-apply form');if(!f)return;var k='sa_fd:'+location.pathname;f.addEventListener('submit',function(){try{var o={};Array.prototype.forEach.call(f.elements,function(e){if(!e.name||e.type==='hidden'||e.name==='website')return;if(e.type==='checkbox'||e.type==='radio'){if(e.checked)(o[e.name]=o[e.name]||[]).push(e.value);}else o[e.name]=[e.value];});sessionStorage.setItem(k,JSON.stringify(o));}catch(x){}});window.addEventListener('load',function(){try{var d=sessionStorage.getItem(k);if(!d||!/[?&]sa_careers=(error|phone|slow)/.test(location.search))return;sessionStorage.removeItem(k);d=JSON.parse(d);Array.prototype.forEach.call(f.elements,function(e){var v=d[e.name];if(!v||e.type==='hidden')return;if(e.type==='checkbox'||e.type==='radio'){if(v.indexOf(e.value)>-1&&!e.checked){e.checked=true;}}else if(e.value===''){e.value=v[0];}});}catch(x){}});})();
SAJS
        . '</script>';
    echo '</div>'; // .sa-careers
    return (string) ob_get_clean();
});

/* Careers page styles, same visual language as the other store forms. */
add_action('wp_head', static function (): void {
    if (!is_page('careers')) {
        return;
    }
    echo '<style id="sa-careers-css">.sa-careers__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin:22px 0 30px}.sa-careers__card{display:flex;flex-direction:column;gap:8px;padding:20px;border:1px solid #e4e3de;border-radius:14px;background:#fff}.sa-careers__card h2{font-size:19px;margin:0;color:#111111}.sa-careers__blurb{color:#555;margin:0;font-size:14px}.sa-careers__card ul{margin:4px 0 0;padding-left:18px;color:#333;font-size:14px;display:grid;gap:5px}.sa-careers__meta{font-size:12px;color:#6b7280;margin:auto 0 0;padding-top:8px}.sa-careers__apply{display:inline-block;background:#F5A623;color:#111111;border-radius:10px;padding:10px 14px;font-weight:600;font-size:14px;text-decoration:none;align-self:flex-start;margin-top:6px}.sa-careers__apply:hover{filter:brightness(.95);color:#111111;text-decoration:none}.sa-careers__form{max-width:760px;border-top:1px solid #e4e3de;padding-top:26px}.sa-careers__form h2{font-size:22px;margin:0 0 14px}.sa-careers .sa-svc-form label{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:600;color:#333333}</style>';
});

/* ---------- submit ---------- */
add_action('admin_post_sa_careers_apply', 'sa_careers_handle');
add_action('admin_post_nopriv_sa_careers_apply', 'sa_careers_handle');

function sa_careers_handle(): void
{
    $back = esc_url_raw(wp_unslash((string) ($_POST['sa_back'] ?? '')));
    if ($back === '' || wp_parse_url($back, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) {
        $back = home_url('/careers/');
    }
    $back = remove_query_arg(['sa_careers', 'ref', 'role'], $back);
    $go = static function (string $state, string $ref = '') use ($back): void {
        wp_safe_redirect(add_query_arg(array_filter(['sa_careers' => $state, 'ref' => $ref]), $back) . '#sa-careers-apply');
        exit;
    };
    if (!wp_verify_nonce((string) ($_POST['sa_careers_nonce'] ?? ''), 'sa_careers_apply')) {
        $go('error');
    }
    if (trim((string) ($_POST['website'] ?? '')) !== '') { // honeypot: pretend success
        $go('sent');
    }
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
    $rk = 'sa_careers_rl_' . md5((string) $ip);
    $n = (int) get_transient($rk);
    if ($n >= 4) {
        $go('slow');
    }
    set_transient($rk, $n + 1, 10 * MINUTE_IN_SECONDS);

    $roles = sa_careers_roles();
    $g = static fn(string $k, int $max = 200): string => mb_substr(sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? ''))), 0, $max);
    $name = $g('name', 120);
    $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
    $phone = $g('phone', 40);
    $role_key = sanitize_key((string) ($_POST['role'] ?? ''));
    $message = mb_substr(sanitize_textarea_field(wp_unslash((string) ($_POST['message'] ?? ''))), 0, 4000);

    if ($name === '' || $phone === '' || $message === '' || !is_email($email)) {
        $go('error');
    }
    if ($role_key !== 'general' && !isset($roles[$role_key])) {
        $go('error');
    }
    if (!preg_match('/^\+?[0-9][0-9 ()\-]{7,18}$/', $phone)) {
        $go('phone');
    }

    $role_title = $role_key === 'general' ? 'General application' : $roles[$role_key]['title'];
    $rows = [
        'Role'       => $role_title,
        'Phone'      => $phone,
        'Experience' => $message,
        'Location'   => sa_careers_location(),
    ];
    $plain = '';
    foreach ($rows as $l => $v) {
        $plain .= $l . ': ' . $v . "\n";
    }

    $tid = function_exists('sa_core_ticket_create') ? sa_core_ticket_create([
        'email' => $email, 'name' => $name, 'subject' => 'Job application — ' . $role_title . ' — ' . $name,
        'body' => $plain, 'source' => 'form_careers', 'user_id' => get_current_user_id(),
    ]) : 0;
    $ref = $tid ? 'T' . $tid : strtoupper(wp_generate_password(6, false));

    $table = '<table role="presentation" width="100%" style="border-collapse:collapse;font-size:14px;margin:8px 0">';
    foreach ($rows as $l => $v) {
        $table .= '<tr><td style="padding:6px 8px 6px 0;color:#666;vertical-align:top;white-space:nowrap">' . esc_html($l) . '</td><td style="padding:6px 0">' . nl2br(esc_html($v)) . '</td></tr>';
    }
    $table .= '</table>';
    $to = function_exists('sa_core_store_email') && is_email(sa_core_store_email()) ? sa_core_store_email() : (string) get_option('admin_email');
    $html = ['Content-Type: text/html; charset=UTF-8'];
    $layout = static fn(string $h, string $inner, string $cl = '', string $cu = ''): string => function_exists('sa_mail_layout') ? sa_mail_layout($h, $inner, $cl, $cu) : '<h1>' . esc_html($h) . '</h1>' . $inner;
    wp_mail($to, '[' . $ref . '] Job application: ' . $role_title . ' — ' . $name, $layout('Job application: ' . $role_title, '<p><strong>' . esc_html($name) . '</strong> &lt;' . esc_html($email) . '&gt;</p>' . $table, 'Open tickets', admin_url('admin.php?page=sa-super-tickets' . ($tid ? '&ticket=' . $tid : ''))), array_merge($html, ['Reply-To: ' . $name . ' <' . $email . '>']));
    wp_mail($email, 'We received your application (' . $ref . ')', $layout('We received your application', '<p>' . sa_email_greeting((string) $name, (string) $email) . '</p><p>Thanks for applying for <strong>' . esc_html($role_title) . '</strong> at Supreme Autoparts. We will review your application and be in touch. Your reference is <strong>' . esc_html($ref) . '</strong>.</p>' . $table, 'Visit Supreme Autoparts', home_url('/')), $html);
    $go('sent', $ref);
}

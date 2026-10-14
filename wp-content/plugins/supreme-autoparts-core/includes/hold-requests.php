<?php
/**
 * Order holds with structured info requests.
 * Admin picks a reason + checklist (uploads, text answers, choices, date, address correction),
 * customer gets a no-login expiring link (/order-help/), uploads are stored privately and only
 * served to shop managers, owner can approve/reject per item, reply, ask for more, release or cancel.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Item catalog: id => [label, type, help, choices?] */
function sa_hr_items(): array
{
    $card = 'Cover all card digits except the last 4 with paper or your finger. Cover the CVV (3 digits on the back). We never need the full number.';
    return [
        'id_front'     => ['Government photo ID (front)', 'upload', 'National ID, passport or driving licence. All corners visible, no glare.'],
        'id_back'      => ['Government photo ID (back)', 'upload', 'Back of the same ID.'],
        'selfie_id'    => ['Selfie holding your ID', 'upload', 'Hold the ID next to your face. Both must be clear.'],
        'card_front'   => ['Photo of the card used (front)', 'upload', $card],
        'card_back'    => ['Photo of the card used (back)', 'upload', 'Cover the CVV (3 digits) completely. Signature strip should be visible.'],
        'pay_proof'    => ['Payment proof (M-Pesa message or bank slip)', 'upload', 'Screenshot or photo showing the amount, date and reference.'],
        'pay_ref'      => ['Payment reference / M-Pesa code', 'text', 'E.g. SJK4XXXXXX.'],
        'addr_proof'   => ['Proof of address', 'upload', 'Utility bill or bank statement from the last 3 months showing your name and address.'],
        'billing_phone'=> ['Confirm your billing phone number', 'text', 'The number linked to the card or M-Pesa used.'],
        'contact'      => ['Confirm best phone and email to reach you', 'text', 'Phone number and email you check often.'],
        'address_fix'  => ['Correct delivery address', 'address', 'Please give the full address the courier should use.'],
        'landmark'     => ['Nearest landmark / directions for the rider', 'text', 'Building name, floor, nearby shop or stage.'],
        'recipient'    => ['Who will receive the parcel', 'text', 'Name and phone number of the person receiving it.'],
        'customs_docs' => ['Customs / import documents', 'upload', 'ID or tax number and any documents your country\'s customs asks for.'],
        'tax_id'       => ['Tax ID for customs (if required)', 'text', 'E.g. KRA PIN, EORI, CPF, or "not required".'],
        'remote_fee'   => ['Approve remote-area delivery charge', 'choice', 'Your area has an extra courier charge. Choose how to continue.', ['Approve the extra charge', 'Switch to store pickup / nearest branch', 'Cancel and refund']],
        'reschedule'   => ['Pick a new delivery date', 'date', 'Choose a day someone will be available.'],
        'redelivery'   => ['How should we deliver again?', 'choice', '', ['Deliver again to the same address', 'Deliver to a different address', 'I will collect at the store / courier branch']],
        'pickup_plan'  => ['When will you collect your order?', 'date', 'Your order is waiting at the store.'],
        'vin'          => ['VIN / chassis number', 'text', 'Found on the logbook or the plate at the bottom of the windscreen.'],
        'logbook'      => ['Logbook photo', 'upload', 'Page showing make, model, year and chassis number.'],
        'vehicle_photo'=> ['Photo of the vehicle', 'upload', 'Full side or front view.'],
        'old_part'     => ['Photo of the old part', 'upload', 'Include any numbers stamped or printed on it.'],
        'part_number'  => ['Part number on the old part', 'text', 'If you can read it.'],
        'stock_choice' => ['The item is out of stock. What would you like?', 'choice', '', ['Wait for new stock', 'Accept a substitute part', 'Refund this item']],
        'quote_approve'=> ['Approve the updated price', 'choice', 'See the message above for the extra cost.', ['I approve the extra cost', 'I do not approve, cancel and refund']],
    ];
}

function sa_hr_reasons(): array
{
    return [
        'fraud'    => ['Suspected fraud', ['id_front', 'id_back', 'card_front', 'card_back', 'selfie_id', 'billing_phone']],
        'payment'  => ['Payment verification', ['pay_proof', 'pay_ref', 'card_front', 'billing_phone']],
        'address'  => ['Shipping / address issue', ['address_fix', 'landmark', 'recipient']],
        'customs'  => ['Customs / import documents (international)', ['customs_docs', 'tax_id', 'id_front']],
        'remote'   => ['Remote-area surcharge approval', ['remote_fee']],
        'delivery' => ['Delivery problem (failed delivery / reschedule)', ['redelivery', 'reschedule', 'recipient']],
        'pickup'   => ['Pickup not collected', ['pickup_plan']],
        'fitment'  => ['Part fitment check', ['vin', 'logbook', 'vehicle_photo', 'old_part', 'part_number']],
        'stock'    => ['Out of stock / backorder', ['stock_choice']],
        'quote'    => ['Price / quote approval', ['quote_approve']],
        'contact'  => ['Customer contact needed', ['contact']],
        'other'    => ['Other', []],
    ];
}

/* ---------- Storage (private) ---------- */

function sa_hr_dir(): string
{
    $key = get_option('sa_hr_dirkey');
    if (!$key) {
        $key = wp_generate_password(24, false);
        update_option('sa_hr_dirkey', $key, false);
    }
    $d = wp_upload_dir(null, false)['basedir'] . '/sa-private-' . $key;
    if (!is_dir($d)) {
        wp_mkdir_p($d);
    }
    if (!file_exists($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($d . '/index.php', '<?php http_response_code(403);');
    }
    return $d;
}

function sa_hr_get(WC_Order $o): array
{
    $r = $o->get_meta('_sa_hold_req');
    return is_array($r) ? $r : [];
}

function sa_hr_open(WC_Order $o): ?array
{
    foreach (array_reverse(sa_hr_get($o), true) as $i => $r) {
        if (($r['state'] ?? '') === 'open') {
            $r['_i'] = $i;
            return $r;
        }
    }
    return null;
}

function sa_hr_token(WC_Order $o, string $rid, int $exp): string
{
    return $exp . '.' . substr(hash_hmac('sha256', $o->get_id() . '|' . $rid . '|' . $exp . '|' . $o->get_order_key(), wp_salt('auth')), 0, 32);
}

function sa_hr_url(WC_Order $o, array $r): string
{
    return add_query_arg(['o' => $o->get_id(), 'r' => $r['id'], 't' => sa_hr_token($o, $r['id'], (int) $r['exp'])], home_url('/order-help/'));
}

/* ---------- Actions ---------- */

/**
 * Create (or extend) the open request. $items: list of ['key'=>..] or custom ['label','type'].
 */
function sa_hr_create(WC_Order $o, string $reason, array $keys, array $custom, string $message, int $days = 14, bool $hold = true): array
{
    $cat = sa_hr_items();
    $reqs = sa_hr_get($o);
    $open = sa_hr_open($o);
    $items = $open['items'] ?? [];
    foreach ($keys as $k) {
        if (isset($cat[$k]) && !isset($items[$k])) {
            $items[$k] = ['label' => $cat[$k][0], 'type' => $cat[$k][1], 'help' => $cat[$k][2], 'choices' => $cat[$k][3] ?? [], 'status' => 'requested', 'answer' => '', 'files' => [], 'why' => ''];
        }
    }
    foreach ($custom as $c) {
        $lab = trim((string) ($c['label'] ?? ''));
        if ($lab === '') {
            continue;
        }
        $type = in_array($c['type'] ?? '', ['upload', 'text', 'date'], true) ? $c['type'] : 'text';
        $items['c' . substr(md5($lab . microtime()), 0, 6)] = ['label' => $lab, 'type' => $type, 'help' => '', 'choices' => [], 'status' => 'requested', 'answer' => '', 'files' => [], 'why' => ''];
    }
    $reasons = sa_hr_reasons();
    $now = time();
    if ($open) {
        $r = $open;
        unset($r['_i']);
        $r['items'] = $items;
        $r['exp'] = max((int) $r['exp'], $now + $days * DAY_IN_SECONDS);
        if ($message !== '') {
            $r['thread'][] = ['t' => $now, 'by' => 'shop', 'msg' => $message];
        }
        $reqs[$open['_i']] = $r;
    } else {
        $r = ['id' => wp_generate_password(10, false), 'reason' => isset($reasons[$reason]) ? $reason : 'other', 'state' => 'open', 'created' => $now, 'exp' => $now + $days * DAY_IN_SECONDS, 'items' => $items, 'thread' => $message !== '' ? [['t' => $now, 'by' => 'shop', 'msg' => $message]] : []];
        $reqs[] = $r;
    }
    $o->update_meta_data('_sa_hold_req', $reqs);
    $o->update_meta_data('_sa_hold_reason', ($reasons[$r['reason']][0] ?? 'Other') . ($message !== '' ? ': ' . $message : ''));
    $o->save();
    $o->add_order_note(sprintf("Info request %s (%s). Items: %s\nMessage: %s\nLink expires %s.", $open ? 'updated' : 'sent', $reasons[$r['reason']][0] ?? 'Other', implode(', ', array_column($items, 'label')) ?: 'none', $message ?: '-', wp_date('j M Y', (int) $r['exp'])));
    if ($hold && $o->get_status() !== 'on-hold') {
        // Suppress the generic lifecycle on-hold email; ours is more specific.
        $o->update_meta_data('_sa_hr_silent', 1);
        $o->save();
        $o->update_status('on-hold', 'Put on hold: ' . ($reasons[$r['reason']][0] ?? 'Other') . '.');
        $o->delete_meta_data('_sa_hr_silent');
        $o->save();
    }
    sa_hr_mail_customer($o, $r, $open ? 'more' : 'new', $message);
    return $r;
}

function sa_hr_update(WC_Order $o, array $r): void
{
    $reqs = sa_hr_get($o);
    foreach ($reqs as $i => $x) {
        if ($x['id'] === $r['id']) {
            unset($r['_i']);
            $reqs[$i] = $r;
        }
    }
    $o->update_meta_data('_sa_hold_req', $reqs);
    $o->save();
}

function sa_hr_mail_customer(WC_Order $o, array $r, string $kind, string $message = ''): bool
{
    $to = (string) $o->get_billing_email();
    if (!is_email($to)) {
        return false;
    }
    $m = sa_hr_mail_build($o, $r, $kind, $message);
    $ok = (bool) wp_mail($to, $m['subject'], $m['html'], ['Content-Type: text/html; charset=UTF-8']);
    $o->add_order_note('Customer email sent: info request (' . $kind . ')' . ($ok ? '' : ' (send failed)'));
    return $ok;
}

/** Pending items html (email) with why + how per item. */
function sa_hr_items_email(array $r): string
{
    $g = sa_hr_item_guide();
    $F = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $h = '';
    $i = 0;
    foreach ($r['items'] as $k => $it) {
        if (!in_array($it['status'], ['requested', 'rejected'], true)) {
            continue;
        }
        $i++;
        [$why, $how] = $g[$k] ?? ['', (string) $it['help']];
        $h .= '<tr><td style="width:30px;vertical-align:top;padding:12px 0 12px"><div style="width:24px;height:24px;line-height:24px;border-radius:12px;background:#F5A623;color:#111;font-family:' . $F . ';font-size:13px;font-weight:700;text-align:center">' . $i . '</div></td>'
            . '<td style="vertical-align:top;padding:12px 0;border-bottom:1px solid #eeeeee;font-family:' . $F . ';font-size:14px;line-height:1.5;color:#333">'
            . '<div style="font-weight:700;color:#111;font-size:15px">' . esc_html($it['label']) . '</div>'
            . ($it['status'] === 'rejected' && $it['why'] ? '<div style="color:#8a4b00;background:#fff3dc;padding:4px 8px;border-radius:4px;margin:4px 0;display:inline-block">Please send again: ' . esc_html($it['why']) . '</div>' : '')
            . ($why ? '<div style="color:#555"><strong style="color:#111">Why:</strong> ' . esc_html($why) . '</div>' : '')
            . ($how ? '<div style="color:#555"><strong style="color:#111">How:</strong> ' . esc_html($how) . '</div>' : '')
            . '</td></tr>';
    }
    return $h ? '<div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6b6b6b;font-weight:600;margin:20px 0 0">What to send (' . $i . ')</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $h . '</table>' : '';
}

function sa_hr_mail_build(WC_Order $o, array $r, string $kind, string $message = ''): array
{
    $build = static function () use ($o, $r, $kind, $message): array {
    $GLOBALS['sa_sending_order_email'] = 1;
    try {
    $n = $o->get_order_number();
    $pay = sa_hr_pay($o);
    $c = sa_hr_copy((string) $r['reason']);
    $fill = static fn(string $s): string => sa_hr_fill($s, $o, $pay);
    $url = sa_hr_url($o, $r);
    $exp = wp_date('l, j F Y', (int) $r['exp']);
    $uploads = (bool) array_filter($r['items'], static fn($it) => $it['type'] === 'upload');
    $hi = '<p style="margin:0 0 12px">' . sa_email_greeting($o) . '</p>';
    $msg = $message !== '' ? '<div style="border-left:3px solid #F5A623;background:#fafafa;padding:10px 14px;margin:14px 0"><div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6b6b6b;font-weight:600">Message from our team</div>' . nl2br(esc_html($message)) . '</div>' : '';
    $verify = '<div style="border:1px solid #111;border-radius:8px;padding:12px 14px;margin:14px 0"><div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6b6b6b;font-weight:600">What we need to confirm</div><div style="color:#111;font-weight:600">We need to confirm ' . esc_html($fill($c['verify'])) . '</div></div>';
    $next = '<div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6b6b6b;font-weight:600;margin:20px 0 4px">What happens next</div>'
        . '<p style="margin:0 0 6px">Once you send this, we review it ' . esc_html($c['review']) . ' and email you as soon as your order is moving again. Nothing more is charged.</p>'
        . '<p style="margin:0 0 6px"><strong style="color:#111">Please reply by ' . esc_html($exp) . '.</strong> If we don\'t hear from you by then, your order may be cancelled and refunded in full to your original payment method.</p>';
    $trust = sa_hr_trust_html($o, $uploads);
    $sum = sa_hr_summary_html($o);
    $cta = sa_hr_btn($fill($c['cta']), $url);
    $sec = '<p style="font-size:13px;color:#555;margin:0 0 4px">Takes about 2 minutes on your phone. No login needed.</p>';
    switch ($kind) {
        case 'new':
            $subject = $fill($c['subject']);
            $pre = $fill($c['pre']);
            $head = $c['head'];
            $inner = $hi . '<p style="margin:0 0 12px">' . esc_html($fill($c['reason'])) . '</p>' . $verify . $msg . sa_hr_items_email($r) . $cta . $sec . $next . $sum . $trust;
            break;
        case 'reminder':
            $left = max(1, (int) ceil(((int) $r['exp'] - time()) / DAY_IN_SECONDS));
            $subject = 'Reminder: order #' . $n . ' is still waiting for you';
            $pre = 'Your order is on hold. Please reply by ' . $exp . ' to keep it.';
            $head = $left <= 1 ? 'Last day to keep your order' : 'Your order is still on hold';
            $inner = $hi . '<p style="margin:0 0 12px">We\'re still holding order #' . esc_html($n) . ' for you, but we haven\'t received what we need yet. ' . esc_html($fill($c['reason'])) . '</p>' . $verify . sa_hr_items_email($r) . $cta . $sec . $next . $sum . $trust;
            break;
        case 'more':
            $rej = (bool) array_filter($r['items'], static fn($it) => $it['status'] === 'rejected');
            $subject = $rej ? 'Order #' . $n . ': please resend one item' : 'Order #' . $n . ': one more detail needed';
            $pre = $rej ? 'Thanks for sending your details. One item needs a clearer copy.' : 'Thanks for your reply. We need one more thing to finish.';
            $head = $rej ? 'Almost there, one item to resend' : 'Thanks, just one more thing';
            $inner = $hi . '<p style="margin:0 0 12px">Thank you for what you\'ve sent so far. ' . ($rej ? 'We couldn\'t accept one item, usually because part of it was hard to read. Everything else is fine and you don\'t need to send it again.' : 'To finish our review we need the item below.') . '</p>' . $msg . sa_hr_items_email($r) . sa_hr_btn($rej ? 'Resend now' : $fill($c['cta']), $url) . $sec . $next . $sum . $trust;
            break;
        case 'released':
            $subject = 'Thanks, order #' . $n . ' is moving again';
            $pre = 'Everything checks out. Your order is back in progress.';
            $head = 'Thanks, your order is moving again';
            $inner = $hi . '<p style="margin:0 0 12px">Everything checks out. We\'ve released the hold on order #' . esc_html($n) . ' and it\'s back in progress. We\'ll email you when it ships or is ready to collect.</p>'
                . ($uploads ? '<p style="margin:0 0 12px;color:#555">The files you sent will be deleted from our systems within 30 days.</p>' : '') . $msg . $sum . sa_hr_btn('View my order', function_exists('sa_ol_view_url') ? sa_ol_view_url($o) : home_url('/my-account/')) . sa_hr_trust_html($o, false);
            break;
        case 'cancelled':
            $subject = 'Order #' . $n . ' has been cancelled';
            $pre = 'Any payment will be refunded in full to your original payment method.';
            $head = 'Your order has been cancelled';
            $inner = $hi . '<p style="margin:0 0 12px">We weren\'t able to complete the checks for order #' . esc_html($n) . ', so it has been cancelled. If you paid, the full amount of ' . esc_html($pay['amount']) . ' is refunded to your original payment method. Depending on your bank, it can take 5 to 10 business days to appear.</p>'
                . $msg . '<p style="margin:0 0 12px">If you think this is a mistake, reply to this email and we\'ll gladly look again.</p>' . $sum . sa_hr_trust_html($o, false);
            break;
        default:
            $subject = 'Order #' . $n . ': new message from our team';
            $pre = mb_substr(preg_replace('/\s+/', ' ', $message), 0, 110);
            $head = 'New message about your order';
            $pend = (bool) array_filter($r['items'], static fn($it) => in_array($it['status'], ['requested', 'rejected'], true));
            $inner = $hi . $msg . ($pend ? sa_hr_items_email($r) : '') . sa_hr_btn($pend ? $fill($c['cta']) : 'Reply securely', $url) . '<p style="font-size:13px;color:#555;margin:0 0 4px">You can also just reply to this email.</p>' . $sum . sa_hr_trust_html($o, $uploads);
    }
    return ['subject' => $subject, 'pre' => $pre, 'html' => sa_hr_mail_wrap($head, $inner, $pre)];
    } finally {
        unset($GLOBALS['sa_sending_order_email']);
    }
    };
    return function_exists('sa_geo_without_conversion')
        ? sa_geo_without_conversion($build)
        : $build();
}

/* ---------- Automatic reminders (~48h and ~24h before expiry) ---------- */

add_action('init', static function (): void {
    if (!wp_next_scheduled('sa_hr_remind')) {
        wp_schedule_event(time() + 600, 'hourly', 'sa_hr_remind');
    }
});
add_action('sa_hr_remind', 'sa_hr_remind_run');
function sa_hr_remind_run(): int
{
    if (get_option('sa_hr_reminders', '1') !== '1') {
        return 0;
    }
    $sent = 0;
    $ids = wc_get_orders(['limit' => 200, 'status' => ['on-hold', 'processing', 'pending'], 'return' => 'ids', 'meta_key' => '_sa_hold_req', 'meta_compare' => 'EXISTS']);
    foreach ($ids as $id) {
        $o = wc_get_order($id);
        $r = $o ? sa_hr_open($o) : null;
        if (!$r) {
            continue;
        }
        $left = (int) $r['exp'] - time();
        $pending = array_filter($r['items'], static fn($it) => in_array($it['status'], ['requested', 'rejected'], true));
        $answered = (bool) array_filter($r['items'], static fn($it) => $it['status'] === 'received');
        if (!$pending || $answered || $left <= 0) {
            continue;
        }
        $done = (array) ($r['reminded'] ?? []);
        foreach ([48, 24] as $hrs) {
            if ($left <= $hrs * HOUR_IN_SECONDS && $left > ($hrs - 24) * HOUR_IN_SECONDS && !in_array($hrs, $done, true) && (int) $r['created'] < time() - DAY_IN_SECONDS) {
                $r['reminded'] = array_merge($done, [$hrs]);
                sa_hr_update($o, $r);
                sa_hr_mail_customer($o, $r, 'reminder');
                $sent++;
                break;
            }
        }
    }
    return $sent;
}

/* ---------- Admin meta box ---------- */

add_action('add_meta_boxes', static function (): void {
    $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
    add_meta_box('sa-hr', 'Hold & info requests', 'sa_hr_metabox', $screen, 'normal', 'high');
});

function sa_hr_file_url(WC_Order $o, string $f): string
{
    return wp_nonce_url(admin_url('admin-post.php?action=sa_hr_file&o=' . $o->get_id() . '&f=' . rawurlencode($f)), 'sa_hr_file_' . $o->get_id());
}

function sa_hr_metabox($post_or_order): void
{
    try {
        $o = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID ?? 0);
        if (!$o instanceof WC_Order) {
            return;
        }
        sa_hr_metabox_inner($o);
    } catch (\Throwable $e) {
        echo '<p>Could not load info requests.</p>';
        error_log('[sa_hr] metabox: ' . $e->getMessage());
    }
}

function sa_hr_metabox_inner(WC_Order $o): void
{
    $reasons = sa_hr_reasons();
    $cat = sa_hr_items();
    $open = sa_hr_open($o);
    wp_nonce_field('sa_hr_admin', 'sa_hr_nonce');
    echo '<style>
#sa-hr .sa-hr-b{display:inline-block;background:#0a0a0a;color:#fff;border:0;border-radius:6px;padding:9px 14px;font-weight:600;cursor:pointer;margin:3px 4px 3px 0;font-size:13px;line-height:1.2}
#sa-hr .sa-hr-b.alt{background:#fff;color:#0a0a0a;border:1px solid #0a0a0a}#sa-hr .sa-hr-b.amber{background:#F5A623;color:#0a0a0a}
#sa-hr .sa-hr-b.warn{background:#fff;color:#a12;border:1px solid #a12}
#sa-hr .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:4px 14px;margin:8px 0}
#sa-hr label.ck{display:flex;gap:6px;align-items:flex-start;font-size:13px}
#sa-hr .it{border:1px solid #e4e4e4;border-radius:8px;padding:10px 12px;margin:8px 0;background:#fff}
#sa-hr .st{display:inline-block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:2px 7px;border-radius:4px;background:#eee;color:#333}
#sa-hr .st.received{background:#fff3dc;color:#7a4b00}#sa-hr .st.approved{background:#0a0a0a;color:#fff}#sa-hr .st.rejected{background:#fde8e8;color:#a12}
#sa-hr .th{background:#fafafa;border-left:3px solid #F5A623;padding:8px 10px;margin:6px 0;font-size:13px}#sa-hr .th.cust{border-left-color:#0a0a0a}
#sa-hr img.pv{max-width:180px;max-height:140px;border:1px solid #ddd;border-radius:4px;margin:4px 6px 0 0}
#sa-hr select,#sa-hr input[type=text],#sa-hr textarea{width:100%;max-width:100%}
#sa-hr a{color:#0a0a0a}
</style>';
    echo '<div id="sa-hr-inner">';
    if ($open) {
        echo '<p><strong>Open request:</strong> ' . esc_html($reasons[$open['reason']][0] ?? 'Other') . ' · sent ' . esc_html(wp_date('j M, g:i A', (int) $open['created'])) . ' · link expires ' . esc_html(wp_date('j M', (int) $open['exp'])) . ' · <a href="' . esc_url(sa_hr_url($o, $open)) . '" target="_blank">customer page</a></p>';
        foreach ($open['items'] as $k => $it) {
            echo '<div class="it"><span class="st ' . esc_attr($it['status']) . '">' . esc_html($it['status']) . '</span> <strong>' . esc_html($it['label']) . '</strong>';
            if ($it['answer'] !== '') {
                echo '<div style="margin-top:6px;white-space:pre-wrap">' . esc_html($it['answer']) . '</div>';
            }
            foreach ((array) $it['files'] as $f) {
                if (empty($f['f'])) {
                    echo '<div style="color:#777">' . esc_html((string) $f['name']) . '</div>';
                    continue;
                }
                $u = sa_hr_file_url($o, (string) $f['f']);
                $isimg = preg_match('/\.(jpe?g|png|webp|gif)$/i', (string) $f['f']);
                echo '<div><a href="' . esc_url($u) . '" target="_blank">' . ($isimg ? '<img class="pv" src="' . esc_url($u) . '" alt="">' : '📄 ') . esc_html((string) $f['name']) . '</a></div>';
            }
            if ($it['status'] === 'rejected' && $it['why']) {
                echo '<div style="color:#a12;font-size:12px">Rejected: ' . esc_html($it['why']) . '</div>';
            }
            if ($it['status'] !== 'approved') {
                echo '<div style="margin-top:6px"><button class="sa-hr-b" name="sa_hr_do" value="approve:' . esc_attr($k) . '">Approve</button>'
                    . '<input type="text" name="sa_hr_why[' . esc_attr($k) . ']" placeholder="Reason if rejecting (customer sees this)" style="max-width:320px"> '
                    . '<button class="sa-hr-b warn" name="sa_hr_do" value="reject:' . esc_attr($k) . '">Reject &amp; ask again</button></div>';
            }
            echo '</div>';
        }
        echo '<p><strong>Conversation</strong></p>';
        foreach ((array) $open['thread'] as $m) {
            echo '<div class="th ' . ($m['by'] === 'customer' ? 'cust' : '') . '"><small>' . ($m['by'] === 'customer' ? 'Customer' : 'You') . ' · ' . esc_html(wp_date('j M, g:i A', (int) $m['t'])) . '</small><br>' . nl2br(esc_html((string) $m['msg'])) . '</div>';
        }
        echo '<p><textarea name="sa_hr_reply" rows="3" placeholder="Reply to the customer (emailed and shown on their page)"></textarea></p>'
            . '<p><button class="sa-hr-b" name="sa_hr_do" value="reply">Send reply</button>'
            . '<button class="sa-hr-b amber" name="sa_hr_do" value="release" onclick="return confirm(\'Approve everything and release the order to Processing?\')">Approve all &amp; release to Processing</button>'
            . '<button class="sa-hr-b warn" name="sa_hr_do" value="cancel" onclick="return confirm(\'Close the request and cancel this order? Refund separately with the Refund button below the items.\')">Cancel order</button></p><p style="color:#666;font-size:12px">Release and cancel each send the customer a confirmation email (text in the reply box is included).</p>';
        echo '<details><summary style="cursor:pointer;font-weight:600;margin:8px 0">Ask for more items</summary>';
    } else {
        echo '<p style="margin-top:0">Put the order on hold and ask the customer for exactly what you need. They get an email with a secure link (no login) to upload photos or answer.</p>';
    }
    echo '<p><label><strong>Reason</strong><br><select name="sa_hr_reason" id="sa-hr-reason">';
    foreach ($reasons as $k => $v) {
        echo '<option value="' . esc_attr($k) . '"' . selected($open['reason'] ?? '', $k, false) . ' data-items="' . esc_attr(implode(',', $v[1])) . '">' . esc_html($v[0]) . '</option>';
    }
    echo '</select></label></p><div class="grid">';
    foreach ($cat as $k => $v) {
        if ($open && isset($open['items'][$k])) {
            continue;
        }
        echo '<label class="ck"><input type="checkbox" name="sa_hr_items[]" value="' . esc_attr($k) . '"> <span>' . esc_html($v[0]) . ' <small style="color:#777">(' . esc_html($v[1]) . ')</small></span></label>';
    }
    echo '</div><p><label><strong>Custom item</strong> (optional)<br><input type="text" name="sa_hr_custom" placeholder="e.g. Photo of the damaged box"></label> <select name="sa_hr_custom_type" style="width:auto"><option value="upload">Upload</option><option value="text">Text answer</option><option value="date">Date</option></select></p>'
        . '<p><label><strong>Message to customer</strong><br><textarea name="sa_hr_msg" rows="3" placeholder="Explain briefly why you need this. For quotes, state the extra amount."></textarea></label></p>'
        . '<p><label><input type="checkbox" name="sa_hr_rem" value="1"' . checked(get_option('sa_hr_reminders', '1'), '1', false) . '> Send automatic reminders 48h and 24h before the link expires (all orders)</label><input type="hidden" name="sa_hr_rem_set" value="1"></p>'
        . '<p><label>Link valid for <input type="number" name="sa_hr_days" value="14" min="1" max="60" style="width:70px"> days</label></p>'
        . '<p><button class="sa-hr-b" name="sa_hr_do" value="create">' . ($open ? 'Send extra request' : 'Put on hold &amp; request info') . '</button>'
        . ($open ? '' : '<button class="sa-hr-b alt" name="sa_hr_do" value="create_nohold">Request info without hold</button>') . '</p>';
    if ($open) {
        echo '</details>';
    }
    $old = array_filter(sa_hr_get($o), static fn($r) => ($r['state'] ?? '') !== 'open');
    if ($old) {
        echo '<p style="color:#666;font-size:12px">Closed requests: ' . esc_html(implode('; ', array_map(static fn($r) => ($reasons[$r['reason']][0] ?? '') . ' (' . $r['state'] . ', ' . wp_date('j M', (int) ($r['closed'] ?? $r['created'])) . ($r['purged'] ?? false ? ', files deleted' : '') . ')', $old))) . '</p>';
    }
    echo '</div><script>(function(){var s=document.getElementById("sa-hr-reason");if(!s)return;function p(){var o=s.options[s.selectedIndex],l=(o.getAttribute("data-items")||"").split(",");document.querySelectorAll("#sa-hr input[name=\'sa_hr_items[]\']").forEach(function(c){c.checked=l.indexOf(c.value)>-1;});}s.addEventListener("change",p);' . ($open ? '' : 'p();') . '})();</script>';
}

/* Save handler: runs inside Woo's order save. Always leaves a valid order_status for Woo (priority 40). */
add_action('woocommerce_process_shop_order_meta', static function ($order_id): void {
    if (empty($_POST['sa_hr_do']) || !wp_verify_nonce((string) ($_POST['sa_hr_nonce'] ?? ''), 'sa_hr_admin') || !current_user_can('edit_shop_orders')) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $o = wc_get_order((int) $order_id);
        if ($o instanceof WC_Order) {
            sa_hr_admin_do($o, (string) wp_unslash($_POST['sa_hr_do']), wp_unslash($_POST));
        }
    } catch (\Throwable $e) {
        error_log('[sa_hr] save: ' . $e->getMessage());
    }
    $fresh = wc_get_order((int) $order_id);
    if ($fresh instanceof WC_Order) {
        $_POST['order_status'] = 'wc-' . $fresh->get_status();
    }
}, 6);

/** Admin action dispatcher (also used by tests). */
function sa_hr_admin_do(WC_Order $o, string $do, array $p): void
{
    $txt = static fn(string $k): string => sanitize_textarea_field((string) ($p[$k] ?? ''));
    if (!empty($p['sa_hr_rem_set'])) {
        update_option('sa_hr_reminders', empty($p['sa_hr_rem']) ? '0' : '1', false);
    }
    if ($do === 'create' || $do === 'create_nohold') {
        $custom = [];
        if (!empty($p['sa_hr_custom'])) {
            $custom[] = ['label' => sanitize_text_field((string) $p['sa_hr_custom']), 'type' => sanitize_key((string) ($p['sa_hr_custom_type'] ?? 'upload'))];
        }
        $keys = array_map('sanitize_key', (array) ($p['sa_hr_items'] ?? []));
        if (!$keys && !$custom && $txt('sa_hr_msg') === '') {
            return;
        }
        sa_hr_create($o, sanitize_key((string) ($p['sa_hr_reason'] ?? 'other')), $keys, $custom, $txt('sa_hr_msg'), max(1, min(60, (int) ($p['sa_hr_days'] ?? 14))), $do === 'create');
        return;
    }
    $r = sa_hr_open($o);
    if (!$r) {
        return;
    }
    if (preg_match('/^(approve|reject):([a-z0-9_]+)$/', $do, $m) && isset($r['items'][$m[2]])) {
        $it = &$r['items'][$m[2]];
        if ($m[1] === 'approve') {
            $it['status'] = 'approved';
            $it['why'] = '';
            $o->add_order_note('Info item approved: ' . $it['label']);
            sa_hr_update($o, $r);
        } else {
            $it['status'] = 'rejected';
            $it['why'] = sanitize_text_field((string) ($p['sa_hr_why'][$m[2]] ?? '')) ?: 'Not clear enough, please send again';
            $o->add_order_note('Info item rejected: ' . $it['label'] . ' (' . $it['why'] . ')');
            sa_hr_update($o, $r);
            sa_hr_mail_customer($o, $r, 'more');
        }
        return;
    }
    if ($do === 'reply' && $txt('sa_hr_reply') !== '') {
        $r['thread'][] = ['t' => time(), 'by' => 'shop', 'msg' => $txt('sa_hr_reply')];
        sa_hr_update($o, $r);
        $o->add_order_note("Reply sent to customer:\n" . $txt('sa_hr_reply'));
        sa_hr_mail_customer($o, $r, 'reply', $txt('sa_hr_reply'));
        return;
    }
    if ($do === 'release' || $do === 'cancel') {
        foreach ($r['items'] as &$it) {
            if ($do === 'release') {
                $it['status'] = 'approved';
            }
        }
        unset($it);
        $r['state'] = $do === 'release' ? 'approved' : 'cancelled';
        $r['closed'] = time();
        sa_hr_update($o, $r);
        $o->add_order_note('Info request closed: ' . $r['state'] . '.');
        $o->update_meta_data('_sa_hr_silent', 1);
        $o->save();
        $o->update_status($do === 'release' ? 'processing' : 'cancelled', $do === 'release' ? 'Verification approved, hold released.' : 'Cancelled after info request.');
        $o = wc_get_order($o->get_id());
        $o->delete_meta_data('_sa_hr_silent');
        $o->save();
        sa_hr_mail_customer($o, $r, $do === 'release' ? 'released' : 'cancelled', $txt('sa_hr_reply'));
    }
}

/* ---------- Admin-only private file handler ---------- */

add_action('admin_post_sa_hr_file', static function (): void {
    $oid = (int) ($_GET['o'] ?? 0);
    if (!current_user_can('edit_shop_orders') || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sa_hr_file_' . $oid)) {
        wp_die('Not allowed', 403);
    }
    $f = basename((string) wp_unslash($_GET['f'] ?? ''));
    $path = sa_hr_dir() . '/' . $oid . '/' . $f;
    if ($f === '' || !is_file($path)) {
        wp_die('File not found (it may have been deleted after the retention period).', 404);
    }
    $type = wp_check_filetype($f)['type'] ?: 'application/octet-stream';
    nocache_headers();
    header('Content-Type: ' . $type);
    header('Content-Disposition: inline; filename="' . $f . '"');
    header('X-Robots-Tag: noindex');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
});

/* ---------- Customer page: /order-help/?o=&r=&t= ---------- */

add_action('init', static function (): void {
    add_rewrite_rule('^order-help/?$', 'index.php?sa_order_help=1', 'top');
    if (get_option('sa_hr_rw') !== '1') {
        flush_rewrite_rules(false);
        update_option('sa_hr_rw', '1');
    }
});
add_filter('query_vars', static fn(array $v): array => array_merge($v, ['sa_order_help']));

function sa_hr_from_request(): array
{
    $o = wc_get_order((int) ($_REQUEST['o'] ?? 0));
    if (!$o instanceof WC_Order) {
        return [null, null, 'invalid'];
    }
    $rid = sanitize_key((string) ($_REQUEST['r'] ?? ''));
    $t = (string) wp_unslash($_REQUEST['t'] ?? '');
    $exp = (int) strtok($t, '.');
    foreach (sa_hr_get($o) as $r) {
        if (strtolower($r['id']) === $rid || $r['id'] === (string) ($_REQUEST['r'] ?? '')) {
            if (!hash_equals(sa_hr_token($o, $r['id'], (int) $r['exp']), $t)) {
                return [null, null, 'invalid'];
            }
            if ($exp < time()) {
                return [$o, $r, 'expired'];
            }
            return [$o, $r, $r['state'] === 'open' ? 'ok' : 'closed'];
        }
    }
    return [null, null, 'invalid'];
}

function sa_hr_store_upload(WC_Order $o, array $f): ?array
{
    if ((int) $f['error'] !== UPLOAD_ERR_OK || (int) $f['size'] > 10 * MB_IN_BYTES || (int) $f['size'] <= 0) {
        return null;
    }
    $ok = wp_check_filetype_and_ext($f['tmp_name'], $f['name'], ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif', 'pdf' => 'application/pdf']);
    $ext = $ok['ext'] ?: strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'jpe', 'png', 'webp', 'heic', 'heif', 'pdf'], true)) {
        return null;
    }
    $dir = sa_hr_dir() . '/' . $o->get_id();
    wp_mkdir_p($dir);
    $name = wp_generate_password(20, false) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $name) && !(defined('SA_HR_TEST') && @rename($f['tmp_name'], $dir . '/' . $name))) {
        return null;
    }
    @chmod($dir . '/' . $name, 0640);
    return ['f' => $name, 'name' => sanitize_file_name((string) $f['name']), 't' => time()];
}

/** Apply a customer submission. $files: item => $_FILES-like single array. */
function sa_hr_customer_submit(WC_Order $o, array $r, array $post, array $files): int
{
    $changed = 0;
    $summary = [];
    foreach ($r['items'] as $k => &$it) {
        if ($it['status'] === 'approved') {
            continue;
        }
        $got = false;
        if ($it['type'] === 'upload' && !empty($files[$k]['name'])) {
            $s = sa_hr_store_upload($o, $files[$k]);
            if ($s) {
                $it['files'][] = $s;
                $got = true;
            }
        } elseif ($it['type'] === 'address') {
            $a = array_map(static fn($v) => sanitize_text_field((string) $v), (array) ($post['addr'][$k] ?? []));
            if (trim(implode('', $a)) !== '') {
                $it['answer'] = trim(implode("\n", array_filter([$a['line1'] ?? '', $a['line2'] ?? '', trim(($a['city'] ?? '') . ' ' . ($a['region'] ?? '') . ' ' . ($a['postcode'] ?? '')), $a['phone'] ?? ''])));
                $got = true;
            }
        } else {
            $v = sanitize_textarea_field((string) ($post['ans'][$k] ?? ''));
            if ($it['type'] === 'choice' && !in_array($v, $it['choices'], true)) {
                $v = '';
            }
            if ($it['type'] === 'date' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $v = '';
            }
            if ($v !== '') {
                $it['answer'] = $v;
                $got = true;
            }
        }
        if ($got) {
            $it['status'] = 'received';
            $it['why'] = '';
            $changed++;
            $summary[] = $it['label'] . ($it['type'] === 'upload' ? ' (file)' : ': ' . str_replace("\n", ', ', $it['answer']));
        }
    }
    unset($it);
    $msg = sanitize_textarea_field((string) ($post['message'] ?? ''));
    if ($msg !== '') {
        $r['thread'][] = ['t' => time(), 'by' => 'customer', 'msg' => $msg];
        $changed++;
    }
    if (!$changed) {
        return 0;
    }
    sa_hr_update($o, $r);
    $o->add_order_note("Customer responded to info request:\n- " . implode("\n- ", $summary ?: ['(message only)']) . ($msg !== '' ? "\nMessage: " . $msg : ''));
    $admin = (string) get_option('admin_email');
    $body = '<p>' . esc_html($o->get_formatted_billing_full_name()) . ' responded on order #' . esc_html($o->get_order_number()) . ':</p><ul>' . implode('', array_map(static fn($s) => '<li>' . esc_html($s) . '</li>', $summary)) . '</ul>' . ($msg !== '' ? '<p style="border-left:3px solid #F5A623;padding:8px 12px">' . nl2br(esc_html($msg)) . '</p>' : '') . '<p style="color:#666;font-size:13px">Files are private. Open the order to view them.</p>';
    wp_mail($admin, 'Customer sent info for order #' . $o->get_order_number(), sa_mail_layout('Customer responded', $body, 'Open order', $o->get_edit_order_url()), ['Content-Type: text/html; charset=UTF-8']);
    return $changed;
}

add_action('template_redirect', static function (): void {
    if (!get_query_var('sa_order_help')) {
        return;
    }
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');
    [$o, $r, $state] = sa_hr_from_request();
    $err = '';
    if ($state === 'ok' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!wp_verify_nonce((string) ($_POST['_sa_hr'] ?? ''), 'sa_hr_c_' . $r['id']) || !empty($_POST['sa_hp'])) {
            $err = 'Your session expired. Please try again.';
        } else {
            $files = [];
            foreach ((array) ($_FILES['up']['name'] ?? []) as $k => $n) {
                $files[$k] = ['name' => $n, 'type' => $_FILES['up']['type'][$k], 'tmp_name' => $_FILES['up']['tmp_name'][$k], 'error' => $_FILES['up']['error'][$k], 'size' => $_FILES['up']['size'][$k]];
            }
            if (sa_hr_customer_submit($o, $r, wp_unslash($_POST), $files)) {
                wp_safe_redirect(add_query_arg(['o' => $o->get_id(), 'r' => $r['id'], 't' => sa_hr_token($o, $r['id'], (int) $r['exp']), 'sent' => 1], home_url('/order-help/')));
                exit;
            }
            $err = 'Please add at least one photo, answer or message. Files must be photos or PDF, up to 10 MB.';
        }
    }
    status_header($o ? 200 : 404);
    get_header();
    $html = function_exists('sa_geo_without_conversion')
        ? sa_geo_without_conversion(static fn() => sa_hr_page_html($o, $r, $state, $err))
        : sa_hr_page_html($o, $r, $state, $err);
    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    get_footer();
    exit;
});

function sa_hr_page_html(?WC_Order $o, ?array $r, string $state, string $err): string
{
    ob_start();
    echo '<style>.sa-hr-p{max-width:720px;margin:28px auto 56px;padding:0 16px;color:#191919;box-sizing:border-box}.sa-hr-p *{box-sizing:border-box}.sa-hr-p h1{font-size:1.75rem;line-height:1.2;margin:0 0 8px;letter-spacing:-.01em}.sa-hr-p .muted{color:#6b6b6b}
.sa-hr-p .eyebrow{font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;color:#6b6b6b;font-weight:600;margin:0 0 6px}
.sa-hr-p .card{border:1px solid #e4e4e4;border-radius:10px;padding:16px;margin:12px 0;background:#fff}.sa-hr-p .card h3{margin:0 0 6px;font-size:1.05rem;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.sa-hr-p .num{display:inline-flex;width:24px;height:24px;border-radius:12px;background:#F5A623;color:#111;font-size:.8rem;font-weight:700;align-items:center;justify-content:center;flex:0 0 24px}
.sa-hr-p .why,.sa-hr-p .how{font-size:.93rem;color:#444;margin:2px 0}.sa-hr-p .why b,.sa-hr-p .how b{color:#111}
.sa-hr-p .verify{border:1.5px solid #111;border-radius:10px;padding:14px 16px;margin:16px 0;font-weight:600}
.sa-hr-p .ok{background:#0a0a0a;color:#fff;border-radius:4px;font-size:.7rem;padding:2px 7px;font-weight:700;text-transform:uppercase}.sa-hr-p .rx{background:#fff3dc;color:#7a4b00;border-radius:4px;font-size:.7rem;padding:2px 7px;font-weight:700;text-transform:uppercase}.sa-hr-p .rj{color:#8a4b00;background:#fff3dc;padding:6px 10px;border-radius:6px;font-size:.9rem;margin:6px 0}
.sa-hr-p input[type=text],.sa-hr-p input[type=date],.sa-hr-p textarea{width:100%;padding:12px;border:1px solid #bbb;border-radius:8px;font:inherit;font-size:16px;margin-top:6px;background:#fff;color:#111}
.sa-hr-p input:focus,.sa-hr-p textarea:focus{outline:2px solid #F5A623;border-color:#111}
.sa-hr-p .ch label{display:block;border:1px solid #ccc;border-radius:8px;padding:12px;margin:6px 0;cursor:pointer}.sa-hr-p .ch input{margin-right:8px;accent-color:#0a0a0a}.sa-hr-p .ch label:has(input:checked){border-color:#111;background:#fff8ec}
.sa-hr-p .send{background:#0a0a0a;color:#fff;border:0;border-radius:8px;padding:16px 22px;font-weight:700;font-size:1.02rem;width:100%;cursor:pointer;margin-top:6px}
.sa-hr-p .msg{border-left:3px solid #F5A623;background:#fafafa;padding:8px 12px;margin:6px 0;font-size:.95rem}.sa-hr-p .msg.c{border-left-color:#0a0a0a}
.sa-hr-p .prog{display:flex;align-items:center;gap:10px;margin:14px 0 4px;font-size:.9rem;font-weight:600}.sa-hr-p .bar{flex:1;height:6px;background:#eee;border-radius:3px;overflow:hidden}.sa-hr-p .bar i{display:block;height:100%;background:#F5A623}
.sa-hr-p .sum table{max-width:100%}.sa-hr-p .sum img{max-width:56px}.sa-hr-p .sum .sa-hr-col{max-width:50%!important}
.sa-hr-p .deadline{font-size:.93rem;margin:10px 0}
@media (max-width:600px){.sa-hr-p h1{font-size:1.45rem}.sa-hr-p .sum .sa-hr-col{max-width:100%!important;display:block!important}.sa-hr-p .sa-hide-sm{display:none!important}.sa-hr-p .sa-show-sm{display:block!important;max-height:none!important}}
.sa-hr-p a{color:#111}</style><main class="sa-hr-p">';
    if (!$o || $state === 'invalid') {
        echo '<h1>This link isn\'t valid</h1><p>Please use the button in your latest email from us, or open your order from My Account. Need help? Email calvin@supremeautoparts.co.ke.</p>';
    } elseif ($state === 'expired') {
        $c = sa_hr_contact($o);
        echo '<h1>This link has expired</h1><p>For your security, these links only work for a limited time. Reply to our email or contact us on ' . esc_html($c['phone']) . ' and we\'ll send you a new one.</p>';
    } elseif ($state === 'closed') {
        echo '<h1>All done, thank you</h1><p>We have everything we need for order #' . esc_html($o->get_order_number()) . '. There\'s nothing more to send.</p>';
    } else {
        $pay = sa_hr_pay($o);
        $c = sa_hr_copy((string) $r['reason']);
        $g = sa_hr_item_guide();
        $tot = count($r['items']);
        $done = count(array_filter($r['items'], static fn($it) => in_array($it['status'], ['received', 'approved'], true)));
        $greet = function_exists('sa_email_greeting_text') ? sa_email_greeting_text($o) : (function_exists('sa_email_greeting') ? wp_strip_all_tags(sa_email_greeting($o)) : 'Hello,');
        echo '<p class="eyebrow">Order #' . esc_html($o->get_order_number()) . ' · secure form</p><h1>' . esc_html($c['head']) . '</h1>';
        echo '<p>' . esc_html($greet) . ' ' . esc_html(sa_hr_fill($c['reason'], $o, $pay)) . '</p>';
        echo '<div class="verify"><div class="eyebrow">What we need to confirm</div>We need to confirm ' . esc_html(sa_hr_fill($c['verify'], $o, $pay)) . '</div>';
        if (!empty($_GET['sent'])) {
            echo '<div class="woocommerce-message" role="status">Thank you, we\'ve received it. We review details ' . esc_html($c['review']) . ' and will email you.</div>';
        }
        if ($err) {
            echo '<div class="woocommerce-error" role="alert">' . esc_html($err) . '</div>';
        }
        if ($tot) {
            echo '<div class="prog"><span>' . (int) $done . ' of ' . (int) $tot . ' sent</span><span class="bar"><i style="width:' . (int) round($done / max(1, $tot) * 100) . '%"></i></span></div>';
        }
        echo '<p class="deadline muted">Please send by <strong style="color:#111">' . esc_html(wp_date('l, j F Y', (int) $r['exp'])) . '</strong>. After that the order may be cancelled and refunded to your original payment method.</p>';
        foreach ((array) $r['thread'] as $m) {
            echo '<div class="msg' . ($m['by'] === 'customer' ? ' c' : '') . '"><small class="muted">' . ($m['by'] === 'customer' ? 'You' : 'Supreme Autoparts') . ' · ' . esc_html(wp_date('j M, g:i A', (int) $m['t'])) . '</small><br>' . nl2br(esc_html((string) $m['msg'])) . '</div>';
        }
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('sa_hr_c_' . $r['id'], '_sa_hr');
        echo '<input type="text" name="sa_hp" value="" style="display:none" tabindex="-1" autocomplete="off">';
        $i = 0;
        foreach ($r['items'] as $k => $it) {
            $i++;
            [$why, $how] = $g[$k] ?? ['', (string) $it['help']];
            $k = esc_attr($k);
            echo '<div class="card"><h3><span class="num">' . $i . '</span>' . esc_html($it['label']) . ' ';
            if ($it['status'] === 'approved') {
                echo '<span class="ok">Approved</span>';
            } elseif ($it['status'] === 'received') {
                echo '<span class="rx">Received</span>';
            }
            echo '</h3>';
            if ($why) {
                echo '<div class="why"><b>Why:</b> ' . esc_html($why) . '</div>';
            }
            if ($how) {
                echo '<div class="how"><b>How:</b> ' . esc_html($how) . '</div>';
            }
            if ($it['status'] === 'rejected' && $it['why']) {
                echo '<div class="rj">Please send again: ' . esc_html($it['why']) . '</div>';
            }
            if ($it['status'] !== 'approved') {
                switch ($it['type']) {
                    case 'upload':
                        echo '<input type="file" name="up[' . $k . ']" accept="image/*,application/pdf,.heic,.heif" data-max="10485760" aria-label="' . esc_attr($it['label']) . '">' . ($it['status'] === 'received' ? '<small class="muted">' . count($it['files']) . ' file(s) received. Upload again only if you want to replace it.</small>' : '');
                        break;
                    case 'choice':
                        echo '<div class="ch">';
                        foreach ($it['choices'] as $ch) {
                            echo '<label><input type="radio" name="ans[' . $k . ']" value="' . esc_attr($ch) . '"' . checked($it['answer'], $ch, false) . '>' . esc_html($ch) . '</label>';
                        }
                        echo '</div>';
                        break;
                    case 'date':
                        echo '<input type="date" name="ans[' . $k . ']" min="' . esc_attr(wp_date('Y-m-d')) . '" value="' . esc_attr($it['answer']) . '">';
                        break;
                    case 'address':
                        $us = in_array($o->get_shipping_country() ?: $o->get_billing_country(), ['US'], true);
                        $f = ['line1' => 'Street address / building', 'line2' => 'Apartment, floor, landmark (optional)', 'city' => $us ? 'City' : 'Town', 'region' => $us ? 'State' : 'County', 'postcode' => $us ? 'ZIP code' : 'Postal code (optional)', 'phone' => 'Phone for the courier'];
                        foreach ($f as $fk => $fl) {
                            echo '<input type="text" name="addr[' . $k . '][' . $fk . ']" placeholder="' . esc_attr($fl) . '" aria-label="' . esc_attr($fl) . '"' . ($fk === 'phone' ? ' inputmode="tel" autocomplete="tel"' : '') . '>';
                        }
                        break;
                    default:
                        echo '<textarea name="ans[' . $k . ']" rows="2">' . esc_textarea($it['answer']) . '</textarea>';
                }
            } elseif ($it['answer'] !== '') {
                echo '<div class="muted">' . nl2br(esc_html($it['answer'])) . '</div>';
            }
            echo '</div>';
        }
        echo '<div class="card"><h3>Anything else? (optional)</h3><textarea name="message" rows="3" placeholder="Add a note for our team"></textarea></div>'
            . '<button class="send" type="submit">' . esc_html(sa_hr_fill($c['cta'], $o, $pay)) . ' securely</button>'
            . '<p class="muted" style="font-size:.88rem;margin:10px 0 0">After you send, we review it ' . esc_html($c['review']) . '. Nothing more is charged.</p></form>';
        echo '<div class="eyebrow" style="margin-top:28px">Your order</div><div class="sum">' . sa_hr_summary_html($o, false) . '</div>';
        echo sa_hr_trust_html($o, true, false);
    }
    echo '</main>';
    return (string) ob_get_clean();
}

/* ---------- Retention: delete sensitive files N days after the request closes ---------- */

add_action('init', static function (): void {
    if (!wp_next_scheduled('sa_hr_purge')) {
        wp_schedule_event(time() + 3600, 'daily', 'sa_hr_purge');
    }
});
add_action('sa_hr_purge', 'sa_hr_purge_run');
function sa_hr_purge_run(): int
{
    $days = (int) get_option('sa_hr_purge_days', 30);
    $n = 0;
    $ids = wc_get_orders(['limit' => 200, 'return' => 'ids', 'meta_key' => '_sa_hold_req', 'meta_compare' => 'EXISTS']);
    foreach ($ids as $id) {
        $o = wc_get_order($id);
        if (!$o) {
            continue;
        }
        $reqs = sa_hr_get($o);
        $dirty = false;
        foreach ($reqs as &$r) {
            if (($r['state'] ?? 'open') !== 'open' && empty($r['purged']) && (int) ($r['closed'] ?? 0) < time() - $days * DAY_IN_SECONDS) {
                foreach ($r['items'] as &$it) {
                    foreach ((array) $it['files'] as $f) {
                        @unlink(sa_hr_dir() . '/' . $o->get_id() . '/' . basename((string) $f['f']));
                        $n++;
                    }
                    $it['files'] = array_map(static fn($f) => ['f' => '', 'name' => $f['name'] . ' (deleted)', 't' => $f['t']], (array) $it['files']);
                }
                unset($it);
                $r['purged'] = time();
                $dirty = true;
            }
        }
        unset($r);
        if ($dirty) {
            $o->update_meta_data('_sa_hold_req', $reqs);
            $o->save();
            $o->add_order_note('Verification files deleted after ' . $days . '-day retention.');
        }
    }
    return $n;
}

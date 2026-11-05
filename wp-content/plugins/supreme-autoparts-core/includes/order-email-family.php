<?php
/** Cyclery-reference customer lifecycle family. Rendering only; send routes stay unchanged. */
defined('ABSPATH') || exit;

function sa_ol_family_products(WC_Order $o): array
{
$bought = []; $roots = [];
foreach ($o->get_items() as $item) {
    $bought[] = (int)$item->get_product_id();
    $product = $item->get_product();
    if (!$product) { continue; }
    foreach ($product->get_category_ids() as $term_id) {
        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) { continue; }
        $ancestors = get_ancestors($term_id, 'product_cat', 'taxonomy');
        $root = $ancestors ? get_term(end($ancestors), 'product_cat') : $term;
        if ($root && !is_wp_error($root) && !in_array($root->slug, ['brands','collections','uncategorized'], true)) { $roots[$root->slug] = $root; }
    }
}
$products = []; $seen_images = []; $families = [];
if ($roots) {
    $candidates = wc_get_products(['status'=>'publish','limit'=>200,'orderby'=>'popularity','order'=>'DESC','stock_status'=>'instock','category'=>array_keys($roots),'exclude'=>$bought]);
    foreach ($candidates as $candidate) {
        if (isset($roots['brakes']) && preg_match('/intercooler|coupler hose|intake|exhaust|tube elbow/i', $candidate->get_name())) { continue; }
        $image = sa_core_get_product_email_image_url($candidate,320);
        $image_key = preg_replace('/\?.*$/', '', (string)$image);
        if (!$image || isset($seen_images[$image_key]) || strpos($image, 'placeholder') !== false) { continue; }
        $family = ''; $depth = -1;
        foreach ($candidate->get_category_ids() as $term_id) {
            $term = get_term($term_id, 'product_cat');
            if (!$term || is_wp_error($term)) { continue; }
            $ancestors = get_ancestors($term_id, 'product_cat', 'taxonomy');
            $root = $ancestors ? get_term(end($ancestors), 'product_cat') : $term;
            if (!$root || is_wp_error($root) || !isset($roots[$root->slug])) { continue; }
            if (count($ancestors) > $depth) {
                $depth = count($ancestors);
                $branch = $term;
                while ($branch->parent && (int)$branch->parent !== (int)$root->term_id) { $parent = get_term($branch->parent, 'product_cat'); if (!$parent || is_wp_error($parent)) { break; } $branch = $parent; }
                $family = $branch->slug;
            }
        }
        if ($family === '' || isset($families[$family])) { continue; }
        $families[$family] = true; $seen_images[$image_key] = true; $products[] = $candidate;
        if (count($products) === 6) { break; }
    }
}
    return $products;
}

function sa_ol_family_layout(WC_Order $o, string $heading, string $inner, string $cta, string $url, string $preheader, string $stage): string
{
    $asset = home_url('/wp-content/themes/supreme-autoparts/assets/');
    $business = sa_email_footer_business((string)($o->get_shipping_country() ?: $o->get_billing_country()));
    $sender_address = sa_email_sender_address((string)($o->get_shipping_country() ?: $o->get_billing_country()));
    $d = sa_rcpt_order_data($o);
    // Recommendations are discovery only, never a claim of exact vehicle compatibility.
    $products = in_array($stage, ['awaiting-info','on-hold','failed','refunded'], true) ? [] : sa_ol_family_products($o);
    ob_start();
    ?><!doctype html><!--sa-email-footer--><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
    body{margin:0;background:#fff;color:#222;font-family:Arial,Helvetica,sans-serif}table{border-collapse:collapse}img{display:block;border:0;max-width:100%;height:auto}p{margin:0 0 22px;font-size:18px;line-height:1.55}a{color:#111}.sa-family-recs td{width:33.333%;padding:9px;vertical-align:top;font-size:13px;line-height:1.45}.sa-family-recs img{width:150px;height:150px;object-fit:contain;margin:0 auto 12px}.sa-family-details td{width:50%;vertical-align:top;padding:18px 12px;font-size:15px;line-height:1.6}@media(max-width:620px){.sa-family{width:100%!important}.sa-family-body{padding:24px 16px!important}.sa-family-nav a{font-size:12px!important;letter-spacing:1px!important}.sa-family-logo{width:160px!important}.sa-family-details td{display:block!important;width:auto!important;padding:16px 0!important}.sa-family-recs td{padding:6px;font-size:12px}.sa-family-recs img{width:100px;height:100px}.sa-family-body h1{font-size:26px!important}}
    </style></head><body><span style="display:none;max-height:0;overflow:hidden"><?php echo esc_html($preheader); ?></span><table role="presentation" width="100%"><tr><td align="center"><table role="presentation" class="sa-family" width="600" style="width:600px;max-width:100%;background:#fff"><tr><td class="sa-email-brand-header" style="padding:24px 22px"><table role="presentation" width="100%"><tr><td><a href="<?php echo esc_url(home_url('/')); ?>"><img class="sa-family-logo" src="<?php echo esc_url($asset.'logo-email.png'); ?>" width="200" alt="Supreme Autoparts"></a></td><td class="sa-family-nav" align="right" style="white-space:nowrap"><a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" style="font-size:14px;letter-spacing:2px;font-weight:bold;text-decoration:none">SHOP</a>&nbsp;&nbsp; <a href="<?php echo esc_url(home_url('/services/')); ?>" style="font-size:14px;letter-spacing:2px;font-weight:bold;text-decoration:none">GARAGE</a>&nbsp;&nbsp; <a href="mailto:calvin@supremeautoparts.co.ke" style="font-size:14px;letter-spacing:2px;font-weight:bold;text-decoration:none">HELP</a></td></tr></table></td></tr>
    <tr><td><img src="<?php echo esc_url($asset.'order-email-family-banner.jpg'); ?>" width="600" height="200" alt="Workshop inspiration - stock photography"></td></tr>
    <tr><td class="sa-family-body" style="padding:26px 20px;text-align:center"><h1 style="font-size:30px;line-height:1.2;margin:0 0 22px;text-align:center"><?php echo esc_html($heading); ?></h1><?php echo $inner; // Escaped by lifecycle content builder. ?>
    <?php if ($stage !== 'cancelled'): ?><p style="text-align:center;margin:28px 0"><a href="<?php echo esc_url($url); ?>" style="display:inline-block;background:#f5a623;color:#111;padding:15px 24px;text-decoration:none;font-size:16px;font-weight:bold"><?php echo esc_html($cta); ?></a></p><?php endif; ?>
    <div style="border-top:1px solid #e5e5e5;padding-top:24px;text-align:left"><h2 style="font-size:20px;letter-spacing:1px">YOUR ORDER</h2><p style="font-size:14px">#<?php echo esc_html($o->get_order_number()); ?> · <?php echo esc_html($d['date']); ?></p><?php echo sa_ol_items_html($o); ?></div>
    <?php if ($stage !== 'cancelled'): ?><table role="presentation" class="sa-family-details" width="100%"><tr><td><strong>ORDER DETAILS</strong><br><?php echo sa_rcpt_lines($d['bill'],true); ?></td><td><strong>SHIPPING DETAILS</strong><br><?php echo sa_rcpt_lines($d['ship'] ?: $d['bill'],true); ?></td></tr><tr><td><strong>PAYMENT METHOD</strong><br><?php echo esc_html($o->get_payment_method_title()); ?></td><td><strong>SHIPPING METHOD</strong><br><?php echo esc_html($o->get_shipping_method()); ?></td></tr></table><?php endif; ?>
    <?php if ($stage !== 'cancelled'): ?><p style="text-align:center;margin:22px 0">If you have any questions, feel free to bug us. Whether you are checking fitment or planning the next fix, just reply to this email.</p><?php endif; ?>
    <?php if($products): ?><h2 style="font-size:22px;letter-spacing:2px;text-align:center">YOU MIGHT LIKE THESE...</h2><p style="text-align:center;font-size:14px">Related to the parts in your order, not a confirmed fitment match.</p><table role="presentation" class="sa-family-recs" width="100%" style="text-align:center"><?php foreach($products as $n=>$p): if($n%3===0)echo '<tr>'; ?><td><a href="<?php echo esc_url($p->get_permalink()); ?>" style="text-decoration:none"><img src="<?php echo esc_url(sa_core_get_product_email_image_url($p,320)); ?>" width="150" height="150" alt="<?php echo esc_attr($p->get_name()); ?>"><strong><?php echo esc_html($p->get_name()); ?></strong></a></td><?php if($n%3===2||$n===count($products)-1)echo '</tr>'; endforeach; ?></table><?php endif; ?>
    <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><img src="<?php echo esc_url($asset.'order-email-footer-clean.jpg'); ?>" width="552" alt="Keep your car moving - parts, fitment and a little garage know-how. Visit Supreme." style="margin-top:24px;width:100%"></a></td></tr>
    <tr><td style="background:#050505;color:#fff;padding:26px 18px;text-align:center;font-size:14px;line-height:1.6">© <?php echo esc_html(wp_date('Y')); ?> Supreme Autoparts<?php if ($sender_address !== '') { ?><br><?php echo esc_html($sender_address); ?><?php } ?><?php if ($business['phone_display'] !== '') { ?><br><?php echo esc_html($business['phone_display']); ?><?php } ?></td></tr><tr><td style="background:#111;color:#ddd;padding:20px;text-align:center;font-size:13px;line-height:1.6">You're receiving this email about your order with Supreme Autoparts. <a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>" style="color:#fff">Privacy policy</a>.<br><a href="<?php echo esc_url($d['view_url']); ?>" style="color:#fff">View your receipt</a> · Reply if you need a hand.</td></tr></table></td></tr></table></body></html><?php
    return (string)ob_get_clean();
}

/** Customer-only companion shell. No order, refund, shipment or fitment facts are invented. */
function sa_customer_mail_layout(string $heading, string $inner, string $cta_label = '', string $cta_url = '', string $preheader = '', string $badge = '', string $country = ''): string
{
    $asset = home_url('/wp-content/themes/supreme-autoparts/assets/');
    $b = sa_email_footer_business($country);
    $sender_address = sa_email_sender_address($country, (bool)preg_match('/service reminder|due for service/i', $heading));
    $cta = $cta_label !== '' && $cta_url !== '' ? '<p style="text-align:center;margin:28px 0"><a href="'.esc_url($cta_url).'" style="display:inline-block;background:#f5a623;color:#111;padding:15px 24px;text-decoration:none;font-weight:bold">'.esc_html($cta_label).'</a></p>' : '';
    // Codes and urgent roadside information stay first, without a large promo photograph.
    $urgent = (bool)preg_match('/login code|password|verify|verification|roadside|payment failed|payment not confirmed/i', $heading);
    $reset = (bool)preg_match('/reset (?:your )?password/i', $heading);
    $reply_help = ($reset || stripos($inner, 'feel free to bug us') !== false) ? '' : '<p style="font-size:14px;margin:24px 0 0">If you have any questions, feel free to bug us. Just reply to this email.</p>';
    $hero = $urgent ? '' : '<tr><td><img src="'.esc_url($asset.'order-email-family-banner.jpg').'" width="600" height="200" alt="Workshop inspiration - stock photography"></td></tr>';
    return '<!doctype html><!--sa-email-footer--><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#fff;color:#222;font-family:Arial,Helvetica,sans-serif}table{border-collapse:collapse}img{border:0;max-width:100%;height:auto}p{font-size:16px;line-height:1.6}a{color:#111}@media(max-width:620px){.sa-customer-main{width:100%!important}.sa-customer-body{padding:24px 16px!important}.sa-customer-logo{width:160px!important}.sa-customer-nav a{font-size:12px!important;letter-spacing:1px!important}.sa-hide-sm{display:none!important}.sa-show-sm{display:block!important;max-height:none!important;overflow:visible!important}.sa-items img{width:64px!important;height:64px!important}.sa-items td{font-size:13px!important}}</style></head><body><span style="display:none;max-height:0;overflow:hidden">'.esc_html($preheader).'</span><table role="presentation" width="100%"><tr><td align="center"><table role="presentation" class="sa-customer-main" width="600" style="width:600px;max-width:100%"><tr><td class="sa-email-brand-header" style="padding:24px 22px"><table role="presentation" width="100%"><tr><td><a href="'.esc_url(home_url('/')).'"><img class="sa-customer-logo" src="'.esc_url($asset.'logo-email.png').'" width="200" alt="Supreme Autoparts"></a></td><td class="sa-customer-nav" align="right" style="white-space:nowrap"><a href="'.esc_url(wc_get_page_permalink('shop')).'" style="font-size:14px;letter-spacing:2px;font-weight:bold;text-decoration:none">SHOP</a>&nbsp;&nbsp; <a href="'.esc_url(home_url('/services/')).'" style="font-size:14px;letter-spacing:2px;font-weight:bold;text-decoration:none">GARAGE</a>&nbsp;&nbsp; <a href="mailto:calvin@supremeautoparts.co.ke" style="font-size:14px;letter-spacing:2px;font-weight:bold;text-decoration:none">HELP</a></td></tr></table></td></tr>'.$hero.'<tr><td style="color:#222;text-align:center;padding:26px 22px 0"><h1 style="margin:0;font-size:30px;line-height:1.2">'.esc_html($heading).'</h1></td></tr><tr><td class="sa-customer-body" style="padding:28px 24px;font-size:16px;line-height:1.6">'.$inner.$cta.$reply_help.'</td></tr><tr><td style="background:#050505;color:#fff;padding:26px 18px;text-align:center;font-size:14px;line-height:1.6"><img src="'.esc_url($asset.'logo-email-dark.png').'" width="180" alt="Supreme Autoparts" style="display:block;width:180px;margin:0 auto 16px">© '.esc_html(wp_date('Y')).' Supreme Autoparts'.($sender_address !== '' ? '<br>'.esc_html($sender_address) : '').($b['phone_display'] !== '' ? '<br>'.esc_html($b['phone_display']) : '').'</td></tr><tr><td style="background:#111;color:#ddd;padding:20px;text-align:center;font-size:13px;line-height:1.6">This email is from Supreme Autoparts. <a href="'.esc_url(home_url('/privacy-policy/')).'" style="color:#fff">Privacy policy</a>.</td></tr></table></td></tr></table></body></html>';
}

/** Email-only receipt adapter. Page and PDF keep their separate document layout. */
function sa_customer_receipt_email(array $d): string
{
    $font = 'Arial,Helvetica,sans-serif';
    $inner = !empty($d['greet']) ? '<p>'.$d['greet'].'</p>' : '';
    if (!empty($d['lead'])) $inner .= '<p>'.esc_html($d['lead']).'</p>';
    $inner .= '<p>'.esc_html($d['number'].' · '.$d['date']).'<br><strong>'.(!empty($d['paid']) ? 'Paid' : 'Payment due').'</strong></p>';
    if (!empty($d['seller']['lines'])) $inner .= '<p style="font-size:14px"><strong>'.esc_html($d['seller_lbl'] ?? 'Ships from').'</strong><br>'.sa_rcpt_lines($d['seller']['lines']).'</p>';
    $inner .= sa_rcpt_items_html($d['items'], 'email', $font, (string)($d['item_lbl'] ?? 'Item'));
    $inner .= '<table role="presentation" width="100%" style="margin:24px 0">'.sa_rcpt_totals_html($d['totals'], $font).'</table>';
    $inner .= '<table role="presentation" width="100%"><tr><td style="vertical-align:top;padding:16px 8px"><strong>'.esc_html($d['bill_lbl'] ?? 'Bill to').'</strong><br>'.sa_rcpt_lines($d['bill']).'</td>';
    if (!empty($d['ship'])) $inner .= '<td style="vertical-align:top;padding:16px 8px"><strong>'.esc_html($d['ship_lbl'] ?? 'Ship to').'</strong><br>'.sa_rcpt_lines($d['ship']).'</td>';
    $inner .= '</tr></table>';
    if (!empty($d['pay'])) foreach ($d['pay'] as [$k,$v]) $inner .= '<p style="font-size:15px"><strong>'.esc_html($k).'</strong><br>'.wp_kses_post($v).'</p>';
    if (!empty($d['extra_html'])) $inner .= $d['extra_html'];
    if (!empty($d['note'])) $inner .= '<p>'.nl2br(esc_html($d['note'])).'</p>';
    if (!empty($d['view_url'])) $inner .= '<p><a href="'.esc_url($d['view_url']).'">View or print online</a></p>';
    $inner .= '<p>Thank you so much for supporting Supreme Autoparts! Have a question about this '.esc_html(strtolower($d['doc'])).'? Reply and quote '.esc_html($d['number']).'. Feel free to bug us.</p>';
    return sa_customer_mail_layout($d['title'], $inner, !empty($d['pay_url']) ? 'Pay now' : '', (string)($d['pay_url'] ?? ''), $d['doc'].' '.$d['number'].' from Supreme Autoparts');
}

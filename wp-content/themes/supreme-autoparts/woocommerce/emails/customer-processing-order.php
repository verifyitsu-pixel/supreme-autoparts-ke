<?php
/** Reference-led Supreme confirmation, with real order data and existing site photography. */
defined('ABSPATH') || exit;
if (!$order->get_date_paid() || !$order->is_paid()) {
    include WC()->plugin_path() . '/templates/emails/customer-on-hold-order.php';
    return;
}
$d = sa_rcpt_order_data($order);
$name = function_exists('sa_email_first_name') ? sa_email_first_name($order) : '';
$who = $name !== '' ? $name : 'car enthusiast';
$business = function_exists('sa_email_footer_business') ? sa_email_footer_business((string)($order->get_shipping_country() ?: $order->get_billing_country())) : ['one_line'=>'Nairobi, Kenya','phone_display'=>'+254 714 498 451'];
$asset = home_url('/wp-content/themes/supreme-autoparts/assets/');
$shop = wc_get_page_permalink('shop');
$pickup = false;
foreach ($order->get_shipping_methods() as $method) { if (strpos($method->get_method_id(), 'local_pickup') === 0) { $pickup = true; } }
$font = 'Arial,Helvetica,sans-serif';
// Email-only order-category shelf. Does not change SM_Recommend or its scores.
$bought = []; $roots = [];
foreach ($order->get_items() as $item) {
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
?><!doctype html><!--sa-email-footer--><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>
body{margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#222}table{border-collapse:collapse}img{border:0;display:block;max-width:100%;height:auto}p{font-size:18px;line-height:1.55;margin:0 0 24px}a{color:#111}h2{font-size:22px;letter-spacing:2px;margin:0 0 14px}.sa-recs td{width:33.333%;vertical-align:top;padding:10px;font-size:13px;line-height:1.4}.sa-recs img{width:150px;height:150px;object-fit:contain;margin:0 auto 14px}.sa-half{width:50%;vertical-align:top;padding:24px 10px}.sa-label{font-size:18px;font-weight:bold;letter-spacing:1.5px;margin-bottom:12px}.sa-text{font-size:16px;line-height:1.5}.sa-order-product img{width:130px;height:130px;object-fit:contain}.sa-totals td{font-size:16px;padding:7px 0}.sa-totals th{text-align:left;font-size:18px;padding:7px 0}.sa-title{font-size:18px;line-height:1.4}.sa-small{font-size:13px;line-height:1.5}@media(max-width:620px){.sa-main{width:100%!important}.sa-body{padding:22px 16px!important}.sa-half{display:block!important;width:auto!important;padding:20px 0!important}.sa-recs td{padding:7px;font-size:12px}.sa-recs img{height:100px;width:100px}.sa-order-product img{width:100px;height:100px}.sa-order-product td{display:block!important;width:auto!important;padding:8px 0!important}.sa-order-product{padding:14px 0!important}.sa-title{font-size:16px}p{font-size:18px!important}.sa-nav a{font-size:12px!important;letter-spacing:1px!important}.sa-logo{width:160px!important}}
</style></head><body><table role="presentation" width="100%"><tr><td align="center" style="padding:0"><table role="presentation" class="sa-main" width="600" style="width:600px;max-width:100%;background:#fff"><tr><td class="sa-email-brand-header" style="padding:24px 22px"><table role="presentation" width="100%"><tr><td><a href="<?php echo esc_url(home_url('/')); ?>"><img class="sa-logo" src="<?php echo esc_url($asset.'logo-email.png'); ?>" width="200" alt="Supreme Autoparts"></a></td><td class="sa-nav" align="right" style="white-space:nowrap"><a href="<?php echo esc_url($shop); ?>" style="font-size:14px;font-weight:bold;letter-spacing:2px;text-decoration:none">SHOP</a>&nbsp;&nbsp; <a href="<?php echo esc_url(home_url('/services/')); ?>" style="font-size:14px;font-weight:bold;letter-spacing:2px;text-decoration:none">GARAGE</a>&nbsp;&nbsp; <a href="<?php echo esc_url('mailto:calvin@supremeautoparts.co.ke'); ?>" style="font-size:14px;font-weight:bold;letter-spacing:2px;text-decoration:none">HELP</a></td></tr></table></td></tr>
<tr><td><img src="<?php echo esc_url($asset.'order-email-hero-clean.jpg'); ?>" width="600" alt="Order confirmation - Supreme Autoparts"></td></tr>
<tr><td class="sa-body" style="padding:24px 20px;text-align:center">
<p>It's a fact! You, yes you <?php echo esc_html($who); ?>, have excellent taste in car parts and just picked up some good stuff.</p>
<p style="font-weight:bold">Thank you so much for supporting Supreme Autoparts! :)<br>A big thank-you from our garage to yours.</p>
<img src="<?php echo esc_url($asset.'order-email-garage-clean.jpg'); ?>" width="560" alt="Professional workshop inspiration - stock photography" style="width:100%;margin:4px 0 8px">
<div style="font-size:11px;color:#777;margin:0 0 24px">Workshop inspiration. Stock photography.</div><p>We're confident this is the best order confirmation you've seen all day. You should print it out and hang it in your garage. (We assume you have a garage corner, because every car project needs one.)</p>
<p>If you have any questions, feel free to bug us. Whether you're checking fitment, planning the next fix or just talking cars, we're waiting to hear from the one and only, the glorious <?php echo esc_html($who); ?>.</p>
<p><?php echo esc_html($pickup ? 'If you are collecting your order, we will contact you when your parts are ready for pickup. Please wait for that update before setting off.' : 'If this order is shipping to you, we will email your tracking details when it is dispatched. Your chosen delivery method and any saved estimate are below. If a part needs a special order, we will contact you with an update.'); ?></p>
<p>Now go enjoy your car!</p>
<table role="presentation" width="100%" style="border-top:1px solid #e5e5e5;border-bottom:1px solid #e5e5e5;margin:30px 0 12px;text-align:left"><tr><td style="padding:22px 10px;width:50%;font-size:16px"><strong>Date:</strong><br><span class="sa-small"><?php echo esc_html($d['date']); ?></span></td><td style="padding:22px 10px;font-size:16px"><strong>Order Number:</strong><br><span class="sa-small">#<?php echo esc_html($order->get_order_number()); ?></span></td></tr></table>
<?php foreach ($d['items'] as $it) : ?><table class="sa-order-product" role="presentation" width="100%" style="border-bottom:1px solid #e5e5e5;text-align:left"><tr><td width="140" style="padding:20px 10px 20px 0;vertical-align:top"><?php if (!empty($it['thumb'])) : ?><img src="<?php echo esc_url($it['thumb']); ?>" width="130" height="130" alt="<?php echo esc_attr($it['name']); ?>"><?php endif; ?></td><td class="sa-title" style="vertical-align:top;padding:20px 10px 20px 0"><?php echo esc_html($it['name']); ?><?php foreach ((array)($it['meta']??[]) as $m) : ?><div class="sa-small" style="margin-top:7px;color:#666"><?php echo esc_html($m); ?></div><?php endforeach; ?></td><td style="vertical-align:top;padding:20px 0;font-size:15px;white-space:nowrap"><?php echo esc_html($it['qty']); ?> × <?php echo wp_kses_post($it['unit']); ?></td></tr></table><?php endforeach; ?>
<table class="sa-totals" role="presentation" width="100%" style="margin:20px 0;text-align:left"><?php foreach ($d['totals'] as $row) : ?><tr><th><?php echo esc_html($row[0]??''); ?></th><td align="right"><?php echo wp_kses_post($row[1]??''); ?></td></tr><?php endforeach; ?></table>
<table role="presentation" width="100%" style="text-align:left"><tr><td class="sa-half"><div class="sa-label">ORDER DETAILS</div><div class="sa-text"><?php echo sa_rcpt_lines($d['bill'],true); ?></div></td><td class="sa-half"><div class="sa-label">SHIPPING DETAILS</div><div class="sa-text"><?php echo sa_rcpt_lines($d['ship']?:$d['bill'],true); ?></div></td></tr><tr><td class="sa-half"><div class="sa-label">PAYMENT METHOD</div><div class="sa-text"><?php echo esc_html($order->get_payment_method_title()); ?></div></td><td class="sa-half"><div class="sa-label">SHIPPING METHOD</div><div class="sa-text"><?php echo esc_html($order->get_shipping_method()); ?><?php if(function_exists('sa_ff_order_block'))echo wp_kses_post(sa_ff_order_block($order)); ?></div></td></tr></table>
<div style="border-top:1px solid #e5e5e5;border-bottom:1px solid #e5e5e5;padding:26px 0;margin:0 0 24px"><p>Need help with the next part of your project? Real parts, fitment help and a friendly garage chat are just a reply away.</p><a href="<?php echo esc_url($shop); ?>" style="display:inline-block;background:#f5a623;color:#111;padding:15px 24px;font-size:17px;font-weight:bold;text-decoration:none">Back to the garage</a></div>
<h2>FORGET ANYTHING?</h2><p style="font-size:15px">Related to the parts in your order. Different options to explore, not a confirmed fitment match.</p>
<table class="sa-recs" role="presentation" width="100%" style="text-align:center"><?php foreach ($products as $n=>$p) : if($n%3===0)echo '<tr>'; $img=sa_core_get_product_email_image_url($p,320); ?><td><a href="<?php echo esc_url($p->get_permalink()); ?>" style="text-decoration:none"><img src="<?php echo esc_url($img); ?>" width="150" height="150" alt="<?php echo esc_attr($p->get_name()); ?>"><strong><?php echo esc_html($p->get_name()); ?></strong></a></td><?php if($n%3===2||$n===count($products)-1)echo '</tr>'; endforeach; ?></table>
<a href="<?php echo esc_url($shop); ?>"><img src="<?php echo esc_url($asset.'order-email-footer-clean.jpg'); ?>" width="560" alt="Keep your car moving - parts, fitment and a little garage know-how. Visit Supreme." style="width:100%;margin:22px 0 0"></a>
</td></tr><tr><td style="background:#050505;color:#fff;text-align:center;padding:26px 18px;font-size:14px;letter-spacing:1px">© <?php echo esc_html(wp_date('Y')); ?> Supreme Autoparts<br><?php echo esc_html($business['one_line']); ?><br><?php echo esc_html($business['phone_display']); ?></td></tr><tr><td style="background:#111;color:#ddd;text-align:center;padding:20px;font-size:13px;line-height:1.55">You're receiving this email because you placed an order with Supreme Autoparts. <a style="color:#fff" href="<?php echo esc_url(home_url('/privacy-policy/')); ?>">Privacy policy</a>.<br><a style="color:#fff" href="<?php echo esc_url($d['view_url']); ?>">View your receipt</a> · Reply to this email if you need a hand.</td></tr></table></td></tr></table></body></html>

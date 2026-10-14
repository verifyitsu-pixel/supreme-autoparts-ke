<?php
/**
 * Per-country copy for pages whose seeded text mixed Kenya and US details.
 * Kenya sees only Kenyan delivery/payment copy; US only US; everyone else international.
 * Figures come from the live shipping config (no invented rates or times).
 */
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

function sa_gc_zone(): string
{
    $cc = function_exists('sa_geo_country') ? sa_geo_country() : 'KE';
    return $cc === 'KE' ? 'KE' : ($cc === 'US' ? 'US' : 'ROW');
}

// Pesapal title: mobile money only means something in Kenya.
add_filter('woocommerce_gateway_title', static function ($title, $id = '') {
    if ($id === 'pesapal' && !is_admin() && sa_gc_zone() !== 'KE') {
        return 'Card (Visa, Mastercard)';
    }
    return $title;
}, 20, 2);

function sa_gc_shipping_html(): string
{
    $z = sa_gc_zone();
    $free = function_exists('sa_core_shipping_free_min') ? sa_core_shipping_free_min() : 99.0;
    $p = static fn (float $usd): string => wp_strip_all_tags(wc_price($usd, ['decimals' => 0]));
    $mail = 'calvin@supremeautoparts.co.ke';
    if ($z === 'KE') {
        return '<h2>Delivery in Kenya</h2>'
            . '<ul><li><strong>Fargo Courier</strong> to any of the 47 counties. The price depends on your town and the parcel weight and shows at checkout before you pay. You can track the parcel on our site.</li>'
            . '<li><strong>Store pickup</strong> is free. Pick a collection time at checkout and collect from our shop at Ground Floor, Midax Plaza, Kangundo Rd, Njiru.</li>'
            . '<li>Parts you buy with fitting stay at our garage and are not shipped.</li></ul>'
            . '<p>Questions about a delivery: call or WhatsApp <a href="https://wa.me/254714498451">+254 714 498 451</a> or email <a href="mailto:' . $mail . '">' . $mail . '</a> with your order number.</p>';
    }
    if ($z === 'US') {
        return '<h2>Shipping in the United States</h2>'
            . '<ul><li><strong>Free shipping</strong> on eligible orders of ' . esc_html($p($free)) . ' and up.</li>'
            . '<li><strong>Standard Shipping</strong> (continental US): ' . esc_html($p(8)) . ' flat.</li>'
            . '<li><strong>Priority Shipping</strong>: ' . esc_html($p(15)) . ' flat.</li></ul>'
            . '<p>Oversized or direct-ship items can be excluded from free shipping; the product page and checkout show what applies. Questions: WhatsApp <a href="https://wa.me/19174375121">+1 917 437 5121</a> or email <a href="mailto:' . $mail . '">' . $mail . '</a>.</p>';
    }
    return '<h2>International shipping</h2>'
        . '<p>We ship to most countries for a flat ' . esc_html($p(25)) . '. The rate shows at checkout before you pay. Customs duties and import taxes in your country are paid by you.</p>'
        . '<p>Questions: WhatsApp <a href="https://wa.me/19174375121">+1 917 437 5121</a> or email <a href="mailto:' . $mail . '">' . $mail . '</a>.</p>';
}

function sa_gc_about_html(): string
{
    $z = sa_gc_zone();
    $intro = '<p><strong>Supreme Autoparts</strong> sells parts for Japanese, American, British and European cars, trucks and SUVs. You can search by part number, brand or vehicle, and we check fitment if you send us your car details.</p>';
    if ($z === 'KE') {
        return $intro . '<p>We are based at Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi. We deliver to every county with Fargo Courier, offer store pickup, service cars at our garage and run Roadside Rescue across Nairobi.</p>'
            . '<p>Call or WhatsApp <a href="https://wa.me/254714498451">+254 714 498 451</a> · <a href="mailto:calvin@supremeautoparts.co.ke">calvin@supremeautoparts.co.ke</a></p>';
    }
    return $intro . '<p>' . ($z === 'US' ? 'We ship across the US.' : 'We ship worldwide.') . ' Not sure a part fits? Send us your year, make, model or VIN before you order.</p>'
        . '<p>WhatsApp <a href="https://wa.me/19174375121">+1 917 437 5121</a> · <a href="mailto:calvin@supremeautoparts.co.ke">calvin@supremeautoparts.co.ke</a></p>';
}

add_filter('the_content', static function (string $html): string {
    if (is_admin() || !is_main_query() || !in_the_loop() || !is_page()) {
        return $html;
    }
    $slug = (string) get_post_field('post_name', get_the_ID());
    if ($slug === 'shipping-policy') {
        return sa_gc_shipping_html();
    }
    if ($slug === 'contact') {
        if (sa_gc_zone() === 'KE') {
            $html = str_replace(['about fitment, US delivery, international shipping, or a special order', 'US delivery'], ['about fitment, delivery, store pickup or a special order', 'delivery'], $html);
        } elseif (sa_gc_zone() === 'ROW') {
            $html = str_replace('about fitment, US delivery, international shipping, or a special order', 'about fitment, international shipping or a special order', $html);
        }
        return $html;
    }
    if ($slug === 'about-us') {
        return sa_gc_about_html();
    }
    return $html;
}, 5);

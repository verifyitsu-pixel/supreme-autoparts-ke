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
    $cc = function_exists('sa_storefront_country') ? sa_storefront_country() : 'KE';
    return $cc === 'KE' ? 'KE' : ($cc === 'US' ? 'US' : 'ROW');
}

// Pesapal title: mobile money only means something in Kenya.
add_filter('woocommerce_gateway_title', static function ($title, $id = '') {
    if ($id === 'pesapal' && !is_admin() && function_exists('sa_geo_country') && sa_geo_country() !== 'KE') {
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
            . '<p>Questions about a delivery: call or <a href="https://wa.me/supremeautopart">chat on WhatsApp</a> or email <a href="mailto:' . $mail . '">' . $mail . '</a> with your order number.</p>';
    }
    if ($z === 'US') {
        return '<h2>Shipping in the United States</h2>'
            . '<ul><li><strong>Free shipping</strong> on eligible orders of ' . esc_html($p($free)) . ' and up.</li>'
            . '<li><strong>Standard Shipping</strong> (continental US): ' . esc_html($p(8)) . ' flat.</li>'
            . '<li><strong>Priority Shipping</strong>: ' . esc_html($p(15)) . ' flat.</li></ul>'
            . '<p>Oversized or direct-ship items can be excluded from free shipping; the product page and checkout show what applies. Questions: <a href="https://wa.me/supremeautopart">chat on WhatsApp</a> or email <a href="mailto:' . $mail . '">' . $mail . '</a>.</p>';
    }
    return '<h2>International shipping</h2>'
        . '<p>We ship to most countries for a flat ' . esc_html($p(25)) . '. The rate shows at checkout before you pay. Customs duties and import taxes in your country are paid by you.</p>'
        . '<p>Questions: <a href="https://wa.me/supremeautopart">chat on WhatsApp</a> or email <a href="mailto:' . $mail . '">' . $mail . '</a>.</p>';
}

function sa_gc_about_html(): string
{
    $z = sa_gc_zone();
    $intro = '<p>Supreme Autoparts is a trading name of Standard Driveworks.</p><p><strong>Supreme Autoparts</strong> sells parts for Japanese, American, British and European cars, trucks and SUVs. You can search by part number, brand or vehicle, and we check fitment if you send us your car details.</p>';
    if ($z === 'KE') {
        return $intro . '<p>We are based at Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi. We deliver to every county with Fargo Courier, offer store pickup, service cars at our garage and run Roadside Rescue across Nairobi.</p>'
            . '<p>Call or <a href="https://wa.me/supremeautopart">chat on WhatsApp</a> · <a href="mailto:calvin@supremeautoparts.co.ke">calvin@supremeautoparts.co.ke</a></p>';
    }
    return $intro . '<p>' . ($z === 'US' ? 'We ship across the US.' : 'We ship worldwide.') . ' Not sure a part fits? Send us your year, make, model or VIN before you order.</p>'
        . '<p><a href="https://wa.me/supremeautopart">Chat on WhatsApp</a> · <a href="mailto:calvin@supremeautoparts.co.ke">calvin@supremeautoparts.co.ke</a></p>';
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
    if ($slug === 'terms' && !str_contains($html, 'Supreme Autoparts is a trading name of Standard Driveworks.')) {
        return '<p>Supreme Autoparts is a trading name of Standard Driveworks.</p>' . $html;
    }
    return $html;
}, 5);

/** Service scope updates: factual additions only, without new fees or legal waivers. */
add_filter('the_content', static function (string $html): string {
    if (is_admin() || !is_main_query() || !in_the_loop() || !is_page()) { return $html; }
    $slug = (string) get_post_field('post_name', get_the_ID());
    if ($slug === 'contact') {
        $html = '<h2>Automotive software services in Nairobi</h2><p>Ask us about ECU programming, vehicle coding, software updates and electronic diagnostics for your car. <a href="/pricing/#automotive-software">View service pricing</a>.</p>' . $html;
    }
    if ($slug === 'terms') {
        $html .= '<h2>Automotive software and garage services</h2><p>Our vehicle services include ECU programming, vehicle coding, software updates, electronic diagnostics and maintenance at our garage in Nairobi, Kenya. These are services carried out on vehicles, not a subscription or downloadable software product. We confirm vehicle compatibility, the scope of work and a quote before starting.</p><p>The Kenya/US store-view switch changes browsing information. It does not change your delivery address, shipping eligibility or payment currency. See our <a href="/pricing/">service pricing</a>. We confirm the scope and final price before work starts.</p>';
    }
    if ($slug === 'refund-policy') {
        $html .= '<h2>Vehicle software and maintenance enquiries</h2><p>The product-return information above concerns physical goods. For a vehicle-software or maintenance booking, contact us with your booking number and the issue. See our <a href="/booking-terms/">Booking Terms</a> for the existing service-booking conditions. This addition does not introduce a new service fee or refund restriction.</p>';
    }
    if ($slug === 'privacy-policy') {
        $html .= '<h2>Store view and service enquiries</h2><p>The Kenya/US store-view preference is remembered in a cookie for 30 days. It changes browsing copy, not your delivery address or payment currency. Service enquiries and bookings use the contact and vehicle information you choose to provide so we can respond about compatibility, diagnostics or maintenance.</p>';
    }
    return $html;
}, 35);

add_action('template_redirect', static function (): void {
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ($path !== 'pricing') { return; }
    global $wp_query;
    if ($wp_query) { $wp_query->is_404 = false; }
    status_header(200);
    get_header();
    echo '<main class="sa-container sa-pricing"><p class="sa-ed-kicker">Supreme Autoparts · Nairobi</p><h1>Vehicle software & maintenance pricing</h1><p>ECU programming, vehicle coding, software updates, electronic diagnostics and garage maintenance. Send your year, make, model and the work you need for a vehicle-specific quote.</p><h2 id="automotive-software">Software & electronic diagnostics</h2><div class="sa-pricing-scroll"><table><thead><tr><th>Service</th><th>Price (USD)</th></tr></thead><tbody>';
    $software = [
        ['ECU programming', 'From US$150'],
        ['Vehicle coding', 'Vehicle-specific quote'],
        ['Software updates', 'From US$250 for GM reprogramming'],
        ['Electronic diagnostics', 'US$125–183'],
    ];
    foreach ($software as $row) {
        echo '<tr><th scope="row">' . esc_html($row[0]) . '</th><td>' . esc_html($row[1]) . '</td></tr>';
    }
    echo '</tbody></table></div><h2>Maintenance & mechanical services</h2><div class="sa-pricing-scroll"><table><thead><tr><th>Service</th><th>Price (USD)</th></tr></thead><tbody>';
    $prices = [
        'oil-change' => 'US$160–188',
        'brake-pads' => 'US$335–394',
        'wheel-alignment' => 'US$186–273',
        'tyre-rotation' => 'US$49–72',
        'ac-service' => 'US$285–369',
        'battery' => 'US$456–493',
    ];
    foreach (sa_svc_catalog() as $slug => $svc) {
        if ($slug === 'diagnostics') { continue; }
        echo '<tr><th scope="row">' . esc_html($svc['name']) . '</th><td>' . esc_html($prices[$slug] ?? 'Vehicle-specific quote') . '</td></tr>';
    }
    echo '</tbody></table></div><h2>Get your exact quote</h2><p>Tell us your vehicle and the problem or work required. We confirm the service scope and price before work starts.</p><a class="sa-software-link" href="' . esc_url(home_url('/contact/')) . '">Contact Supreme for a quote →</a><p><a href="' . esc_url(home_url('/services/')) . '">Browse services</a> · <a href="' . esc_url(home_url('/terms/')) . '">Terms</a> · <a href="' . esc_url(home_url('/privacy-policy/')) . '">Privacy</a> · <a href="' . esc_url(home_url('/refund-policy/')) . '">Refunds</a></p></main>';
    get_footer();
    exit;
}, 0);

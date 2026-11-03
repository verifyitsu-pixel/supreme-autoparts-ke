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
        $html .= '<h2>Vehicle software and maintenance enquiries</h2><p>The product-return information above concerns physical goods. For a vehicle-software or maintenance booking, contact us with your booking number and the issue. See our <a href="/booking-terms/">Booking Terms</a> for the existing service-booking conditions.</p>';
    }
    if ($slug === 'privacy-policy') {
        $html .= '<h2>Store view and service enquiries</h2><p>The Kenya/US store-view preference is remembered in a cookie for 30 days. It changes browsing copy, not your delivery address or payment currency. Service enquiries and bookings use the contact and vehicle information you choose to provide so we can respond about compatibility, diagnostics or maintenance.</p>';
    }
    return $html;
}, 35);

function sa_gc_service_card(string $slug, string $name, string $price, string $summary, bool $assessment): void {
    $price = sa_svc_price_label(sa_svc_catalog()[$slug], sa_svc_region());
    $detail = sa_svc_url($slug) . '?region=ke';
    $book = home_url('/book-service/?service=' . rawurlencode($slug) . '&region=ke');
    echo '<article class="sa-service-card"><h3><a href="' . esc_url($detail) . '">' . esc_html($name) . '</a></h3><p class="sa-card-price">' . esc_html($price) . '</p><p class="sa-card-summary">' . esc_html($summary) . '</p><p class="sa-card-fee">' . ($assessment ? '60-minute assessment · KES 2,500' : 'Booking fee · KES ' . number_format((float) (get_option('sa_svc_settings', [])['fee_kes'] ?? 1000))) . ' credited to your bill</p><div class="sa-card-actions"><a class="sa-card-button" href="' . esc_url($book) . '">' . ($assessment ? 'Book assessment' : 'Book service') . '</a><a class="sa-card-details" href="' . esc_url($detail) . '">View details →</a></div></article>';
}

add_action('template_redirect', static function (): void {
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ($path !== 'pricing') { return; }
    global $wp_query;
    if ($wp_query) { $wp_query->is_404 = false; }
    status_header(200);
    get_header();
    echo '<main class="sa-container sa-pricing"><header class="sa-pricing-hero"><p class="sa-ed-kicker">Supreme Autoparts · Nairobi</p><h1>Car care.<br>Clear prices.</h1><p>Software, diagnostics and maintenance. Choose your service to see the details and book your visit.</p><nav class="sa-pricing-jump"><a href="#automotive-software">Software & diagnostics</a><a href="#maintenance">Maintenance</a></nav></header><section id="automotive-software"><div class="sa-pricing-heading"><p class="sa-ed-kicker">Vehicle software</p><h2>Software & diagnostics</h2></div><div class="sa-assessment-note"><strong>Start with an assessment</strong><span>ECU programming, coding and updates begin with a 60-minute visit at our Nairobi garage. KES 2,500, credited toward your final bill. Work and final price are quoted afterward.</span></div><div class="sa-service-cards">';
    $software = [
        ['ecu-programming', 'ECU programming', 'From US$150', 'Check compatibility and the programming your vehicle needs.'],
        ['vehicle-coding', 'Vehicle coding', 'Vehicle-specific quote', 'Vehicle-specific coding, with scope confirmed before work.'],
        ['software-updates', 'Software updates', 'From US$250', 'GM reprogramming. Other vehicles quoted after assessment.'],
        ['diagnostics', 'Electronic diagnostics', 'US$125–183', 'Understand warning lights and electronic faults.'],
    ];
    foreach ($software as [$slug, $name, $price, $summary]) {
        sa_gc_service_card($slug, $name, $price, $summary, $slug !== 'diagnostics');
    }
    echo '</div></section><section id="maintenance"><div class="sa-pricing-heading"><p class="sa-ed-kicker">Keep it running</p><h2>Maintenance & mechanical</h2></div><div class="sa-service-cards">';
    $prices = ['oil-change'=>'US$160–188','brake-pads'=>'US$335–394','wheel-alignment'=>'US$186–273','tyre-rotation'=>'US$49–72','ac-service'=>'US$285–369','battery'=>'US$456–493'];
    foreach (sa_svc_catalog() as $slug => $svc) {
        if ($slug === 'diagnostics' || !empty($svc['assessment'])) { continue; }
        sa_gc_service_card($slug, $svc['name'], $prices[$slug] ?? 'Vehicle-specific quote', $svc['summary'], false);
    }
    echo '</div></section><footer class="sa-pricing-footer"><h2>Not sure what you need?</h2><p>Tell us about your car and the problem. We will help you choose the right service.</p><a class="sa-card-button" href="' . esc_url(home_url('/contact/')) . '">Contact Supreme</a><p class="sa-pricing-policy-links"><a href="' . esc_url(home_url('/booking-terms/')) . '">Booking terms</a> · <a href="' . esc_url(home_url('/terms/')) . '">Terms</a> · <a href="' . esc_url(home_url('/privacy-policy/')) . '">Privacy</a> · <a href="' . esc_url(home_url('/refund-policy/')) . '">Refunds</a></p></footer></main>';
    get_footer();
    exit;
}, 0);

/* Preserve each booking's fee through payment, including software assessment fees. */
function sa_software_fee_settings($settings) {
    $settings = is_array($settings) ? $settings : [];
    $service = sanitize_key((string) ($_POST['service'] ?? $_GET['service'] ?? get_query_var('sa_svc') ?? ''));
    $booking = (int) ($_GET['b'] ?? 0);
    if ($booking && get_post_type($booking) === 'sa_booking') {
        $stored = (string) get_post_meta($booking, '_sa_bk_key', true);
        if ($stored !== '' && hash_equals($stored, (string) ($_GET['k'] ?? ''))) {
            $fee = (int) get_post_meta($booking, '_sa_bk_fee_kes', true);
            if ($fee > 0) { $settings['fee_kes'] = $fee; return $settings; }
        }
    }
    if (!empty(sa_svc_catalog()[$service]['assessment'])) { $settings['fee_kes'] = 2500; }
    return $settings;
}
add_filter('option_sa_svc_settings', 'sa_software_fee_settings');
add_filter('default_option_sa_svc_settings', 'sa_software_fee_settings');
add_action('template_redirect', static function (): void {
    if (get_query_var('sa_svc_view') !== 'terms') { return; }
    ob_start(static function (string $html): string {
        $html = str_replace('14 October 2026', '3 November 2026', $html);
        $html = str_replace('A booking fee of KES ', 'For other service bookings, a booking fee of KES ', $html);
        return str_replace('Booking fee</h2>', 'Software assessments</h2><p>ECU programming, vehicle coding and software updates start with a paid 60-minute assessment at our Nairobi garage. The assessment fee is KES 2,500, credited toward your final service bill. Work, final price and timing are quoted after assessment.</p><h2>Booking fee</h2>', $html);
    });
}, 1);

add_action('wp_footer', static function (): void {
    if (get_query_var('sa_svc_view') !== 'book') { return; }
    echo '<script>(function(){var f=document.querySelector("[data-sa-svc-form]");if(!f)return;var names=["ecu-programming","vehicle-coding","software-updates"],sel=f.querySelector("[name=service]:checked"),assessment=sel&&names.indexOf(sel.value)>=0;f.querySelectorAll("[name=service]").forEach(function(e){var hide=assessment?e!==sel:names.indexOf(e.value)>=0;if(hide){e.disabled=true;e.closest("label").hidden=true;}});f.querySelectorAll("input[type=checkbox][data-dur]").forEach(function(e){if(names.indexOf(e.value)>=0){e.disabled=true;e.checked=false;e.closest("label").hidden=true;}});})();</script>';
}, 100);

/* Keep policy search previews consistent with the live 15-day product-return text. */
add_filter('get_post_metadata', static function ($value, $object_id, $meta_key, $single) {
    if ($meta_key === '_sa_seo_description' && get_post_field('post_name', (int) $object_id) === 'refund-policy') {
        $description = 'Unused products may be returned within 15 days through My Account > Returns. See the policy for conditions and vehicle-service booking terms.';
        return $single ? $description : [$description];
    }
    return $value;
}, 99, 4);

/** A shared service summary across detail, booking and service-fee payment. */
function sa_gc_journey_summary(string $slug): string {
    $svc = sa_svc_catalog()[$slug] ?? null;
    if (!$svc) { return ''; }
    $assessment = !empty($svc['assessment']);
    $fee = $assessment ? 2500 : (float) sa_svc_setting('fee_kes');
    return '<section class="sa-journey-summary"><p class="sa-ed-kicker">Your service, at a glance</p><h2>' . esc_html($svc['name']) . '</h2><dl><div><dt>Service price</dt><dd>' . esc_html(sa_svc_price_label($svc, sa_svc_region())) . '</dd></div><div><dt>' . ($assessment ? 'Assessment booking fee' : 'Booking fee') . '</dt><dd>KES ' . number_format($fee) . '</dd></div><div><dt>Applied to your final bill</dt><dd>Yes</dd></div></dl><p>' . ($assessment ? 'This books a 60-minute assessment in Nairobi, not the programming, coding or update work itself. Work, final price and timing are quoted after assessment.' : 'The booking fee confirms your appointment. The final service quote is confirmed before work starts.') . '</p></section>';
}
add_action('template_redirect', static function (): void {
    if (get_query_var('sa_svc_view') !== 'detail') { return; }
    $slug = (string) get_query_var('sa_svc'); $svc = sa_svc_catalog()[$slug] ?? null;
    if (!$svc) { return; }
    global $wp_query; $wp_query->is_404 = false; $wp_query->is_page = true; status_header(200);
    add_filter('pre_get_document_title', static fn () => $svc['name'] . ' | Supreme Autoparts', 99);
    get_header();
    echo '<main class="sa-journey sa-container"><nav class="sa-svc-crumbs"><a href="' . esc_url(home_url('/pricing/')) . '">Service pricing</a> / ' . esc_html($svc['name']) . '</nav><div class="sa-journey-grid"><article><p class="sa-ed-kicker">Supreme Autoparts · Nairobi garage</p><h1>' . esc_html($svc['name']) . '</h1><p class="sa-journey-lead">' . esc_html($svc['summary']) . '</p><div class="sa-journey-mobile-summary">' . sa_gc_journey_summary($slug) . '<a class="sa-card-button sa-journey-book" href="' . esc_url(sa_svc_book_url($slug)) . '">' . (!empty($svc['assessment']) ? 'Book an assessment' : 'Book this service') . '</a></div><section class="sa-journey-panel"><h2>What is included</h2><ul>';
    foreach ($svc['includes'] as $item) { echo '<li>' . esc_html($item) . '</li>'; }
    echo '</ul></section><section class="sa-journey-panel"><h2>How it works</h2><ol class="sa-journey-steps"><li><strong>Tell us about your vehicle</strong><p>Choose the service and provide your vehicle details.</p></li><li><strong>Choose your visit</strong><p>Select the location, date and available time in the booking form.</p></li><li><strong>Review and pay the booking fee</strong><p>Review your details and booking terms before checkout. The fee is credited toward your final service bill.</p></li><li><strong>' . (!empty($svc['assessment']) ? 'Assessment, then your quote' : 'Approve your service quote') . '</strong><p>' . (!empty($svc['assessment']) ? 'Bring your vehicle to our Nairobi garage for the 60-minute assessment. We confirm compatibility, work, final price and timing before proceeding.' : 'We confirm the work and final price before starting. You pay the remaining service bill at the service.') . '</p></li></ol></section></article><aside>' . sa_gc_journey_summary($slug) . '<a class="sa-card-button sa-journey-book" href="' . esc_url(sa_svc_book_url($slug)) . '">' . (!empty($svc['assessment']) ? 'Book an assessment' : 'Book this service') . '</a><p class="sa-journey-fine">Final work and price confirmed before work starts. <a href="' . esc_url(home_url('/booking-terms/')) . '">Booking terms</a></p>' . sa_svc_address_html(true) . '</aside></div></main>';
    get_footer(); exit;
}, 5);
add_action('template_redirect', static function (): void {
    if (get_query_var('sa_svc_view') !== 'book') { return; }
    $slug = sanitize_key((string) ($_GET['service'] ?? ''));
    ob_start(static function (string $html) use ($slug): string {
        $summary = sa_gc_journey_summary($slug);
        if (!empty(sa_svc_catalog()[$slug]['assessment'])) {
            $html = preg_replace('/<div class="sa-fw" data-sa-fw>.*?<div class="sa-svc-choices">/s', '<div class="sa-svc-choices">', $html);
        }
        $html = str_replace('<ul class="sa-svc-trust">', $summary . '<ul class="sa-svc-trust">', $html);
        return $html;
    });
}, 2);
add_filter('body_class', static function (array $classes): array {
    $oid = absint(get_query_var('order-pay')); $order = $oid ? wc_get_order($oid) : null;
    if ($order && $order->get_meta('_sa_booking_fee_for')) { $classes[] = 'sa-service-payment'; }
    return $classes;
});
add_action('before_woocommerce_pay', static function (): void {
    $oid = absint(get_query_var('order-pay')); $order = $oid ? wc_get_order($oid) : null;
    if (!$order || !($bid = (int) $order->get_meta('_sa_booking_fee_for'))) { return; }
    // The payment page's normal order-key validation owns access to this output.
    $key = (string) ($_GET['key'] ?? '');
    if ($key === '' || !hash_equals((string) $order->get_order_key(), $key)) { return; }
    $slug = (string) get_post_meta($bid, '_sa_bk_service', true);
    echo sa_gc_journey_summary($slug);
    echo '<p class="sa-journey-fine">The order total below is the booking fee payable now, not the full service price. Your final work and remaining bill are quoted separately. <a href="' . esc_url(home_url('/booking-terms/')) . '">Booking terms</a></p>';
}, 8);

/* Software assessments are not a parts-fitting booking. Keep the parts waiver
 * available for its original fitting workflow, never for an assessment. */
function sa_gc_assessment_request_guard(): void {
    $service = sanitize_key((string) ($_POST['service'] ?? ''));
    if (!empty(sa_svc_catalog()[$service]['assessment'])) {
        unset($_POST['fw_on'], $_POST['fw_order'], $_POST['fw_key'], $_POST['fw_contact'], $_POST['fw_item']);
    }
}
add_action('admin_post_sa_book_service', 'sa_gc_assessment_request_guard', 1);
add_action('admin_post_nopriv_sa_book_service', 'sa_gc_assessment_request_guard', 1);

add_filter('script_loader_src', static function ($src, $handle) {
    if ($handle === 'sa-services') { return add_query_arg('ver', (string) filemtime(SA_CORE_DIR . 'assets/services.js'), $src); }
    return $src;
}, 20, 2);

<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static page bodies — rebranded for Supreme Autoparts / Kenya.
 * Adapted from supreme-mods.com policies without inventing fake legal claims.
 *
 * @return array<string, array{title:string,content:string}>
 */
function sa_core_page_definitions(): array
{
    return [
        'about-us' => [
            'title'   => 'About Us',
            'content' => <<<'HTML'
<p>Welcome to <strong>Supreme Autoparts</strong>, your destination for performance parts and accessories for cars, trucks, and SUVs. We are committed to competitive prices and helpful service across our product range.</p>
<p>Supreme Autoparts is built by enthusiasts for enthusiasts. We aim to supply just about any car part you might need for popular American, European, and Asian vehicles. If you are searching for something specific that is not listed online, please contact us — odds are we can help source it.</p>
<p>Thanks for reading. We look forward to getting quality parts to you soon.</p>
<p><em>supremeautoparts.co.ke</em></p>
HTML,
        ],
        'contact' => [
            'title'   => 'Contact',
            'content' => <<<'HTML'
<p>Have a question about fitment, shipping to Kenya, or a special order? Reach out and our team will help.</p>
<ul>
<li><strong>Website:</strong> supremeautoparts.co.ke</li>
<li><strong>Email:</strong> support@supremeautoparts.co.ke (update this address in WordPress before launch)</li>
<li><strong>Hours:</strong> Monday–Friday, business hours (Africa/Nairobi)</li>
</ul>
<p>For order issues, include your order number and vehicle year/make/model.</p>
HTML,
        ],
        'free-shipping' => [
            'title'   => 'Free Shipping Details',
            'content' => <<<'HTML'
<p>Qualifying products may be eligible for free shipping when your order meets the store threshold (configured in store settings; default placeholder <strong>KES 15,000</strong> — confirm before launch).</p>
<p><strong>Important adaptations for Kenya:</strong> The source store’s free-shipping offer applied to contiguous US shipping. On supremeautoparts.co.ke, free-shipping eligibility, carriers, and excluded oversized/direct-ship items will be defined by Supreme Autoparts’ live shipping configuration in WooCommerce. Do not assume US contiguous rules apply.</p>
<ul>
<li>Oversized or heavy items may be excluded unless otherwise noted on the product page.</li>
<li>Direct-ship / supplier-fulfilled items may carry separate shipping charges.</li>
<li>If a product page does not indicate free-shipping eligibility, standard shipping rates apply.</li>
</ul>
<p>Inspect parts on arrival and contact us promptly if anything is incorrect or damaged.</p>
HTML,
        ],
        'price-match' => [
            'title'   => 'Price Match Policy',
            'content' => <<<'HTML'
<p>Supreme Autoparts aims to offer competitive pricing on aftermarket and performance parts.</p>
<p>If you find an identical in-stock item from an authorized retailer at a lower price, contact us with a link to the competing offer before you purchase. We will review eligibility (same brand, part number, condition, and fulfillment terms). Price match is not guaranteed for marketplace listings, auction sites, clearance/closeout, or items we cannot verify.</p>
<p>Final decisions rest with Supreme Autoparts. Contact details are on the Contact page.</p>
HTML,
        ],
        'returns' => [
            'title'   => 'Returns',
            'content' => <<<'HTML'
<p>Supreme Autoparts accepts returns of <strong>unused</strong> products within <strong>60 days</strong> of the order date, subject to the conditions below (adapted from the source store’s return framework and rebranded).</p>
<ul>
<li>Returns within 60 days may be eligible for a refund to the original payment method.</li>
<li>After 60 days, non-stocked items are accepted only at our discretion; accepted late returns may receive store credit only.</li>
<li>Customers are responsible for return shipping costs and fees unless otherwise stated. Original outbound shipping is typically non-refundable.</li>
<li>Products must be new, unused, and in original packaging with labeling and hardware.</li>
<li>Returns are subject to a restocking fee of <strong>20%</strong> (supplier drop-ship items may incur additional supplier restocking fees).</li>
<li>An RMA must be issued <strong>before</strong> returning any product.</li>
<li>No returns on tools, electrical, installed, clearance, or special-order products unless required by applicable law.</li>
</ul>
<p>Damaged-in-transit returns: contact us with photos; carrier claims may apply. This page is informational — confirm final return terms with support before shipping goods back.</p>
HTML,
        ],
        'privacy-policy' => [
            'title'   => 'Privacy Policy',
            'content' => <<<'HTML'
<p><strong>Supreme Autoparts</strong> operates supremeautoparts.co.ke. Please review this privacy policy carefully. By using the Website, you agree to the practices described here; if you do not agree, please do not use the Website.</p>
<p>We make reasonable efforts to protect your information, but no server or Internet transmission is 100% secure or error-free. We do not share your information except as described below.</p>
<h2>1. Information Collected</h2>
<p>When you send or enter information on our Website, we may store it. This may include your name, phone number, mailing address, email address, shipping address, and billing information needed to process orders. We may also collect technical data such as IP address, browser type, and pages visited.</p>
<h2>2. How Your Information Is Used</h2>
<p>We use information to process orders, provide customer support, improve the Website, prevent fraud, and (where permitted) communicate about products or promotions. You may opt out of marketing emails at any time.</p>
<h2>3. Access and Choices</h2>
<p>You may request access to or correction of your personal information by contacting us. Account holders can update many details via My Account.</p>
<h2>4. Cookies and Similar Technologies</h2>
<p>We use cookies and similar technologies for cart functionality, analytics, and preferences. You can control cookies through your browser settings; disabling cookies may affect checkout.</p>
<h2>5. Children’s Privacy</h2>
<p>The Website is not directed at children under 16. We do not knowingly collect personal information from children.</p>
<h2>6. External Sites</h2>
<p>Links to third-party sites are provided for convenience. Their privacy practices are their own.</p>
<h2>7. International Visitors</h2>
<p>If you access the site from outside Kenya, you understand information may be processed in Kenya or other locations where our service providers operate.</p>
<h2>8. Changes</h2>
<p>We may update this policy. Continued use after changes constitutes acceptance of the updated policy.</p>
<h2>9. Contact</h2>
<p>Questions about privacy: use the Contact page on supremeautoparts.co.ke.</p>
<p><em>This policy is adapted from the source storefront’s published privacy framework and rebranded for Supreme Autoparts. Have counsel review before production launch.</em></p>
HTML,
        ],
        'terms' => [
            'title'   => 'Terms of Service',
            'content' => <<<'HTML'
<p><strong>TERMS AND CONDITIONS</strong></p>
<p>Please read these terms carefully before using supremeautoparts.co.ke. By accessing or using this site, you agree to these terms and other applicable law. If you do not agree, do not use this site.</p>
<h2>Copyright</h2>
<p>Site content (text, graphics, code) is protected by copyright and related laws and is the property of Supreme Autoparts / supremeautoparts.co.ke unless otherwise noted. You may electronically copy or print portions solely to place an order or for personal non-commercial use. Other reproduction, distribution, or transmission is prohibited without authorization.</p>
<h2>Trademarks</h2>
<p>All trademarks, service marks, and trade names are trademarks or registered trademarks of their respective owners. Product brand names (e.g., WeatherTech, Bilstein) remain the property of those brands.</p>
<h2>Warranty Disclaimer</h2>
<p>This site and its materials are provided “as is” and “as available.” To the fullest extent permitted by law, Supreme Autoparts disclaims warranties of merchantability, fitness for a particular purpose, and non-infringement. Fitment information is believed accurate but verify against your vehicle before purchase and installation.</p>
<h2>Limitation of Liability</h2>
<p>To the fullest extent permitted by law, Supreme Autoparts is not liable for indirect, incidental, special, or consequential damages arising from use of the site or products, even if advised of the possibility of such damages.</p>
<h2>Typographical Errors</h2>
<p>We may correct pricing or availability errors and cancel orders placed at incorrect prices.</p>
<h2>Governing Law</h2>
<p>These terms are intended to be governed by the laws of Kenya, without regard to conflict-of-law principles, unless mandatory consumer protections require otherwise. Have local counsel confirm jurisdiction language before launch.</p>
<p><em>Adapted and rebranded from the source storefront’s published terms. Not a substitute for legal advice.</em></p>
HTML,
        ],
        'refund-policy' => [
            'title'   => 'Refund Policy',
            'content' => <<<'HTML'
<p>Refunds follow our Returns policy. In summary:</p>
<ul>
<li>Unused products returned within 60 days (with prior RMA) may be refunded to the original payment method.</li>
<li>Restocking fees (typically 20%) and non-refundable outbound shipping may apply.</li>
<li>Late or discretionary returns may receive store credit only.</li>
<li>Tools, electrical, installed, clearance, and special-order items are generally non-returnable.</li>
</ul>
<p>See the full <a href="/returns/">Returns</a> page for conditions. Kenya consumer protection laws may provide additional rights that cannot be waived.</p>
HTML,
        ],
        'shipping-policy' => [
            'title'   => 'Shipping Policy',
            'content' => <<<'HTML'
<p>Supreme Autoparts ships using carriers configured in WooCommerce. Delivery times and rates depend on destination within Kenya (and any international zones you enable).</p>
<ul>
<li><strong>Free shipping:</strong> May apply to qualifying orders over the configured threshold — see Free Shipping Details. Exclusions for oversized, heavy, or direct-ship items may apply.</li>
<li><strong>Direct-ship items:</strong> Some products ship from suppliers. Stock is not guaranteed until the order is placed; we will notify you if an item cannot ship promptly.</li>
<li><strong>Inspect on arrival:</strong> Check parts for accuracy before installation. Contact us immediately if boxes are mislabeled or contents are wrong.</li>
<li><strong>Insurance:</strong> Shipping prices may not include optional insurance unless stated at checkout.</li>
</ul>
<p>The source storefront’s US-contiguous free-shipping rules do not automatically apply in Kenya. Configure live rates and messaging in WooCommerce before launch.</p>
HTML,
        ],
    ];
}

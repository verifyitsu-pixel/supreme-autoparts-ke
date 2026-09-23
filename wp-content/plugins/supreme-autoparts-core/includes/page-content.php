<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static page bodies — customer-facing copy for Supreme Autoparts (Kenya).
 *
 * @return array<string, array{title:string,content:string}>
 */
function sa_core_page_definitions(): array
{
    $email = 'calvin@supremeautoparts.co.ke';
    $site  = 'supremeautoparts.co.ke';

    return [
        'about-us' => [
            'title'   => 'About Us',
            'content' => <<<HTML
<p>Welcome to <strong>Supreme Autoparts</strong>, your destination for performance parts and accessories for cars, trucks, and SUVs. We serve buyers in the <strong>United States</strong> and <strong>Kenya</strong> with US-spec fitments, USD checkout, and responsive support.</p>
<p>Supreme Autoparts is built by enthusiasts for enthusiasts. We aim to supply parts for popular American, European, and Asian vehicles — especially trucks and SUVs. If you are searching for something specific that is not listed online, please contact us — odds are we can help source it.</p>
<p>Thanks for reading. We look forward to getting quality parts to you soon.</p>
<p><em>{$site}</em> · <a href="mailto:{$email}">{$email}</a></p>
HTML,
        ],
        'contact' => [
            'title'   => 'Contact',
            'content' => <<<HTML
<p>Have a question about fitment, shipping to Kenya, or a special order? Reach out and our team will help.</p>
<ul>
<li><strong>Website:</strong> {$site}</li>
<li><strong>WhatsApp / SMS:</strong> <a href="https://wa.me/254714498451">+254 714 498 451</a></li>
<li><strong>Email:</strong> <a href="mailto:{$email}">{$email}</a></li>
<li><strong>Hours:</strong> Monday–Friday, business hours (Africa/Nairobi)</li>
</ul>
<p>Looking for a part that is not listed? Use our <a href="/enquire/">Can&rsquo;t find a part?</a> form.</p>
<p>For order issues, include your order number and vehicle year/make/model.</p>
HTML,
        ],
        'enquire' => [
            'title'   => 'Can\'t find a part?',
            'content' => <<<HTML
<p>We are updating the catalogue. If you need a specific part for your vehicle, send an enquiry and we will check availability and pricing.</p>
<p>Fill in the form below, then choose WhatsApp, email, or SMS. Include product name, car / model, and year at minimum.</p>
[sa_enquire context="general"]
HTML,
        ],
        'free-shipping' => [
            'title'   => 'Free Shipping Details',
            'content' => <<<'HTML'
<p>Qualifying products may be eligible for complimentary shipping when your order meets the threshold shown in the site banner (USD equivalent ~$99, or the local amount displayed for your region). Always confirm the live figure on the banner and at checkout.</p>
<p>Free-shipping eligibility, carriers, and any exclusions for oversized or direct-ship items are defined by Supreme Autoparts’ live shipping settings. Always check the product page and checkout totals for the final shipping charge.</p>
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
            'content' => <<<HTML
<p>Supreme Autoparts aims to offer competitive pricing on aftermarket and performance parts.</p>
<p>If you find an identical in-stock item from an authorized retailer at a lower price, contact us at <a href="mailto:{$email}">{$email}</a> with a link to the competing offer before you purchase. We will review eligibility (same brand, part number, condition, and fulfillment terms). Price match is not guaranteed for marketplace listings, auction sites, clearance/closeout, or items we cannot verify.</p>
<p>Final decisions rest with Supreme Autoparts.</p>
HTML,
        ],
        'returns' => [
            'title'   => 'Returns',
            'content' => <<<HTML
<p>Supreme Autoparts accepts returns of <strong>unused</strong> products within <strong>60 days</strong> of the order date, subject to the conditions below.</p>
<ul>
<li>Returns within 60 days may be eligible for a refund to the original payment method.</li>
<li>After 60 days, non-stocked items are accepted only at our discretion; accepted late returns may receive store credit only.</li>
<li>Customers are responsible for return shipping costs and fees unless otherwise stated. Original outbound shipping is typically non-refundable.</li>
<li>Products must be new, unused, and in original packaging with labeling and hardware.</li>
<li>Returns are subject to a restocking fee of <strong>20%</strong> (supplier drop-ship items may incur additional supplier restocking fees).</li>
<li>An RMA must be issued <strong>before</strong> returning any product. Email <a href="mailto:{$email}">{$email}</a> with your order number to request an RMA.</li>
<li>No returns on tools, electrical, installed, clearance, or special-order products unless required by applicable law.</li>
</ul>
<p>Damaged-in-transit returns: contact us with photos; carrier claims may apply. Please confirm return instructions with support before shipping goods back.</p>
HTML,
        ],
        'privacy-policy' => [
            'title'   => 'Privacy Policy',
            'content' => <<<HTML
<p><strong>Supreme Autoparts</strong> operates {$site}. Please review this privacy policy carefully. By using the Website, you agree to the practices described here; if you do not agree, please do not use the Website.</p>
<p>We make reasonable efforts to protect your information, but no server or Internet transmission is 100% secure or error-free. We do not share your information except as described below.</p>
<h2>1. Information Collected</h2>
<p>When you send or enter information on our Website, we may store it. This may include your name, phone number, mailing address, email address, shipping address, and billing information needed to process orders. We may also collect technical data such as IP address, browser type, and pages visited.</p>
<h2>2. How Your Information Is Used</h2>
<p>We use information to process orders, provide customer support, improve the Website, prevent fraud, and (where permitted) communicate about products or promotions. You may opt out of marketing emails at any time.</p>
<h2>3. Access and Choices</h2>
<p>You may request access to or correction of your personal information by contacting <a href="mailto:{$email}">{$email}</a>. Account holders can update many details via My Account.</p>
<h2>4. Cookies and Similar Technologies</h2>
<p>We use cookies and similar technologies for cart functionality, analytics, and preferences. See our <a href="/cookie-policy/">Cookie Policy</a> for details. You can control cookies through your browser settings; disabling cookies may affect checkout.</p>
<h2>5. Children’s Privacy</h2>
<p>The Website is not directed at children under 16. We do not knowingly collect personal information from children.</p>
<h2>6. External Sites</h2>
<p>Links to third-party sites are provided for convenience. Their privacy practices are their own.</p>
<h2>7. International Visitors</h2>
<p>If you access the site from outside Kenya, you understand information may be processed in Kenya or other locations where our service providers operate.</p>
<h2>8. Data Policy</h2>
<p>We process personal data as needed to fulfil orders and operate the store. For a fuller summary of rights and safeguards, see our <a href="/data-policy/">Data Policy</a> page. Kenya’s Data Protection Act, 2019 may provide additional rights.</p>
<h2>9. Changes</h2>
<p>We may update this policy. Continued use after changes constitutes acceptance of the updated policy.</p>
<h2>10. Contact</h2>
<p>Questions about privacy: <a href="mailto:{$email}">{$email}</a> or the Contact page on {$site}.</p>
HTML,
        ],
        'terms' => [
            'title'   => 'Terms of Service',
            'content' => <<<HTML
<p><strong>TERMS AND CONDITIONS</strong></p>
<p>Please read these terms carefully before using {$site}. By accessing or using this site, you agree to these terms and other applicable law. If you do not agree, do not use this site.</p>
<h2>Copyright</h2>
<p>Site content (text, graphics, code) is protected by copyright and related laws and is the property of Supreme Autoparts / {$site} unless otherwise noted. You may electronically copy or print portions solely to place an order or for personal non-commercial use. Other reproduction, distribution, or transmission is prohibited without authorization.</p>
<h2>Trademarks</h2>
<p>All trademarks, service marks, and trade names are trademarks or registered trademarks of their respective owners. Product brand names (e.g., WeatherTech, Bilstein) remain the property of those brands.</p>
<h2>Accounts and Orders</h2>
<p>You are responsible for maintaining the confidentiality of your account credentials and for all activity under your account. Order acceptance is subject to product availability, payment authorization, and fraud checks. We may cancel or refuse orders that appear erroneous or abusive.</p>
<h2>Warranty Disclaimer</h2>
<p>This site and its materials are provided “as is” and “as available.” To the fullest extent permitted by law, Supreme Autoparts disclaims warranties of merchantability, fitness for a particular purpose, and non-infringement. Fitment information is believed accurate but verify against your vehicle before purchase and installation.</p>
<h2>Limitation of Liability</h2>
<p>To the fullest extent permitted by law, Supreme Autoparts is not liable for indirect, incidental, special, or consequential damages arising from use of the site or products, even if advised of the possibility of such damages.</p>
<h2>Typographical Errors</h2>
<p>We may correct pricing or availability errors and cancel orders placed at incorrect prices.</p>
<h2>Payments and Chargebacks</h2>
<p>By placing an order you agree to pay all charges. Unauthorized chargebacks or payment disputes are addressed under our <a href="/chargeback-policy/">Chargeback / Dispute Policy</a>.</p>
<h2>Governing Law</h2>
<p>These terms are governed by the laws of Kenya, without regard to conflict-of-law principles, except where mandatory consumer protections require otherwise.</p>
<p>Questions: <a href="mailto:{$email}">{$email}</a>.</p>
HTML,
        ],
        'refund-policy' => [
            'title'   => 'Refund Policy',
            'content' => <<<HTML
<p>Refunds follow our Returns policy. In summary:</p>
<ul>
<li>Unused products returned within 60 days (with prior RMA) may be refunded to the original payment method.</li>
<li>Restocking fees (typically 20%) and non-refundable outbound shipping may apply.</li>
<li>Late or discretionary returns may receive store credit only.</li>
<li>Tools, electrical, installed, clearance, and special-order items are generally non-returnable.</li>
</ul>
<p>See the full <a href="/returns/">Returns</a> page for conditions. Kenya consumer protection laws may provide additional rights that cannot be waived.</p>
<p>To start a refund or RMA request, email <a href="mailto:{$email}">{$email}</a> with your order number.</p>
HTML,
        ],
        'shipping-policy' => [
            'title'   => 'Shipping Policy',
            'content' => <<<HTML
<p>Supreme Autoparts ships using carriers available at checkout. Delivery times and rates depend on your destination within Kenya (and any international zones we enable).</p>
<ul>
<li><strong>Free shipping:</strong> May apply to qualifying orders over the configured threshold — see <a href="/free-shipping/">Free Shipping Details</a>. Exclusions for oversized, heavy, or direct-ship items may apply.</li>
<li><strong>Direct-ship items:</strong> Some products ship from suppliers. Stock is not guaranteed until the order is placed; we will notify you if an item cannot ship promptly.</li>
<li><strong>Inspect on arrival:</strong> Check parts for accuracy before installation. Contact us immediately if boxes are mislabeled or contents are wrong.</li>
<li><strong>Insurance:</strong> Shipping prices may not include optional insurance unless stated at checkout.</li>
</ul>
<p>Shipping questions: <a href="mailto:{$email}">{$email}</a>.</p>
HTML,
        ],
        'cookie-policy' => [
            'title'   => 'Cookie Policy',
            'content' => <<<HTML
<p>This Cookie Policy explains how <strong>Supreme Autoparts</strong> ({$site}) uses cookies and similar technologies.</p>
<h2>What are cookies?</h2>
<p>Cookies are small text files stored on your device when you visit a website. They help the site remember your cart, login session, preferences, and (where enabled) analytics.</p>
<h2>How we use cookies</h2>
<ul>
<li><strong>Essential:</strong> Cart, checkout, account login, security, and fraud prevention. These are required for the store to function.</li>
<li><strong>Preferences:</strong> Remember choices such as currency display or dismissed notices.</li>
<li><strong>Analytics:</strong> Understand traffic and improve the site (e.g. aggregated page views). We do not use analytics to sell your personal data.</li>
</ul>
<h2>Your choices</h2>
<p>Most browsers let you block or delete cookies. Blocking essential cookies may prevent login, cart, or checkout from working. For privacy questions, contact <a href="mailto:{$email}">{$email}</a>.</p>
<p>See also our <a href="/privacy-policy/">Privacy Policy</a> and <a href="/data-policy/">Data Policy</a> pages.</p>
HTML,
        ],
        'chargeback-policy' => [
            'title'   => 'Chargeback & Dispute Policy',
            'content' => <<<HTML
<p><strong>Supreme Autoparts</strong> takes payment disputes seriously. Please contact us before opening a chargeback with your bank or card issuer — we can usually resolve issues faster.</p>
<h2>Contact us first</h2>
<p>Email <a href="mailto:{$email}">{$email}</a> with your order number, the issue (wrong item, not received, damaged, unauthorized charge, etc.), and supporting photos if relevant. We aim to respond during business hours (Africa/Nairobi).</p>
<h2>Friendly resolution</h2>
<p>Depending on the case we may offer a replacement, partial refund, full refund, or RMA per our <a href="/returns/">Returns</a> and <a href="/refund-policy/">Refund</a> policies.</p>
<h2>Chargebacks</h2>
<ul>
<li>Filing a chargeback without first contacting us may delay resolution and can result in order cancellation or account review.</li>
<li>If a chargeback is filed, we may provide the card network with order evidence (invoices, delivery confirmation, correspondence, IP/device signals where available).</li>
<li>Fraudulent or abusive chargebacks may be contested and may lead to refusal of future orders.</li>
</ul>
<h2>Unauthorized transactions</h2>
<p>If you believe a charge is unauthorized, contact us immediately so we can investigate and secure the account.</p>
<p>Related: <a href="/terms/">Terms of Service</a>.</p>
HTML,
        ],
        'pay' => [
            'title'   => 'Pay',
            'content' => <<<'HTML'
[sa_open_pay]
HTML,
        ],
        'data-policy' => [
            'title'   => 'Data Policy',
            'content' => <<<HTML
<p>Supreme Autoparts processes personal data to operate {$site}, fulfil orders, and support customers. This page summarizes our approach under Kenya’s Data Protection Act, 2019 and related consumer protections. It complements our <a href="/privacy-policy/">Privacy Policy</a>.</p>
<h2>What we process</h2>
<p>Account and checkout details (name, email, phone, addresses), order history, payment status from our payment providers (we do not store full card numbers), and technical logs needed for security.</p>
<h2>Why we process it</h2>
<ul>
<li>Contract / order fulfilment</li>
<li>Legal obligations (tax, accounting, dispute response)</li>
<li>Legitimate interests (fraud prevention, service improvement)</li>
<li>Consent where required (e.g. marketing emails)</li>
</ul>
<h2>Your rights</h2>
<p>Subject to applicable law, you may request access, correction, deletion, or restriction of your personal data, and object to certain processing. Account holders can update many details under My Account. To exercise rights, email <a href="mailto:{$email}">{$email}</a>.</p>
<h2>Retention and security</h2>
<p>We retain order and account records as needed for legal and operational purposes, then delete or anonymize when no longer required. We use reasonable technical and organizational measures; no method of transmission is perfectly secure.</p>
<p>Processors (hosting, payment, email delivery) only receive data needed to perform their services.</p>
HTML,
        ],
        'guides' => [
            'title'   => 'Guides',
            'content' => <<<HTML
<p>Practical guides for <strong>US</strong> and <strong>Kenya</strong> customers shopping Supreme Autoparts — fitment, ordering, and shipping without the fluff.</p>
<ul>
<li><a href="/how-to-order/"><strong>How to order</strong></a> — cart, USD checkout, accounts</li>
<li><a href="/fitment-guide/"><strong>Fitment guide</strong></a> — year / make / model checks for US-spec trucks &amp; SUVs</li>
<li><a href="/shipping-to-kenya/"><strong>Shipping to Kenya</strong></a> — import delivery, free-shipping threshold, local support</li>
<li><a href="/auto-parts-kenya/"><strong>Auto parts Kenya</strong></a> — buy US-spec parts with Kenya support</li>
<li><a href="/performance-truck-parts/"><strong>Performance truck &amp; off-road</strong></a> — suspension, brakes, wheels, lighting</li>
</ul>
<p>Need a human? WhatsApp <a href="https://wa.me/254714498451">+254 714 498 451</a> or email <a href="mailto:{$email}">{$email}</a>.</p>
HTML,
        ],
        'how-to-order' => [
            'title'   => 'How to Order',
            'content' => <<<HTML
<p>Supreme Autoparts is built for buyers in the <strong>United States</strong> and <strong>Kenya</strong> (and other destinations we ship to). Catalog fitments skew US-spec — popular trucks, SUVs, and performance platforms — with <strong>USD checkout</strong> and local Kenya support on WhatsApp.</p>
<h2>1. Find the right part</h2>
<ol>
<li>Use <a href="/shop/">Shop</a> or search by brand / part name.</li>
<li>Open category hubs: <a href="/product-category/brakes/">Brakes</a>, <a href="/product-category/suspension/">Suspension</a>, <a href="/product-category/wheels/">Wheels</a>, and more.</li>
<li>Confirm <strong>year, make, model</strong> (and engine/trim when listed). See the <a href="/fitment-guide/">Fitment guide</a>.</li>
</ol>
<h2>2. Add to cart &amp; check out</h2>
<ul>
<li>Prices are charged in <strong>USD</strong> at payment. Geo display may show a local estimate for convenience.</li>
<li>Checkout as a guest or create a <a href="/my-account/">My Account</a> login to track orders and invoices.</li>
<li>Review shipping lines before paying — free shipping may apply above the banner threshold (see <a href="/free-shipping/">Free Shipping Details</a>).</li>
</ul>
<h2>3. After you order</h2>
<p>You will get email confirmation from <a href="mailto:{$email}">{$email}</a>. For fitment or delivery questions:</p>
<ul>
<li><strong>WhatsApp / SMS:</strong> <a href="https://wa.me/254714498451">+254 714 498 451</a></li>
<li><strong>Email:</strong> <a href="mailto:{$email}">{$email}</a></li>
</ul>
<p>Cannot find a listing? Use <a href="/enquire/">Can&rsquo;t find a part?</a> with vehicle details.</p>
<h2>Quick FAQ</h2>
<ul>
<li><strong>US buyers:</strong> Shop US-spec performance and aftermarket parts; confirm shipping to your address at checkout.</li>
<li><strong>Kenya buyers:</strong> Same catalog and USD payment — see <a href="/shipping-to-kenya/">Shipping to Kenya</a> and <a href="/auto-parts-kenya/">Auto parts Kenya</a>.</li>
</ul>
HTML,
        ],
        'shipping-to-kenya' => [
            'title'   => 'Shipping to Kenya',
            'content' => <<<HTML
<p>Ordering performance or replacement parts from abroad is normal for Kenya builds. Here is how Supreme Autoparts handles <strong>shipping to Kenya</strong>.</p>
<h2>What to expect</h2>
<ul>
<li><strong>Rates at checkout:</strong> Carrier options and totals depend on weight, dimensions, and destination. Always use the live checkout figure.</li>
<li><strong>Free shipping:</strong> Qualifying carts over the USD threshold in the site banner (often ~$99 equivalent) may ship complimentary — oversized, heavy, or supplier-direct items can be excluded. Details: <a href="/free-shipping/">Free Shipping</a> and <a href="/shipping-policy/">Shipping Policy</a>.</li>
<li><strong>Inspect on arrival:</strong> Check parts before installation; contact us immediately if the wrong item or damage shows up.</li>
</ul>
<h2>Duties &amp; import notes</h2>
<p>Customs duties, VAT, or brokerage for Kenya imports are the buyer&rsquo;s responsibility unless we explicitly state otherwise on an order. Keep your invoice handy for clearance. Questions before you buy: WhatsApp <a href="https://wa.me/254714498451">+254 714 498 451</a>.</p>
<h2>US buyers</h2>
<p>This page focuses on Kenya delivery. If you are ordering to a US address, select your shipping address at checkout — available methods appear there. Currency at payment remains <strong>USD</strong>.</p>
<h2>Support</h2>
<p>Email <a href="mailto:{$email}">{$email}</a> with your order number. Related guides: <a href="/how-to-order/">How to order</a> · <a href="/auto-parts-kenya/">Auto parts Kenya</a> · <a href="/fitment-guide/">Fitment</a>.</p>
HTML,
        ],
        'fitment-guide' => [
            'title'   => 'Fitment Guide',
            'content' => <<<HTML
<p>Wrong fitment is the #1 return driver. Spend two minutes here before you click buy — whether you are building a truck in the <strong>US</strong> or running an import platform in <strong>Kenya</strong>.</p>
<h2>Match these fields</h2>
<ol>
<li><strong>Year</strong> — cab/chassis changes mid-generation; do not assume “close enough.”</li>
<li><strong>Make &amp; model</strong> — F-150 vs F-250, Tacoma vs Tundra, Wrangler vs Gladiator, etc.</li>
<li><strong>Drive / cab / bed</strong> — 4WD, crew cab, bed length, and trim often change suspension and bumper kits.</li>
<li><strong>Engine / powertrain</strong> — exhaust, intake, and some drivetrain parts are engine-specific.</li>
</ol>
<h2>US-spec catalog</h2>
<p>Most listings follow North American fitment language (years and models as sold in the US). That is intentional: popular performance brands publish US applications first. If your vehicle is a grey import in Kenya, compare VIN/trim to the US twin carefully or ask us.</p>
<h2>When to ask us</h2>
<p>Send year, make, model, trim, and the part link (or SKU) to <a href="mailto:{$email}">{$email}</a> or WhatsApp <a href="https://wa.me/254714498451">+254 714 498 451</a>. For parts not listed, use <a href="/enquire/">Can&rsquo;t find a part?</a>.</p>
<p>Browse: <a href="/product-category/suspension/">Suspension</a> · <a href="/product-category/brakes/">Brakes</a> · <a href="/performance-truck-parts/">Performance truck parts</a>.</p>
HTML,
        ],
        'auto-parts-kenya' => [
            'title'   => 'Auto Parts Kenya',
            'content' => <<<HTML
<p>Looking for <strong>auto parts in Kenya</strong> without settling for random marketplace quality? Supreme Autoparts lets you order <strong>US-spec and popular aftermarket parts</strong> online — brakes, suspension, wheels, lighting, and more — with <strong>USD checkout</strong> and support that picks up WhatsApp.</p>
<h2>Why shop with us</h2>
<ul>
<li>Catalog built around real performance brands and fitments common on trucks and SUVs.</li>
<li>Secure USD payment; optional local currency display for readability.</li>
<li>Kenya-aware shipping narrative and human support: <a href="https://wa.me/254714498451">+254 714 498 451</a> · <a href="mailto:{$email}">{$email}</a>.</li>
</ul>
<h2>Popular starting points</h2>
<ul>
<li><a href="/product-category/brakes/">Brakes</a></li>
<li><a href="/product-category/suspension/">Suspension</a></li>
<li><a href="/product-category/wheels/">Wheels</a></li>
<li><a href="/product-category/lighting/">Lighting</a></li>
<li><a href="/performance-truck-parts/">Performance truck &amp; off-road</a></li>
</ul>
<h2>How ordering works</h2>
<p>Follow <a href="/how-to-order/">How to order</a>, confirm fitment with the <a href="/fitment-guide/">Fitment guide</a>, then read <a href="/shipping-to-kenya/">Shipping to Kenya</a> before you pay. Policies: <a href="/returns/">Returns</a> · <a href="/shipping-policy/">Shipping</a>.</p>
<p><em>US customers:</em> same storefront and catalog — choose your US shipping address at checkout.</p>
HTML,
        ],
        'performance-truck-parts' => [
            'title'   => 'Performance Truck & Off-Road Parts',
            'content' => <<<HTML
<p>Supreme Autoparts stocks <strong>performance truck and off-road parts</strong> aimed at serious drivers — lift-ready suspension, braking upgrades, wheels, lighting, drivetrain, and exterior armor. Fitment language follows <strong>US-spec</strong> applications used across North America and by import builders in Kenya.</p>
<h2>Shop by system</h2>
<ul>
<li><a href="/product-category/suspension/"><strong>Suspension</strong></a> — shocks, coils, kits</li>
<li><a href="/product-category/brakes/"><strong>Brakes</strong></a> — pads, rotors, big-brake related parts</li>
<li><a href="/product-category/wheels/"><strong>Wheels</strong></a> &amp; <a href="/product-category/tires/">Tires</a></li>
<li><a href="/product-category/lighting/"><strong>Lighting</strong></a> — driving lights and related</li>
<li><a href="/product-category/drivetrain/"><strong>Drivetrain</strong></a> · <a href="/product-category/exhaust/">Exhaust</a> · <a href="/product-category/exterior/">Exterior</a></li>
</ul>
<h2>US &amp; Kenya</h2>
<p>Checkout is <strong>USD</strong>. US buyers select domestic shipping at checkout when available; Kenya buyers should review <a href="/shipping-to-kenya/">Shipping to Kenya</a>. Support: WhatsApp <a href="https://wa.me/254714498451">+254 714 498 451</a> · <a href="mailto:{$email}">{$email}</a>.</p>
<p>New here? Start with <a href="/how-to-order/">How to order</a> and the <a href="/fitment-guide/">Fitment guide</a>.</p>
HTML,
        ],
    ];
}

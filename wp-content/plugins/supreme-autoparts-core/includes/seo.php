<?php
declare(strict_types=1);

/**
 * Lean technical SEO for Supreme Autoparts (US + Kenya discoverability).
 *
 * Pure PHP in wp_head — no SEO plugin JS. Complements WooCommerce Product /
 * BreadcrumbList JSON-LD; adds Organization, WebSite, Store, FAQ, OG/Twitter,
 * titles, meta descriptions, canonicals, robots, image alts, share helper.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Meta key for per-post title override. */
const SA_SEO_TITLE_META = '_sa_seo_title';
/** Meta key for per-post description override. */
const SA_SEO_DESC_META = '_sa_seo_description';
/** Meta key for optional FAQ JSON (array of {q,a}) on pages. */
const SA_SEO_FAQ_META = '_sa_seo_faq';

/**
 * Brand / store identity used in schema + OG.
 *
 * @return array{
 *   name:string,legal:string,url:string,email:string,phone:string,phone_e164:string,
 *   logo:string,og_image:string,same_as:array<int,string>,area_served:array<int,array<string,string>>
 * }
 */
function sa_seo_brand(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $url = home_url('/');
    $logo = '';
    if (defined('SA_THEME_URI')) {
        foreach (['logo-light.png', 'logo.png', 'logo-light.jpg', 'logo.jpg'] as $f) {
            $path = (defined('SA_THEME_DIR') ? SA_THEME_DIR : '') . '/assets/' . $f;
            if ($path !== '/assets/' . $f && is_readable($path)) {
                $logo = trailingslashit(SA_THEME_URI) . 'assets/' . $f;
                break;
            }
        }
    }
    if ($logo === '' && function_exists('sa_theme_logo_url')) {
        $logo = (string) sa_theme_logo_url(false);
    }
    if ($logo === '') {
        $logo = home_url('/wp-content/themes/supreme-autoparts/assets/logo.png');
    }
    $logo = set_url_scheme($logo, 'https');

    // Prefer a wide logo for OG (WhatsApp/Telegram); fall back to square icon.
    $og = $logo;
    if (defined('SA_THEME_DIR') && defined('SA_THEME_URI')) {
        foreach (['logo-light.jpg', 'logo.jpg', 'icon.png'] as $f) {
            if (is_readable(SA_THEME_DIR . '/assets/' . $f)) {
                $og = set_url_scheme(SA_THEME_URI . '/assets/' . $f, 'https');
                break;
            }
        }
    }

    $cached = [
        'name'         => 'Supreme Autoparts',
        'legal'        => 'Supreme Autoparts',
        'url'          => $url,
        'email'        => function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke',
        'phone'        => '+254 714 498 451',
        'phone_e164'   => '+254714498451',
        'logo'         => $logo,
        'og_image'     => $og,
        'same_as'      => array_values(array_filter([
            // Add social profile URLs here when claimed (Instagram, Facebook, X, YouTube).
        ])),
        'area_served'  => [
            ['@type' => 'Country', 'name' => 'United States'],
            ['@type' => 'Country', 'name' => 'Kenya'],
        ],
    ];

    return $cached;
}

/**
 * Google Search Console verification token (meta content=).
 * Env SA_GOOGLE_SITE_VERIFICATION wins; else option sa_google_site_verification.
 */
function sa_seo_google_verification(): string
{
    $env = getenv('SA_GOOGLE_SITE_VERIFICATION');
    if (is_string($env) && $env !== '') {
        return trim($env);
    }
    $opt = get_option('sa_google_site_verification', '');
    return is_string($opt) ? trim($opt) : '';
}

/**
 * Whether the current view should be noindexed (cart, checkout, account, thank-you, search).
 */
function sa_seo_should_noindex(): bool
{
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return false;
    }
    if (function_exists('is_cart') && is_cart()) {
        return true;
    }
    if (function_exists('is_checkout') && is_checkout()) {
        return true;
    }
    if (function_exists('is_account_page') && is_account_page()) {
        return true;
    }
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
        return true;
    }
    if (is_search() || is_404()) {
        return true;
    }
    // Utility pages that shouldn't rank.
    if (is_page(['cart', 'checkout', 'my-account', 'pay'])) {
        return true;
    }
    return (bool) apply_filters('sa_seo_should_noindex', false);
}

/**
 * Absolute canonical URL for the current request (respects WC/WP permalinks).
 */
function sa_seo_canonical_url(): string
{
    if (function_exists('is_singular') && is_singular()) {
        $url = get_permalink();
        return is_string($url) ? $url : home_url('/');
    }
    if (function_exists('is_product_category') && is_product_category()) {
        $term = get_queried_object();
        if ($term instanceof WP_Term) {
            $link = get_term_link($term);
            return is_string($link) ? $link : home_url('/');
        }
    }
    if (function_exists('is_product_tag') && is_product_tag()) {
        $term = get_queried_object();
        if ($term instanceof WP_Term) {
            $link = get_term_link($term);
            return is_string($link) ? $link : home_url('/');
        }
    }
    if (function_exists('is_shop') && is_shop()) {
        $shop = function_exists('wc_get_page_id') ? (int) wc_get_page_id('shop') : 0;
        if ($shop > 0) {
            $p = get_permalink($shop);
            return is_string($p) ? $p : home_url('/shop/');
        }
        return home_url('/shop/');
    }
    if (is_home() || is_front_page()) {
        return home_url('/');
    }
    if (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        if ($term instanceof WP_Term) {
            $link = get_term_link($term);
            return is_string($link) ? $link : home_url('/');
        }
    }
    global $wp;
    $req = isset($wp->request) ? (string) $wp->request : '';
    return home_url('/' . ltrim($req, '/'));
}

/**
 * Truncate plain text for meta/OG (word-safe).
 */
function sa_seo_clip(string $text, int $max = 160): string
{
    $text = wp_strip_all_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max - 1);
    $sp  = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > (int) ($max * 0.6)) {
        $cut = mb_substr($cut, 0, $sp);
    }
    return rtrim($cut, '.,;: ') . '…';
}

/**
 * Resolve title + description + image for the current view.
 *
 * @return array{title:string,description:string,image:string,type:string}
 */
function sa_seo_context(): array
{
    $brand = sa_seo_brand();
    $site  = $brand['name'];
    $img   = $brand['og_image'];
    $type  = 'website';

    $default_desc = 'Performance auto parts & accessories for cars, trucks, and SUVs. US-spec and popular fitments, USD checkout, shipping to Kenya and beyond. Shop brakes, suspension, wheels, and more at Supreme Autoparts.';

    $title = $site;
    $desc  = $default_desc;

    if (is_front_page() || is_home()) {
        $title = 'Auto Parts & Accessories | Cars, Trucks, SUVs — US Spec · Ship to Kenya | ' . $site;
        $desc  = 'Shop performance and aftermarket auto parts online. US-spec fitments for trucks and off-road builds, USD checkout via secure payments, WhatsApp support in Kenya (+254). Free shipping on qualifying orders.';
    } elseif (function_exists('is_product') && is_product()) {
        $product = function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
        $name    = get_the_title();
        $title   = $name . ' | ' . $site;
        if ($product instanceof WC_Product) {
            $type = 'product';
            $raw  = $product->get_short_description() ?: $product->get_description();
            if (is_string($raw) && $raw !== '') {
                $desc = sa_seo_clip($raw, 158);
            } else {
                $desc = sa_seo_clip($name . ' — genuine aftermarket part. USD pricing, ships to Kenya and international destinations. Order at Supreme Autoparts.', 158);
            }
            $thumb = (int) $product->get_image_id();
            if ($thumb > 0) {
                $src = wp_get_attachment_image_url($thumb, 'large');
                if (is_string($src) && $src !== '') {
                    $img = set_url_scheme($src, 'https');
                }
            } else {
                // CDN-only products may store URL in meta.
                $cdn = (string) get_post_meta($product->get_id(), '_sa_primary_image_url', true);
                if ($cdn === '') {
                    $cdn = (string) get_post_meta($product->get_id(), '_sa_image_url', true);
                }
                if ($cdn !== '' && preg_match('#^https?://#i', $cdn)) {
                    $img = set_url_scheme($cdn, 'https');
                }
            }
            $override_t = (string) get_post_meta($product->get_id(), SA_SEO_TITLE_META, true);
            $override_d = (string) get_post_meta($product->get_id(), SA_SEO_DESC_META, true);
            if ($override_t !== '') {
                $title = $override_t;
            }
            if ($override_d !== '') {
                $desc = sa_seo_clip($override_d, 160);
            }
        }
    } elseif (function_exists('is_product_category') && is_product_category()) {
        $term = get_queried_object();
        if ($term instanceof WP_Term) {
            $title = $term->name . ' Parts & Accessories | ' . $site;
            $tdesc = term_description($term);
            if (is_string($tdesc) && trim(wp_strip_all_tags($tdesc)) !== '') {
                $desc = sa_seo_clip($tdesc, 158);
            } else {
                $desc = sa_seo_clip(
                    'Shop ' . $term->name . ' for cars, trucks, and SUVs. US-spec and popular fitments, USD checkout, shipping to Kenya. Browse ' . $term->name . ' at Supreme Autoparts.',
                    158
                );
            }
            if (function_exists('sa_category_image_url')) {
                $cat_img = sa_category_image_url($term->slug);
                if ($cat_img !== '') {
                    $img = set_url_scheme($cat_img, 'https');
                }
            }
        }
    } elseif (function_exists('is_shop') && is_shop()) {
        $title = 'Shop Auto Parts Online | Performance & Aftermarket | ' . $site;
        $desc  = 'Browse brakes, suspension, wheels, lighting, and more. US-spec truck and off-road parts with USD checkout and shipping to Kenya. Find your part at Supreme Autoparts.';
    } elseif (is_singular(['page', 'post'])) {
        $id    = get_the_ID();
        $name  = get_the_title();
        $title = $name . ' | ' . $site;
        $override_t = (string) get_post_meta($id, SA_SEO_TITLE_META, true);
        $override_d = (string) get_post_meta($id, SA_SEO_DESC_META, true);
        if ($override_t !== '') {
            $title = $override_t;
        }
        if ($override_d !== '') {
            $desc = sa_seo_clip($override_d, 160);
        } else {
            $excerpt = get_the_excerpt($id);
            if (is_string($excerpt) && trim($excerpt) !== '') {
                $desc = sa_seo_clip($excerpt, 158);
            } else {
                $content = get_post_field('post_content', $id);
                if (is_string($content) && $content !== '') {
                    $desc = sa_seo_clip($content, 158);
                }
            }
        }
        if (has_post_thumbnail($id)) {
            $src = get_the_post_thumbnail_url($id, 'large');
            if (is_string($src) && $src !== '') {
                $img = set_url_scheme($src, 'https');
            }
        }
        // Known high-intent pages — stronger dual-market titles when no override.
        $slug = get_post_field('post_name', $id);
        if ($override_t === '' && is_string($slug)) {
            $special = [
                'shipping-to-kenya' => 'Shipping Auto Parts to Kenya | Import & Delivery | ' . $site,
                'how-to-order'      => 'How to Order Auto Parts Online (US & Kenya) | ' . $site,
                'fitment-guide'     => 'Vehicle Fitment Guide | Year / Make / Model | ' . $site,
                'auto-parts-kenya'  => 'Buy Auto Parts in Kenya | Import US Spec Parts | ' . $site,
                'performance-truck-parts' => 'Performance Truck & Off-Road Parts | US Spec | ' . $site,
                'guides'            => 'Guides: Ordering, Fitment & Shipping | ' . $site,
                'contact'           => 'Contact Supreme Autoparts | WhatsApp +254 · Email | ' . $site,
                'about-us'          => 'About Supreme Autoparts | US Spec Parts · Kenya Support | ' . $site,
            ];
            if (isset($special[$slug])) {
                $title = $special[$slug];
            }
        }
    } elseif (is_singular()) {
        $title = get_the_title() . ' | ' . $site;
    }

    return [
        'title'       => $title,
        'description' => $desc,
        'image'       => $img,
        'type'        => $type,
    ];
}

/**
 * Document title parts — unique templates; allow post meta override.
 *
 * @param array<string,string> $parts
 * @return array<string,string>
 */
function sa_seo_document_title_parts(array $parts): array
{
    if (is_admin() || wp_doing_ajax()) {
        return $parts;
    }
    $ctx = sa_seo_context();
    // Use our full title string; clear tagline to avoid "Title — Tagline — Site" bloat.
    $parts['title']   = $ctx['title'];
    $parts['tagline'] = '';
    $parts['site']    = '';
    // If title already includes brand, leave as single segment.
    return $parts;
}
add_filter('document_title_parts', 'sa_seo_document_title_parts', 20);

add_filter('document_title_separator', static fn (): string => '–');

/**
 * Emit robots, description, canonical, OG, Twitter, verification, JSON-LD.
 */
function sa_seo_wp_head(): void
{
    if (is_admin()) {
        return;
    }

    $ctx   = sa_seo_context();
    $brand = sa_seo_brand();
    $url   = sa_seo_canonical_url();
    $noindex = sa_seo_should_noindex();

    // Robots
    if ($noindex) {
        echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
    } else {
        echo '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />' . "\n";
    }

    // Meta description
    if ($ctx['description'] !== '') {
        printf(
            '<meta name="description" content="%s" />' . "\n",
            esc_attr($ctx['description'])
        );
    }

    // Canonical (skip if noindex utility — still helpful for cart/checkout self-ref)
    printf('<link rel="canonical" href="%s" />' . "\n", esc_url($url));

    // Google site verification
    $gsc = sa_seo_google_verification();
    if ($gsc !== '') {
        printf(
            '<meta name="google-site-verification" content="%s" />' . "\n",
            esc_attr($gsc)
        );
    }

    // Geo / market hints (single English site — no hreflang duplicates)
    echo '<meta name="geo.region" content="US" />' . "\n";
    echo '<meta name="geo.region" content="KE" />' . "\n";

    // Open Graph
    $og_type = $ctx['type'] === 'product' ? 'product' : 'website';
    printf('<meta property="og:locale" content="en_US" />' . "\n");
    printf('<meta property="og:locale:alternate" content="en_KE" />' . "\n");
    printf('<meta property="og:type" content="%s" />' . "\n", esc_attr($og_type));
    printf('<meta property="og:site_name" content="%s" />' . "\n", esc_attr($brand['name']));
    printf('<meta property="og:title" content="%s" />' . "\n", esc_attr($ctx['title']));
    printf('<meta property="og:description" content="%s" />' . "\n", esc_attr($ctx['description']));
    printf('<meta property="og:url" content="%s" />' . "\n", esc_url($url));
    if ($ctx['image'] !== '') {
        printf('<meta property="og:image" content="%s" />' . "\n", esc_url($ctx['image']));
        printf('<meta property="og:image:alt" content="%s" />' . "\n", esc_attr($ctx['title']));
    }

    // Product OG extras
    if ($ctx['type'] === 'product' && function_exists('wc_get_product')) {
        $product = wc_get_product(get_the_ID());
        if ($product instanceof WC_Product) {
            $price = $product->get_price();
            if ($price !== '' && $price !== null) {
                printf('<meta property="product:price:amount" content="%s" />' . "\n", esc_attr((string) $price));
                printf('<meta property="product:price:currency" content="USD" />' . "\n");
            }
            $avail = $product->is_in_stock()
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock';
            printf('<meta property="product:availability" content="%s" />' . "\n", esc_attr($avail));
        }
    }

    // Twitter / X card
    echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
    printf('<meta name="twitter:title" content="%s" />' . "\n", esc_attr($ctx['title']));
    printf('<meta name="twitter:description" content="%s" />' . "\n", esc_attr($ctx['description']));
    if ($ctx['image'] !== '') {
        printf('<meta name="twitter:image" content="%s" />' . "\n", esc_url($ctx['image']));
    }

    // JSON-LD graph (Organization + WebSite always; Store; FAQ when present)
    // WooCommerce already emits Product + BreadcrumbList — do not duplicate.
    if (!$noindex) {
        sa_seo_print_json_ld($ctx, $brand, $url);
    }
}
add_action('wp_head', 'sa_seo_wp_head', 1);

/**
 * Print Organization / WebSite / Store / FAQ JSON-LD.
 *
 * @param array{title:string,description:string,image:string,type:string} $ctx
 * @param array<string,mixed> $brand
 */
function sa_seo_print_json_ld(array $ctx, array $brand, string $url): void
{
    $org_id    = trailingslashit($brand['url']) . '#organization';
    $website_id = trailingslashit($brand['url']) . '#website';
    $store_id  = trailingslashit($brand['url']) . '#store';

    $org = [
        '@type'       => 'Organization',
        '@id'         => $org_id,
        'name'        => $brand['name'],
        'url'         => $brand['url'],
        'email'       => $brand['email'],
        'telephone'   => $brand['phone_e164'],
        'logo'        => [
            '@type' => 'ImageObject',
            'url'   => $brand['logo'],
        ],
        'areaServed'  => $brand['area_served'],
        'description' => 'Online auto parts store for performance and aftermarket parts. US-spec fitments for cars, trucks, and SUVs; USD checkout; shipping and support for Kenya and international buyers.',
    ];
    if (!empty($brand['same_as'])) {
        $org['sameAs'] = $brand['same_as'];
    }

    $website = [
        '@type'           => 'WebSite',
        '@id'             => $website_id,
        'url'             => $brand['url'],
        'name'            => $brand['name'],
        'description'     => 'Performance auto parts & accessories — US and Kenya.',
        'publisher'       => ['@id' => $org_id],
        'inLanguage'      => 'en',
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => home_url('/?s={search_term_string}&post_type=product'),
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];

    // Store (not Kenya-only LocalBusiness) — dual market areaServed.
    $store = [
        '@type'       => ['Store', 'Organization'],
        '@id'         => $store_id,
        'name'        => $brand['name'],
        'url'         => $brand['url'],
        'email'       => $brand['email'],
        'telephone'   => $brand['phone_e164'],
        'image'       => $brand['logo'],
        'priceRange'  => '$$',
        'currenciesAccepted' => 'USD',
        'paymentAccepted'    => 'Credit Card, Debit Card',
        'areaServed'  => $brand['area_served'],
        'address'     => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Nairobi',
            'addressCountry'  => 'KE',
        ],
        'contactPoint' => [
            [
                '@type'       => 'ContactPoint',
                'telephone'   => $brand['phone_e164'],
                'contactType' => 'customer service',
                'email'       => $brand['email'],
                'areaServed'  => ['US', 'KE'],
                'availableLanguage' => ['English'],
            ],
        ],
        'parentOrganization' => ['@id' => $org_id],
    ];

    $graph = [$org, $website, $store];

    // FAQPage when page has FAQs (meta or known guide slugs).
    $faqs = sa_seo_faqs_for_current();
    if ($faqs !== []) {
        $main = [];
        foreach ($faqs as $i => $row) {
            $q = isset($row['q']) ? (string) $row['q'] : '';
            $a = isset($row['a']) ? (string) $row['a'] : '';
            if ($q === '' || $a === '') {
                continue;
            }
            $main[] = [
                '@type'          => 'Question',
                'name'           => $q,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => wp_strip_all_tags($a),
                ],
            ];
        }
        if ($main !== []) {
            $graph[] = [
                '@type'      => 'FAQPage',
                '@id'        => $url . '#faq',
                'mainEntity' => $main,
            ];
        }
    }

    // On front page only: WebPage entity for richer home rich results.
    if (is_front_page()) {
        $graph[] = [
            '@type'       => 'WebPage',
            '@id'         => trailingslashit($brand['url']) . '#webpage',
            'url'         => $brand['url'],
            'name'        => $ctx['title'],
            'description' => $ctx['description'],
            'isPartOf'    => ['@id' => $website_id],
            'about'       => ['@id' => $org_id],
            'inLanguage'  => 'en',
        ];
    }

    $payload = [
        '@context' => 'https://schema.org',
        '@graph'   => $graph,
    ];

    $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        return;
    }
    echo '<script type="application/ld+json" id="sa-seo-graph">' . $json . '</script>' . "\n";
}

/**
 * FAQs for current page (from post meta or built-in guide FAQs).
 *
 * @return array<int, array{q:string,a:string}>
 */
function sa_seo_faqs_for_current(): array
{
    if (!is_singular('page')) {
        return [];
    }
    $id = get_the_ID();
    $raw = get_post_meta($id, SA_SEO_FAQ_META, true);
    if (is_array($raw) && $raw !== []) {
        return $raw;
    }
    // Try JSON string.
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $slug = (string) get_post_field('post_name', $id);
    $map  = sa_seo_builtin_faqs();
    return $map[$slug] ?? [];
}

/**
 * Built-in FAQs for guide / key pages (US + Kenya).
 *
 * @return array<string, array<int, array{q:string,a:string}>>
 */
function sa_seo_builtin_faqs(): array
{
    $email = 'calvin@supremeautoparts.co.ke';
    $wa    = '+254 714 498 451';

    return [
        'how-to-order' => [
            [
                'q' => 'How do I order from Supreme Autoparts?',
                'a' => 'Browse the shop or search by part name, add items to your cart, and check out in USD. Create an account or checkout as a guest. You will receive order confirmation by email.',
            ],
            [
                'q' => 'What currency am I charged in?',
                'a' => 'Checkout is in USD. Depending on your location, the site may display an approximate local amount for convenience — the charge at payment is USD.',
            ],
            [
                'q' => 'Can I order from the United States and from Kenya?',
                'a' => 'Yes. The catalog is US-spec / popular North American fitments. Buyers in the US and Kenya (and other destinations we ship to) can order online. Kenya customers can also reach us on WhatsApp at ' . $wa . ' or email ' . $email . '.',
            ],
            [
                'q' => 'What if I cannot find my part?',
                'a' => 'Use the “Can’t find a part?” enquire form with year, make, model, and the part you need. We will check availability and pricing.',
            ],
        ],
        'shipping-to-kenya' => [
            [
                'q' => 'Do you ship auto parts to Kenya?',
                'a' => 'Yes. Supreme Autoparts supports shipping to Kenya. Rates and timelines appear at checkout and depend on weight, size, and carrier. See our Shipping Policy for details.',
            ],
            [
                'q' => 'Is there free shipping?',
                'a' => 'Qualifying orders over the threshold shown in the site banner (USD equivalent, often around $99) may include complimentary shipping. Oversized or direct-ship items can be excluded — always confirm on the product page and at checkout.',
            ],
            [
                'q' => 'Who do I contact about a Kenya delivery?',
                'a' => 'Email ' . $email . ' or WhatsApp/SMS ' . $wa . ' with your order number. Business hours are Africa/Nairobi.',
            ],
        ],
        'fitment-guide' => [
            [
                'q' => 'How do I confirm a part fits my vehicle?',
                'a' => 'Match year, make, model, and engine/trim notes on the product title and description. US-spec fitment data is common for trucks and performance builds. If unsure, send us the VIN or exact trim before ordering.',
            ],
            [
                'q' => 'Are these parts OEM or aftermarket?',
                'a' => 'We sell aftermarket and performance parts from brands stocked in the catalog (for example suspension, brakes, wheels). Brand and part number are listed on the product page when available.',
            ],
            [
                'q' => 'I drive in Kenya on a US-spec or import vehicle — will parts fit?',
                'a' => 'Many popular trucks and SUVs in Kenya share US or global platforms. Confirm year/make/model carefully; contact ' . $email . ' with your vehicle details if the listing is unclear.',
            ],
        ],
        'auto-parts-kenya' => [
            [
                'q' => 'Can I buy US-spec auto parts and have them shipped to Kenya?',
                'a' => 'Yes. Shop online in USD, complete checkout, and we arrange shipping per the live Shipping Policy. Local support is available on WhatsApp ' . $wa . '.',
            ],
            [
                'q' => 'Do you have a physical walk-in store in Nairobi?',
                'a' => 'Supreme Autoparts is primarily an online storefront with customer service reachable by email and WhatsApp. Contact us for order status and fitment help.',
            ],
        ],
        'performance-truck-parts' => [
            [
                'q' => 'What truck and off-road categories do you carry?',
                'a' => 'Browse suspension, brakes, wheels, tires, lighting, drivetrain, exhaust, and exterior accessories — including popular performance brands for trucks and SUVs.',
            ],
            [
                'q' => 'Do you ship performance parts within the United States?',
                'a' => 'Checkout supports USD payments for customers we ship to, including US destinations where enabled at checkout. Confirm shipping options and rates on the cart/checkout pages for your address.',
            ],
        ],
        'guides' => [
            [
                'q' => 'Where should I start if I am new to ordering?',
                'a' => 'Read How to Order, then the Fitment Guide. Kenya-bound orders should also review Shipping to Kenya. Contact support anytime on WhatsApp or email.',
            ],
        ],
        'contact' => [
            [
                'q' => 'What is the fastest way to reach Supreme Autoparts?',
                'a' => 'WhatsApp or SMS ' . $wa . ' for quick fitment and order questions. Email ' . $email . ' for RMA, invoices, and detailed requests. Hours: Monday–Friday, Africa/Nairobi business hours.',
            ],
        ],
        'free-shipping' => [
            [
                'q' => 'What is the free shipping threshold?',
                'a' => 'Complimentary shipping may apply when your order meets the USD threshold shown in the site banner (often around $99 equivalent). Exclusions can apply for oversized or supplier-direct items.',
            ],
        ],
    ];
}

/**
 * Fill empty product image alt attributes with the product title.
 *
 * @param array<string,string> $attr
 * @param WP_Post $attachment
 * @return array<string,string>
 */
function sa_seo_attachment_image_attributes(array $attr, $attachment, $size = null): array
{
    $alt = isset($attr['alt']) ? trim((string) $attr['alt']) : '';
    if ($alt !== '') {
        return $attr;
    }
    if (function_exists('is_product') && (is_product() || is_shop() || (function_exists('is_product_category') && is_product_category()))) {
        $title = get_the_title();
        if (is_string($title) && $title !== '') {
            $attr['alt'] = wp_strip_all_tags($title);
        }
    } elseif ($attachment instanceof WP_Post) {
        $parent = (int) $attachment->post_parent;
        if ($parent > 0 && get_post_type($parent) === 'product') {
            $attr['alt'] = wp_strip_all_tags(get_the_title($parent));
        }
    }
    return $attr;
}
add_filter('wp_get_attachment_image_attributes', 'sa_seo_attachment_image_attributes', 20, 3);

/**
 * WooCommerce product loop/thumbnail alt fallback.
 *
 * @param string $image
 * @param WC_Product|null $product
 */
add_filter('woocommerce_product_get_image', static function ($image, $product = null) {
    // String HTML — only patch empty alt if we can do so safely.
    if (!is_string($image) || $image === '' || !($product instanceof WC_Product)) {
        return $image;
    }
    if (stripos($image, 'alt=""') === false && !preg_match('/\balt=([\'"])\s*\1/', $image)) {
        return $image;
    }
    $alt = esc_attr(wp_strip_all_tags($product->get_name()));
    return preg_replace('/alt=(["\'])\s*\1/', 'alt="' . $alt . '"', $image, 1) ?: $image;
}, 20, 2);

/**
 * Exclude thin/utility pages from WP core sitemaps.
 *
 * @param array<int, WP_Post> $posts
 * @return array<int, WP_Post>
 */
function sa_seo_filter_sitemap_posts($posts, $post_type = '')
{
    if (!is_array($posts)) {
        return $posts;
    }
    $block = ['cart', 'checkout', 'my-account', 'pay', 'home'];
    return array_values(array_filter($posts, static function ($p) use ($block) {
        if (!$p instanceof WP_Post) {
            return false;
        }
        return !in_array($p->post_name, $block, true);
    }));
}
add_filter('wp_sitemaps_posts_entry', static function ($entry, $post) {
    if ($post instanceof WP_Post && in_array($post->post_name, ['cart', 'checkout', 'my-account', 'pay'], true)) {
        return [];
    }
    return $entry;
}, 10, 2);

/**
 * Drop users sitemap (thin) — products/categories/pages remain.
 *
 * @param array<string, WP_Sitemaps_Provider> $providers
 * @return array<string, WP_Sitemaps_Provider>
 */
add_filter('wp_sitemaps_add_provider', static function ($provider, $name) {
    if ($name === 'users') {
        return false;
    }
    return $provider;
}, 10, 2);

/**
 * PDP “Share this part” — native share / copy link (lightweight, no SDK).
 */
function sa_seo_render_share_button(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (!function_exists('is_product') || !is_product()) {
        return;
    }
    $done = true;
    $url   = get_permalink();
    $title = get_the_title();
    if (!is_string($url) || $url === '') {
        return;
    }
    $wa = 'https://wa.me/?text=' . rawurlencode($title . ' ' . $url);
    ?>
    <div class="sa-share" data-sa-share>
      <span class="sa-share__label"><?php esc_html_e('Share this part', 'supreme-autoparts-core'); ?></span>
      <div class="sa-share__actions">
        <button type="button" class="sa-btn sa-btn--outline sa-btn--sm" data-sa-share-native
          data-url="<?php echo esc_url($url); ?>"
          data-title="<?php echo esc_attr($title); ?>">
          <?php esc_html_e('Share', 'supreme-autoparts-core'); ?>
        </button>
        <button type="button" class="sa-btn sa-btn--outline sa-btn--sm" data-sa-share-copy
          data-url="<?php echo esc_url($url); ?>">
          <?php esc_html_e('Copy link', 'supreme-autoparts-core'); ?>
        </button>
        <a class="sa-btn sa-btn--outline sa-btn--sm" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener noreferrer">
          <?php esc_html_e('WhatsApp', 'supreme-autoparts-core'); ?>
        </a>
      </div>
      <span class="sa-share__status" data-sa-share-status hidden></span>
    </div>
    <?php
}
add_action('woocommerce_share', 'sa_seo_render_share_button', 10);
// Fallback if theme/template never calls woocommerce_share:
add_action('woocommerce_single_product_summary', 'sa_seo_render_share_button', 45);

/**
 * Inline share JS once on product pages (tiny; no external SEO scripts).
 */
function sa_seo_share_script(): void
{
    if (!function_exists('is_product') || !is_product()) {
        return;
    }
    ?>
    <script>
    (function () {
      function status(el, msg) {
        var s = el.querySelector('[data-sa-share-status]');
        if (!s) return;
        s.hidden = false;
        s.textContent = msg;
        setTimeout(function () { s.hidden = true; }, 2000);
      }
      document.querySelectorAll('[data-sa-share]').forEach(function (root) {
        var nativeBtn = root.querySelector('[data-sa-share-native]');
        var copyBtn = root.querySelector('[data-sa-share-copy]');
        if (nativeBtn) {
          nativeBtn.addEventListener('click', function () {
            var url = nativeBtn.getAttribute('data-url') || '';
            var title = nativeBtn.getAttribute('data-title') || '';
            if (navigator.share) {
              navigator.share({ title: title, url: url }).catch(function () {});
            } else if (copyBtn) {
              copyBtn.click();
            }
          });
        }
        if (copyBtn) {
          copyBtn.addEventListener('click', function () {
            var url = copyBtn.getAttribute('data-url') || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(url).then(function () {
                status(root, 'Link copied');
              }).catch(function () {
                status(root, url);
              });
            } else {
              status(root, url);
            }
          });
        }
      });
    })();
    </script>
    <?php
}
add_action('wp_footer', 'sa_seo_share_script', 40);

/**
 * Seed SEO title/description meta on guide pages after pages seed.
 */
function sa_seo_seed_page_meta(): void
{
    $map = [
        'how-to-order' => [
            'title' => 'How to Order Auto Parts Online (US & Kenya) | Supreme Autoparts',
            'desc'  => 'Step-by-step ordering: find fitment, checkout in USD, shipping options for the US and Kenya, and WhatsApp support at +254 714 498 451.',
        ],
        'shipping-to-kenya' => [
            'title' => 'Shipping Auto Parts to Kenya | Import & Delivery | Supreme Autoparts',
            'desc'  => 'How shipping to Kenya works: rates at checkout, free-shipping threshold, oversized exclusions, and local support via WhatsApp and email.',
        ],
        'fitment-guide' => [
            'title' => 'Vehicle Fitment Guide | Year / Make / Model | Supreme Autoparts',
            'desc'  => 'Confirm year, make, and model before you buy. US-spec truck and SUV fitments, plus tips for import vehicles in Kenya.',
        ],
        'auto-parts-kenya' => [
            'title' => 'Buy Auto Parts in Kenya | Import US Spec Parts | Supreme Autoparts',
            'desc'  => 'Order US-spec performance and aftermarket parts online with USD checkout and shipping to Kenya. Support: calvin@supremeautoparts.co.ke · +254 714 498 451.',
        ],
        'performance-truck-parts' => [
            'title' => 'Performance Truck & Off-Road Parts | US Spec | Supreme Autoparts',
            'desc'  => 'Suspension, brakes, wheels, lighting, and drivetrain for trucks and SUVs. USD checkout — shop popular US-spec performance brands.',
        ],
        'guides' => [
            'title' => 'Guides: Ordering, Fitment & Shipping | Supreme Autoparts',
            'desc'  => 'Practical guides for US and Kenya customers: how to order, fitment checks, shipping to Kenya, and buying auto parts online.',
        ],
    ];

    foreach ($map as $slug => $meta) {
        $page = get_page_by_path($slug);
        if (!$page) {
            continue;
        }
        update_post_meta((int) $page->ID, SA_SEO_TITLE_META, $meta['title']);
        update_post_meta((int) $page->ID, SA_SEO_DESC_META, $meta['desc']);
        if (isset(sa_seo_builtin_faqs()[$slug])) {
            update_post_meta((int) $page->ID, SA_SEO_FAQ_META, sa_seo_builtin_faqs()[$slug]);
        }
    }
}

/**
 * Remove duplicate WP default robots / rel_canonical when we print our own at priority 1.
 * Keep WC structured data (Product) intact.
 */
remove_action('wp_head', 'rel_canonical');
// WP 5.7+ may print robots via wp_robots — filter instead of remove.
add_filter('wp_robots', static function (array $robots): array {
    // Always suppress core robots meta — sa_seo_wp_head prints the single canonical robots tag.
    return [];
}, 20);

<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resource hints: warm up connections to third-party hosts used above the fold
 * (Google Fonts, Shopify CDN product images). Cheap LCP win on slow networks.
 */
add_action('wp_head', static function (): void {
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />' . "\n";
    echo '<link rel="preconnect" href="https://fonts.googleapis.com" />' . "\n";
    echo '<link rel="preconnect" href="https://cdn.shopify.com" crossorigin />' . "\n";
    echo '<link rel="dns-prefetch" href="https://cdn.shopify.com" />' . "\n";
}, 1);

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style(
        'sa-google-fonts',
        'https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,600..900&family=Inter:wght@400;500;600;700&display=swap',
        [],
        null
    );
    wp_enqueue_style('supreme-autoparts', get_stylesheet_uri(), ['sa-google-fonts'], SA_THEME_VERSION);
    wp_enqueue_style(
        'supreme-autoparts-main',
        SA_THEME_URI . '/assets/css/main.css',
        ['supreme-autoparts'],
        SA_THEME_VERSION
    );
    wp_add_inline_style('supreme-autoparts-main', <<<'CSS'

/* The store view changes copy, not shipping destination or checkout currency. */
@media(min-width:1000px) and (max-width:1450px){.sa-header__bar .sa-logo{max-width:230px;flex:0 0 230px}.sa-header__bar .sa-logo__img{width:100%!important;max-width:100%!important;height:auto;object-fit:contain}.sa-header__bar>.sa-search{min-width:0;flex:1}.sa-header__bar>.sa-search input{min-width:0;width:100%}.sa-header__actions{flex-shrink:0}.sa-header__actions>a[href$="/pricing/"]{display:none}}
@media(min-width:601px) and (max-width:999px){.sa-header__actions .sa-hide-sm{display:none}.sa-header__bar .sa-logo{min-width:0;max-width:240px}.sa-header__bar .sa-logo__img{max-width:100%!important;width:100%!important;height:auto}}
.sa-view-switch{background:#fff;border-bottom:1px solid #e6e6e6;color:#555;font-size:12px;line-height:1.3}
.sa-view-switch__in{display:flex;justify-content:flex-end;align-items:center;gap:5px;padding-top:5px;padding-bottom:5px}
.sa-view-switch span{margin-right:5px}
.sa-view-switch a{display:inline-block;padding:5px 12px;border:1px solid #ddd;border-radius:4px;color:#333;text-decoration:none!important;min-height:30px;box-sizing:border-box}
.sa-view-switch a[aria-current]{background:#171717;border-color:#171717;color:#fff}
.sa-view-switch a:focus-visible{outline:2px solid #e9a315;outline-offset:2px}
@media(max-width:600px){.sa-view-switch__in{justify-content:center}.sa-view-switch a{padding:8px 18px;min-height:34px}}

.sa-software-feature{display:flex;align-items:center;justify-content:space-between;gap:36px;margin-top:32px;margin-bottom:40px;padding-top:28px;padding-bottom:28px;border-top:2px solid #e9a315;border-bottom:1px solid #ddd}
.sa-software-feature h2{font-size:clamp(24px,3vw,36px);line-height:1.15;margin:8px 0 14px;color:#171717}
.sa-software-feature p{max-width:800px;line-height:1.65;color:#555;margin:8px 0}
.sa-software-link{flex-shrink:0;color:#171717;font-weight:600;text-decoration:none!important;border-bottom:1px solid #171717;padding:12px 0}
.sa-software-feature ul{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 24px;padding-left:20px}
@media(max-width:700px){.sa-software-feature{display:block;margin-top:24px;margin-bottom:28px;padding-top:22px;padding-bottom:22px}.sa-software-link{display:inline-block;margin-top:8px}.sa-software-feature ul{grid-template-columns:1fr}}

.sa-pricing{padding-top:40px;padding-bottom:55px;max-width:1100px}.sa-pricing h1{font-size:clamp(30px,4vw,48px);line-height:1.12}.sa-pricing p{line-height:1.7}.sa-pricing h2{margin-top:36px}.sa-pricing-note{padding:20px 24px;background:#f6f6f6;border-left:3px solid #e9a315;margin:24px 0}.sa-pricing-scroll{overflow-x:auto}.sa-pricing table{border-collapse:collapse;width:100%;font-size:14px;text-align:left}.sa-pricing th,.sa-pricing td{padding:15px 12px;border-bottom:1px solid #ddd;vertical-align:top;min-width:140px}.sa-pricing thead{background:#f4f4f4}.sa-pricing td a{color:#3b3b3b;text-decoration:none;border-bottom:1px solid #aaa}@media(max-width:600px){.sa-pricing{padding-top:24px}.sa-pricing th,.sa-pricing td{padding:12px 8px;min-width:120px}}

/* Focused service-page layout. */
.sa-pricing{max-width:1120px;padding-top:48px;background:#fff}.sa-pricing-hero{max-width:760px;margin-bottom:44px}.sa-pricing-hero h1{letter-spacing:-.04em;margin:12px 0 20px;font-size:clamp(36px,5vw,62px)}.sa-pricing-hero>p{max-width:620px;color:#606060}.sa-pricing-jump{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px}.sa-pricing-jump a{padding:10px 16px;border:1px solid #d8d8d8;border-radius:30px;color:#222;text-decoration:none}.sa-pricing-heading{margin:38px 0 22px}.sa-pricing-heading h2{font-size:30px;line-height:1.15;letter-spacing:-.025em;margin:8px 0}.sa-assessment-note{display:flex;gap:22px;background:#fff6df;border-left:3px solid #e9a315;padding:20px 24px;margin-bottom:22px;line-height:1.6}.sa-assessment-note strong{flex:0 0 150px}.sa-service-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.sa-service-card{display:flex;flex-direction:column;padding:26px;border:1px solid #e1e1e1;border-radius:14px;background:#fff;min-width:0}.sa-service-card h3{font-size:22px;line-height:1.25;margin:0 0 14px}.sa-service-card h3 a{color:#171717;text-decoration:none}.sa-card-price{font-size:26px;font-weight:700;color:#171717;line-height:1.2!important;margin:0 0 16px}.sa-card-summary{font-size:15px;color:#666;line-height:1.65;flex:1;margin:0 0 18px}.sa-card-fee{font-size:12px;line-height:1.6!important;color:#606060;padding-top:16px;border-top:1px solid #eee;margin:0 0 18px}.sa-card-actions{display:flex;align-items:center;gap:20px;flex-wrap:wrap}.sa-card-button{display:inline-flex;justify-content:center;align-items:center;min-height:46px;background:#171717;color:#fff!important;border-radius:6px;padding:11px 18px;font-weight:600;text-decoration:none!important;font-size:14px}.sa-card-details{font-size:14px;color:#171717;text-decoration:none!important}.sa-pricing-footer{padding:40px 0 20px;margin-top:30px;border-top:1px solid #ddd}.sa-pricing-policy-links{font-size:13px;margin-top:30px}.sa-pricing section{scroll-margin-top:255px}
@media(max-width:700px){body:has(.sa-pricing) .sa-rsbar,body:has(.sa-pricing) .sa-announcement,body:has(.sa-pricing) .sa-announce,body:has(.sa-pricing) .sa-header__search-mobile{display:none!important}body:has(.sa-pricing) .sa-header__bar{min-height:60px;padding-top:8px;padding-bottom:8px}body:has(.sa-pricing) .sa-view-switch__in{padding-top:4px;padding-bottom:4px}body:has(.sa-pricing) .sa-view-switch a{min-height:28px;padding:5px 15px}.sa-pricing{padding-top:22px;padding-bottom:30px}.sa-pricing-hero{margin-bottom:32px}.sa-pricing-hero h1{font-size:38px;line-height:1.06}.sa-pricing-hero>p{font-size:15px}.sa-pricing-jump{gap:8px}.sa-pricing-jump a{font-size:12px;padding:9px 12px}.sa-pricing-heading h2{font-size:28px}.sa-service-cards{grid-template-columns:1fr;gap:14px}.sa-service-card{padding:22px}.sa-service-card h3{font-size:22px}.sa-card-price{font-size:27px}.sa-assessment-note{display:block;padding:18px 20px;font-size:14px}.sa-assessment-note strong{display:block;margin-bottom:6px}.sa-card-actions{gap:18px}.sa-card-button{flex:1}.sa-pricing section{scroll-margin-top:115px}}

/* Service journey only: details, booking and booking-fee checkout. */
.sa-journey-mobile-summary{display:none}
.sa-journey{max-width:1120px;padding-top:32px;padding-bottom:56px;color:#171717}.sa-journey h1{font-size:clamp(36px,4vw,54px);letter-spacing:-.04em;line-height:1.08;margin:12px 0 20px}.sa-journey-grid{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(280px,1fr);gap:42px}.sa-journey-lead{font-size:18px;color:#606060;line-height:1.7}.sa-journey-panel,.sa-journey-summary{border:1px solid #e1e1e1;border-radius:14px;padding:26px;background:#fff;margin:22px 0}.sa-journey-panel h2,.sa-journey-summary h2{font-size:24px;letter-spacing:-.025em;margin:0 0 20px}.sa-journey-panel li{padding:7px 0;line-height:1.65}.sa-journey-steps{padding-left:22px}.sa-journey-steps p{margin:5px 0 12px;color:#606060;line-height:1.65}.sa-journey-summary dl{margin:0}.sa-journey-summary dl>div{padding:16px 0;border-bottom:1px solid #eee}.sa-journey-summary dt{font-size:13px;color:#606060}.sa-journey-summary dd{margin:7px 0 0;font-size:22px;font-weight:700;line-height:1.2}.sa-journey-summary>p:last-child{font-size:14px;line-height:1.7;color:#606060;margin-bottom:0}.sa-journey-book{display:flex;width:100%}.sa-journey-fine{font-size:13px;line-height:1.7;color:#606060}.sa-svc-wiz .sa-journey-summary{max-width:720px}.sa-svc-wiz .sa-svc-alert{background:#fff6df;border-color:#f0d6a0;color:#292929}.sa-svc-wiz .sa-svc-progress{border-radius:12px}.sa-svc-wiz .sa-svc-form,.sa-svc-wiz .sa-svc-aside{border-radius:14px;border:1px solid #e1e1e1;background:#fff}.sa-svc-wiz .sa-svc-btn--primary,body.sa-service-payment #payment button{min-height:46px;padding:12px 22px;font-weight:600;font-size:15px;background:#171717!important;color:#fff!important;border-color:#171717!important;border-radius:6px}.sa-svc-wiz .sa-svc-feebox{border-radius:12px;background:#fff6df;color:#292929}.sa-svc-wiz .sa-svc-choice{border-radius:10px}.sa-svc-wiz h1{letter-spacing:-.035em}.sa-svc-wiz .sa-svc-trust{display:none}body.sa-service-payment .woocommerce{max-width:900px;margin:auto}body.sa-service-payment .shop_table,body.sa-service-payment #payment{border:1px solid #e1e1e1;border-radius:14px;background:#fff;padding:24px}body.sa-service-payment .sa-journey-summary{margin-top:30px}
@media(max-width:700px){body:has(.sa-journey) .sa-rsbar,body:has(.sa-journey) .sa-header__search-mobile,body:has(.sa-svc-wiz) .sa-rsbar,body:has(.sa-svc-wiz) .sa-header__search-mobile,body.sa-service-payment .sa-rsbar,body.sa-service-payment .sa-header__search-mobile{display:none!important}.sa-journey{padding-top:22px}.sa-journey-grid{display:flex;flex-direction:column;gap:0}.sa-journey-mobile-summary{display:block;margin-bottom:28px}.sa-journey-grid aside>.sa-journey-summary,.sa-journey-grid aside>.sa-journey-book{display:none}.sa-journey-panel,.sa-journey-summary{padding:22px}.sa-journey-grid>article>.sa-ed-kicker{margin-top:30px}.sa-journey-lead{font-size:16px}.sa-svc-wiz{padding-top:24px}.sa-svc-wiz .sa-svc-progress{gap:4px;font-size:12px}.sa-svc-wiz .sa-svc-form{padding:18px}.sa-journey-summary dd{font-size:24px}}

CSS
    );
    wp_enqueue_style(
        'supreme-autoparts-loader',
        SA_THEME_URI . '/assets/css/sa-loader.css',
        ['supreme-autoparts-main'],
        SA_THEME_VERSION
    );
    wp_enqueue_style(
        'supreme-autoparts-spinners',
        SA_THEME_URI . '/assets/css/spinners.css',
        ['supreme-autoparts-loader'],
        SA_THEME_VERSION
    );
    wp_enqueue_script(
        'supreme-autoparts',
        SA_THEME_URI . '/assets/js/theme.js',
        ['jquery'],
        SA_THEME_VERSION . '.checkout10',
        true
    );
    wp_enqueue_script(
        'supreme-autoparts-loader',
        SA_THEME_URI . '/assets/js/sa-loader.js',
        ['jquery', 'supreme-autoparts'],
        SA_THEME_VERSION . '.careers-pay1',
        true
    );
    wp_enqueue_script(
        'supreme-autoparts-spinners',
        SA_THEME_URI . '/assets/js/spinners.js',
        ['jquery', 'supreme-autoparts', 'supreme-autoparts-loader'],
        SA_THEME_VERSION,
        true
    );
    wp_localize_script('supreme-autoparts', 'saTheme', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'cartUrl' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
        'checkoutQtyNonce' => wp_create_nonce('sa_checkout_qty'),
        'i18n'    => [
            'cart'               => __('Cart', 'supreme-autoparts'),
            'termsRequired'      => __('Please accept the store policies to place your order.', 'supreme-autoparts'),
            'loading'            => __('Loading…', 'supreme-autoparts'),
            'processing'         => __('Processing…', 'supreme-autoparts'),
            'processingPayment'  => __('Please wait - taking you to secure payment. Do not close this page.', 'supreme-autoparts'),
            'searching'           => __('Searching parts…', 'supreme-autoparts'),
            'qtyUpdateFailed'    => __('Could not update quantity. Please try again.', 'supreme-autoparts'),
        ],
    ]);

    if (function_exists('is_cart') && (is_cart() || is_checkout() || is_product())) {
        wp_enqueue_script('wc-cart-fragments');
    }

    $is_checkout = function_exists('is_checkout') && is_checkout();
    $is_order_received = function_exists('is_order_received_page') && is_order_received_page();
    if ($is_checkout || $is_order_received) {
        wp_enqueue_style(
            'supreme-autoparts-checkout',
            SA_THEME_URI . '/assets/css/checkout.css',
            ['supreme-autoparts-main'],
            SA_THEME_VERSION
        );
    }

    if (function_exists('is_account_page') && is_account_page()) {
        wp_enqueue_style(
            'supreme-autoparts-myaccount',
            SA_THEME_URI . '/assets/css/myaccount.css',
            ['supreme-autoparts-main'],
            SA_THEME_VERSION . '.account4'
        );
    }

});

add_filter('style_loader_src', static function ($src, $handle) {
    if ($handle !== 'sa-whop-open-pay' || !is_string($src) || $src === '') {
        return $src;
    }
    return add_query_arg('ver', SA_THEME_VERSION, remove_query_arg('ver', $src));
}, 20, 2);

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('supreme-autoparts-polish', SA_THEME_URI . '/assets/css/polish.css', [], SA_THEME_VERSION . '.p1');
}, 999);

add_action('wp_enqueue_scripts', static function (): void {
    if (!function_exists('is_shop') || !(is_shop() || is_product_taxonomy() || is_search())) {
        return;
    }
    wp_enqueue_style('supreme-autoparts-filters', SA_THEME_URI . '/assets/css/filters.css', ['supreme-autoparts-polish'], SA_THEME_VERSION . '.kes2');
    wp_enqueue_script('supreme-autoparts-filters', SA_THEME_URI . '/assets/js/filters.js', [], SA_THEME_VERSION, true);
}, 1000);

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('supreme-autoparts-cart-ui', SA_THEME_URI . '/assets/css/cart-ui.css', ['supreme-autoparts-polish'], SA_THEME_VERSION . '.cu17');
}, 1001);

/* Header search panel (optional filters) on every page. */
add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('supreme-autoparts-search-panel', SA_THEME_URI . '/assets/css/search-panel.css', [], SA_THEME_VERSION . '.sp1');
    wp_enqueue_script('supreme-autoparts-search-panel', SA_THEME_URI . '/assets/js/search-panel.js', [], SA_THEME_VERSION . '.sp3', true);
    wp_localize_script('supreme-autoparts-search-panel', 'saSearchPanel', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'view' => function_exists('sa_storefront_is_kenya') && sa_storefront_is_kenya() ? 'ke' : 'us',
        'home'    => home_url('/'),
        'shop'    => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/'),
    ]);
}, 1001);

/* Editorial system (fonts, light header, homepage). */
add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('supreme-autoparts-editorial', SA_THEME_URI . '/assets/css/editorial.css', [], SA_THEME_VERSION);
}, 1003);

/* Homepage cinematic hero: live suggestions, vehicle quick-select, scroll reveal. */
add_action('wp_enqueue_scripts', static function (): void {
    if (!is_front_page()) {
        return;
    }
    wp_enqueue_script('supreme-autoparts-hero', SA_THEME_URI . '/assets/js/hero.js', [], SA_THEME_VERSION, true);
    wp_localize_script('supreme-autoparts-hero', 'saHero', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'store'   => rest_url('wc/store/v1/products'),
    ]);
}, 1002);

<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style(
        'sa-google-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
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
    wp_enqueue_style(
        'supreme-autoparts-loader',
        SA_THEME_URI . '/assets/css/sa-loader.css',
        ['supreme-autoparts-main'],
        SA_THEME_VERSION
    );
    wp_enqueue_script(
        'supreme-autoparts',
        SA_THEME_URI . '/assets/js/theme.js',
        ['jquery'],
        SA_THEME_VERSION,
        true
    );
    wp_enqueue_script(
        'supreme-autoparts-loader',
        SA_THEME_URI . '/assets/js/sa-loader.js',
        ['jquery', 'supreme-autoparts'],
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
            'processingPayment'  => __('Processing payment…', 'supreme-autoparts'),
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
            SA_THEME_VERSION
        );
    }

    // Sai-style agents assemble — full customer storefront (incl. cart/checkout/account).
    // Skip admin, AJAX, REST, and login only so ops stay clean.
    $skip_agents_build = is_admin();
    if (!$skip_agents_build && function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        $skip_agents_build = true;
    }
    if (!$skip_agents_build && defined('REST_REQUEST') && REST_REQUEST) {
        $skip_agents_build = true;
    }
    if (!$skip_agents_build) {
        global $pagenow;
        if (is_string($pagenow) && in_array($pagenow, ['wp-login.php', 'wp-register.php'], true)) {
            $skip_agents_build = true;
        }
    }

    if (!$skip_agents_build) {
        wp_enqueue_style(
            'supreme-autoparts-agents-build',
            SA_THEME_URI . '/assets/css/sa-agents-build.css',
            ['supreme-autoparts-main'],
            SA_THEME_VERSION
        );
        wp_enqueue_script(
            'supreme-autoparts-agents-build',
            SA_THEME_URI . '/assets/js/sa-agents-build.js',
            [],
            SA_THEME_VERSION,
            true
        );
        $wa = function_exists('sa_enquire_contact') ? sa_enquire_contact() : [
            'whatsapp'      => '19174375121',
            'phone_display' => '+1 917 437 5121',
        ];
        $wa_digits = preg_replace('/\D+/', '', (string) ($wa['whatsapp'] ?? '19174375121')) ?: '19174375121';
        $on_checkout = function_exists('is_checkout') && is_checkout();
        $on_cart = function_exists('is_cart') && is_cart();
        $on_account = function_exists('is_account_page') && is_account_page();
        $on_product = function_exists('is_product') && is_product();
        $on_shop = function_exists('is_shop') && is_shop();
        $on_tax = function_exists('is_product_taxonomy') && is_product_taxonomy();
        $on_search = is_search();

        $page_type = 'store';
        if ($on_checkout) {
            $page_type = 'checkout';
        } elseif ($on_cart) {
            $page_type = 'cart';
        } elseif ($on_account) {
            $page_type = 'account';
        } elseif ($on_product) {
            $page_type = 'product';
        } elseif ($on_shop) {
            $page_type = 'shop';
        } elseif ($on_tax) {
            $page_type = 'category';
        } elseif ($on_search) {
            $page_type = 'search';
        } elseif (is_front_page()) {
            $page_type = 'home';
        }

        $product_payload = null;
        if ($on_product && function_exists('wc_get_product')) {
            $pid = get_queried_object_id();
            $product = $pid ? wc_get_product($pid) : null;
            if ($product) {
                $img_id = (int) $product->get_image_id();
                $img = $img_id ? wp_get_attachment_image_url($img_id, 'woocommerce_single') : '';
                if (!$img) {
                    $img = function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src('woocommerce_single') : '';
                }
                $price = html_entity_decode(wp_strip_all_tags((string) $product->get_price_html()), ENT_QUOTES, 'UTF-8');
                $product_payload = [
                    'title' => (string) $product->get_name(),
                    'price' => $price !== '' ? $price : '',
                    'image' => is_string($img) ? $img : '',
                    'sku'   => (string) $product->get_sku(),
                ];
            }
        }

        wp_localize_script('supreme-autoparts-agents-build', 'saAgentsBuild', [
            'storageKey'  => 'sap-built',
            'waDisplay'   => (string) ($wa['phone_display'] ?? '+1 917 437 5121'),
            'waUrl'       => 'https://wa.me/' . $wa_digits,
            'criticalUi'  => ($on_checkout || $on_cart) ? 1 : 0,
            'pageType'    => $page_type,
            'product'     => $product_payload,
        ]);
    }

});

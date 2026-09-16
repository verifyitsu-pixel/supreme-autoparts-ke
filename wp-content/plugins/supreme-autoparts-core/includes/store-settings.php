<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical customer-service / transactional email.
 */
function sa_core_store_email(): string
{
    $env = getenv('WORDPRESS_ADMIN_EMAIL') ?: getenv('SUPREME_STORE_EMAIL');
    if (is_string($env) && is_email($env)) {
        return $env;
    }
    return 'calvin@supremeautoparts.co.ke';
}

/**
 * Apply WooCommerce + WordPress store identity for Kenya checkout flows.
 * Safe to run on every boot (idempotent).
 */
function sa_core_apply_store_settings(): void
{
    $email = sa_core_store_email();

    // WordPress admin / site identity email
    update_option('admin_email', $email);
    update_option('new_admin_email', $email);

    // Prefer www — apex may not be bound on Railway.
    $home = getenv('WP_HOME') ?: 'https://www.supremeautoparts.co.ke';
    $site = getenv('WP_SITEURL') ?: $home;
    if (is_string($home) && $home !== '') {
        // Force www if bare apex is configured (avoids Railway "Application not found" on unbound apex).
        $home = preg_replace('#^https?://supremeautoparts\.co\.ke(?=/|$)#i', 'https://www.supremeautoparts.co.ke', $home) ?: $home;
        $site = preg_replace('#^https?://supremeautoparts\.co\.ke(?=/|$)#i', 'https://www.supremeautoparts.co.ke', $site) ?: $site;
        update_option('home', $home);
        update_option('siteurl', $site);
    }

    // WooCommerce email sender
    update_option('woocommerce_email_from_name', 'Supreme Autoparts');
    update_option('woocommerce_email_from_address', $email);
    update_option('woocommerce_stock_email_recipient', $email);

    // Store address / customer service
    update_option('woocommerce_store_address', get_option('woocommerce_store_address') ?: 'Nairobi');
    update_option('woocommerce_store_city', 'Nairobi');
    update_option('woocommerce_default_country', 'KE');
    update_option('woocommerce_currency', getenv('SA_CHECKOUT_CURRENCY') ?: getenv('WOO_CURRENCY') ?: 'USD');

    // Customer registration + account flows
    update_option('woocommerce_enable_myaccount_registration', 'yes');
    update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
    update_option('woocommerce_enable_guest_checkout', 'yes');
    update_option('woocommerce_registration_generate_username', 'yes');
    update_option('woocommerce_registration_generate_password', 'yes');
    update_option('users_can_register', 1);
    update_option('woocommerce_enable_checkout_login_reminder', 'yes');
    update_option('woocommerce_enable_persistent_cart', 'yes');
    update_option('woocommerce_myaccount_lost_password_endpoint', 'lost-password');
    update_option('woocommerce_myaccount_orders_endpoint', 'orders');
    update_option('woocommerce_myaccount_downloads_endpoint', 'downloads');
    update_option('woocommerce_myaccount_edit_address_endpoint', 'edit-address');
    update_option('woocommerce_myaccount_payment_methods_endpoint', 'payment-methods');
    update_option('woocommerce_myaccount_edit_account_endpoint', 'edit-account');

    // Checkout must accept terms
    update_option('woocommerce_checkout_show_terms', 'yes');
    update_option('woocommerce_checkout_privacy_policy_text',
        sprintf(
            'Your personal data will be used to process your order, support your experience, and for other purposes described in our %s.',
            '[privacy_policy]'
        )
    );
    update_option('woocommerce_checkout_terms_and_conditions_checkbox_text',
        'I have read and agree to the website [terms] and the Privacy, Chargeback, Cookie, and Refund policies linked above.'
    );

    // Assign Woo terms + privacy pages when seeded
    $terms = get_page_by_path('terms');
    if ($terms) {
        update_option('woocommerce_terms_page_id', (int) $terms->ID);
    }
    $privacy = get_page_by_path('privacy-policy');
    if ($privacy) {
        update_option('wp_page_for_privacy_policy', (int) $privacy->ID);
        update_option('woocommerce_privacy_policy_page_id', (int) $privacy->ID);
    }

    // Mail from filters (wp_mail)
    update_option('sa_store_email', $email);
    update_option('sa_store_settings_applied', time());
    update_option('sa_smtp_note', 'Set BREVO_API_KEY (or BREVO_SMTP_*) on Railway. From=calvin@supremeautoparts.co.ke via sa-brevo-mail.');
}

add_filter('wp_mail_from', static function ($from) {
    $email = sa_core_store_email();
    return is_email($email) ? $email : $from;
});

add_filter('wp_mail_from_name', static function ($name) {
    return 'Supreme Autoparts';
});

// Apply lightly on admin/init once per version bump.
add_action('init', static function (): void {
    if (get_option('sa_store_settings_ver') === '8') {
        return;
    }
    if (!function_exists('WC') && !class_exists('WooCommerce')) {
        // Still set WP admin email.
        update_option('admin_email', sa_core_store_email());
    }
    sa_core_apply_store_settings();
    update_option('sa_store_settings_ver', '8');
}, 20);

<?php
/**
 * Plugin Name: Supreme Autoparts Loader
 * Description: Ensures core plugin branding filters load early when the regular plugin is present.
 * Version: 1.0.1
 */

declare(strict_types=1);

// Intentionally minimal — main logic lives in supreme-autoparts-core.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable guest checkout (mu-plugin): survives core plugin deactivation races.
 * Must stay in sync with supreme-autoparts-core/includes/checkout-terms.php.
 */
if (!function_exists('sa_mu_force_guest_checkout_yes')) {
    function sa_mu_force_guest_checkout_yes()
    {
        return 'yes';
    }
}
if (!function_exists('sa_mu_force_checkout_login_reminder_yes')) {
    function sa_mu_force_checkout_login_reminder_yes()
    {
        return 'yes';
    }
}
add_filter('pre_option_woocommerce_enable_guest_checkout', 'sa_mu_force_guest_checkout_yes');
add_filter('pre_option_woocommerce_enable_checkout_login_reminder', 'sa_mu_force_checkout_login_reminder_yes');
add_filter('woocommerce_checkout_registration_required', '__return_false', 5);

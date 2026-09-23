<?php
/**
 * Customer new account email (plain).
 * Password is emailed separately via sa_core_email_customer_password.
 *
 * @package Supreme_Autoparts
 * @version 1.4.28
 */

defined('ABSPATH') || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html(wp_strip_all_tags($email_heading));
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

printf(esc_html__('Hi %s,', 'supreme-autoparts'), esc_html($user_login));
echo "\n\n";

printf(
    /* translators: %s: store name */
    esc_html__('Thanks for creating an account on %s.', 'supreme-autoparts'),
    esc_html($blogname)
);
echo "\n\n";

printf(
    /* translators: %s: username / email */
    esc_html__('You can log in with your email address: %s', 'supreme-autoparts'),
    esc_html($user_login)
);
echo "\n\n";

echo esc_html__('A secure password was emailed to you separately. Use that password to log in. If you did not receive it, use “Email me a new password” on the login page.', 'supreme-autoparts') . "\n\n";

echo esc_html__('Log in to My Account:', 'supreme-autoparts') . ' ' . esc_url(wc_get_page_permalink('myaccount')) . "\n\n";

if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content))) . "\n\n";
}

echo "\n----------------------------------------\n\n";
echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));

<?php
/**
 * Customer new account email (plain).
 * Password is normally emailed separately; if present here it must not be esc_html'd.
 *
 * @package Supreme_Autoparts
 * @version 1.4.20
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

if (!empty($password_generated) && is_string($user_pass) && $user_pass !== '') {
    if (preg_match('/^[A-Za-z0-9]{12,64}$/', $user_pass)) {
        echo esc_html__('We generated a secure password for you. It works right away — use it to log in:', 'supreme-autoparts') . "\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- alphanumeric-only
        echo $user_pass . "\n\n";
        echo esc_html__('You can change this password anytime after logging in under Account details.', 'supreme-autoparts') . "\n\n";
    } else {
        echo esc_html__('A secure password was emailed to you in a separate message. Use that password to log in.', 'supreme-autoparts') . "\n\n";
    }
} elseif (!empty($password_generated)) {
    echo esc_html__('A secure password was emailed to you separately. If you did not receive it, use Lost password on the login page and we will email you a new one.', 'supreme-autoparts') . "\n\n";
}

echo esc_html__('Log in to My Account:', 'supreme-autoparts') . ' ' . esc_url(wc_get_page_permalink('myaccount')) . "\n\n";

if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content))) . "\n\n";
}

echo "\n----------------------------------------\n\n";
echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));

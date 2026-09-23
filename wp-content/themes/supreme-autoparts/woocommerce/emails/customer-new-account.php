<?php
/**
 * Customer new account email (welcome).
 * The working password is emailed separately as plain text
 * (sa_core_email_customer_password) so HTML escaping cannot mangle it.
 *
 * @package Supreme_Autoparts
 * @version 1.4.28
 */

defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email); ?>

<p><?php printf(esc_html__('Hi %s,', 'supreme-autoparts'), esc_html($user_login)); ?></p>

<p><?php printf(
    /* translators: %s: store name */
    esc_html__('Thanks for creating an account on %s.', 'supreme-autoparts'),
    esc_html($blogname)
); ?></p>

<p><?php printf(
    /* translators: %s: username / email */
    esc_html__('You can log in with your email address: %s', 'supreme-autoparts'),
    '<strong>' . esc_html($user_login) . '</strong>'
); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<p><?php esc_html_e('A secure password was emailed to you separately. Use that password to log in. If you did not receive it, use “Email me a new password” on the login page and we will email you a new one.', 'supreme-autoparts'); ?></p>

<p><?php esc_html_e('After you log in, you stay signed in on that browser until you use Log out. You can change the password anytime under Account details.', 'supreme-autoparts'); ?></p>

<p>
	<a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">
		<?php esc_html_e('Log in to My Account', 'supreme-autoparts'); ?>
	</a>
</p>

<p style="color:#666;font-size:14px;"><?php esc_html_e('Need help? calvin@supremeautoparts.co.ke', 'supreme-autoparts'); ?></p>

<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);

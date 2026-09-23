<?php
/**
 * Customer new account email.
 * Customers sign in with an emailed login code (OTP), not a permanent password.
 *
 * @package Supreme_Autoparts
 * @version 1.4.25
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
    esc_html__('You can sign in with your email address: %s', 'supreme-autoparts'),
    '<strong>' . esc_html($user_login) . '</strong>'
); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<p><?php esc_html_e('We emailed you a 6-digit login code in a separate message. Enter that code on the website to finish signing in. You can set a password later under Account details if you want.', 'supreme-autoparts'); ?></p>

<p>
	<a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">
		<?php esc_html_e('Go to My Account', 'supreme-autoparts'); ?>
	</a>
</p>

<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);

<?php
/**
 * Customer new account email — includes generated plaintext password.
 *
 * @package Supreme_Autoparts
 * @version 1.4.18
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
    /* translators: %s: username */
    esc_html__('Username (email): %s', 'supreme-autoparts'),
    '<strong>' . esc_html($user_login) . '</strong>'
); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<?php if (!empty($password_generated) && is_string($user_pass) && $user_pass !== '') : ?>
	<p><?php esc_html_e('We generated a secure password for you. It works right away — use it to log in:', 'supreme-autoparts'); ?></p>
	<p style="font-size:18px;letter-spacing:0.02em;"><strong><?php echo esc_html($user_pass); ?></strong></p>
	<p><?php esc_html_e('You can change this password anytime after logging in under Account details.', 'supreme-autoparts'); ?></p>
<?php elseif (!empty($password_generated)) : ?>
	<p><?php esc_html_e('A secure password was generated for your account. If you did not receive it in this email, use Lost password on the login page and we will email you a new one.', 'supreme-autoparts'); ?></p>
<?php endif; ?>

<p>
	<a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">
		<?php esc_html_e('Log in to My Account', 'supreme-autoparts'); ?>
	</a>
</p>

<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);

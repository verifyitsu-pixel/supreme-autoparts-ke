<?php
/**
 * Customer new account email (welcome).
 * The working password is emailed separately as plain text
 * (sa_core_email_customer_password) so HTML escaping cannot mangle it.
 *
 * @package Supreme_Autoparts
 * @version 1.4.27
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

<?php if (!empty($password_generated) && is_string($user_pass) && $user_pass !== '') : ?>
	<?php
	// Defense-in-depth: only echo if alphanumeric (email-safe). Never esc_html a password.
	$sa_safe_pass = (bool) preg_match('/^[A-Za-z0-9]{12,64}$/', $user_pass);
	?>
	<?php if ($sa_safe_pass) : ?>
		<p><?php esc_html_e('We set a secure password for you. It works right away — use it to log in:', 'supreme-autoparts'); ?></p>
		<p style="font-size:18px;letter-spacing:0.02em;font-family:ui-monospace,Menlo,Consolas,monospace;"><code><?php echo $user_pass; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- alphanumeric-only checked above ?></code></p>
		<p><?php esc_html_e('After you log in, you stay signed in on that browser until you use Log out. You can change this password anytime under Account details.', 'supreme-autoparts'); ?></p>
	<?php else : ?>
		<p><?php esc_html_e('A secure password was emailed to you in a separate message. Use that password to log in, then you can change it under Account details.', 'supreme-autoparts'); ?></p>
	<?php endif; ?>
<?php elseif (!empty($password_generated)) : ?>
	<p><?php esc_html_e('A secure password was emailed to you separately. Use that password to log in. If you did not receive it, use “Email me a new password” on the login page and we will email you a new one.', 'supreme-autoparts'); ?></p>
<?php else : ?>
	<p><?php esc_html_e('A secure password was emailed to you separately. Use that password to log in. If you did not receive it, use “Email me a new password” on the login page.', 'supreme-autoparts'); ?></p>
<?php endif; ?>

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

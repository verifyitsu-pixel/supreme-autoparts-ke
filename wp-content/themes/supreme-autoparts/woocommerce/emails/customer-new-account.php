<?php
/**
 * Customer new account email (welcome).
 * Existing passwordless login instructions are preserved.
 *
 * @package Supreme_Autoparts
 * @version 1.4.28
 */

defined('ABSPATH') || exit;

ob_start(); ?>

<p><?php echo function_exists('sa_email_greeting') ? sa_email_greeting(get_user_by('login', $user_login) ?: '') : esc_html__('Hello,', 'supreme-autoparts'); ?></p>

<p><?php printf(
    /* translators: %s: store name */
    esc_html__('Welcome to the garage! Thank you so much for creating your account on %s.', 'supreme-autoparts'),
    esc_html($blogname)
); ?></p>

<p><?php printf(
    /* translators: %s: username / email */
    esc_html__('You can log in with your email address: %s', 'supreme-autoparts'),
    '<strong>' . esc_html($user_login) . '</strong>'
); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<p><?php esc_html_e('Your account is ready. To sign in, enter your email on the My Account page and we will email you a one-time login code. There is no password to remember.', 'supreme-autoparts'); ?></p>

<p><?php esc_html_e('After you sign in, you stay signed in on that browser until you use Log out.', 'supreme-autoparts'); ?></p>

<p>
	<a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">
		<?php esc_html_e('Log in to My Account', 'supreme-autoparts'); ?>
	</a>
</p>


<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

$inner = (string) ob_get_clean();
echo function_exists('sa_customer_mail_layout') ? sa_customer_mail_layout('Welcome to Supreme Autoparts', $inner, 'Open My Account', wc_get_page_permalink('myaccount'), 'Your account is ready') : $inner;

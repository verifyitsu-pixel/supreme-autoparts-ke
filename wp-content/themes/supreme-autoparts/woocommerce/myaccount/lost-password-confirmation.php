<?php
/**
 * Lost password confirmation — login code or staff reset link sent.
 *
 * @package Supreme_Autoparts
 * @version 1.4.25
 */

defined('ABSPATH') || exit;

wc_print_notice(
    esc_html__('If an account exists, check your email for a login code (or reset link for staff accounts).', 'supreme-autoparts'),
    'success'
);
?>

<?php do_action('woocommerce_before_lost_password_confirmation_message'); ?>

<p><?php echo esc_html(
    apply_filters(
        'woocommerce_lost_password_confirmation_message',
        esc_html__(
            'We emailed a login code to the address on file when the account is a customer account. Enter that code on the login screen. Staff accounts receive a password-reset link instead.',
            'supreme-autoparts'
        )
    )
); ?></p>

<p><a class="sa-btn" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('Back to log in', 'supreme-autoparts'); ?></a></p>

<?php do_action('woocommerce_after_lost_password_confirmation_message'); ?>

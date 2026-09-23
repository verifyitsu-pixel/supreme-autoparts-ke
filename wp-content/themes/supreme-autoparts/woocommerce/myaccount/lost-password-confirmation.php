<?php
/**
 * Lost password confirmation — we emailed a new working password.
 *
 * @package Supreme_Autoparts
 * @version 1.4.18
 */

defined('ABSPATH') || exit;

wc_print_notice(
    esc_html__('Check your email for a new password. It works right away.', 'supreme-autoparts'),
    'success'
);
?>

<?php do_action('woocommerce_before_lost_password_confirmation_message'); ?>

<p><?php echo esc_html(
    apply_filters(
        'woocommerce_lost_password_confirmation_message',
        esc_html__(
            'A new secure password has been sent to the email address on file for your account. It may take a few minutes to arrive. Use that password to log in, then you can change it under Account details.',
            'supreme-autoparts'
        )
    )
); ?></p>

<p><a class="sa-btn" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('Back to log in', 'supreme-autoparts'); ?></a></p>

<?php do_action('woocommerce_after_lost_password_confirmation_message'); ?>

<?php
/**
 * Lost password confirmation — we emailed a new working password.
 *
 * @package Supreme_Autoparts
 * @version 1.4.30
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
        __('A new secure password has been emailed to the address on your account. It may take a few minutes — check inbox and spam. Use that password on the Log in page. After you log in, you stay signed in on this browser until you tap Log out. You can change the password under Account details.', 'supreme-autoparts')
    )
); ?></p>

<p class="sa-lost-help">
  <?php esc_html_e('Did not get the email? Wait a few minutes, check spam, then try again — or contact calvin@supremeautoparts.co.ke.', 'supreme-autoparts'); ?>
</p>

<p><a class="sa-btn" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('Back to log in', 'supreme-autoparts'); ?></a></p>

<?php do_action('woocommerce_after_lost_password_confirmation_message'); ?>

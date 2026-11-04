<?php
/** Supreme order confirmation: personal garage tone, real items, then practical order details. */
defined('ABSPATH') || exit;
// Processing status alone is not proof of a verified payment.
if (!$order->get_date_paid() || !$order->is_paid()) {
    include WC()->plugin_path() . '/templates/emails/customer-on-hold-order.php';
    return;
}
do_action('woocommerce_email_header', $email_heading, $email);
$name = function_exists('sa_email_first_name') ? sa_email_first_name($order) : '';
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:0 0 20px;text-align:center">
  <p style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#666">Good parts. Great drives.</p>
  <h2 style="font-size:26px;line-height:1.25;color:#111;margin:12px 0">Your next fix starts here.</h2>
  <p><?php echo esc_html($name !== '' ? $name . ', you have excellent taste in car parts.' : 'You have excellent taste in car parts.'); ?> Thank you for choosing Supreme Autoparts.</p>
  <p>Whether this is a quick repair or the next piece of your project, we appreciate being part of it. Your order is confirmed, and the real details are right below. Keep this one for the garage.</p>
  <p><?php
  $pickup = false;
  foreach ($order->get_shipping_methods() as $method) { if (strpos($method->get_method_id(),'local_pickup')===0) { $pickup=true; } }
  echo esc_html($pickup ? 'Collecting your parts? We will email you when your order is ready for pickup. Please wait for that update before setting off.' : 'We will email you packing and shipping updates as your order moves along. You will find your chosen delivery method and any available estimate below.');
  ?></p>
  <p>Need a hand with fitment or an order detail? Reply to this email with your order number. We are here to help keep your car moving.</p>
  <p style="font-weight:600;color:#111">See you in the garage,<br>The Supreme Autoparts team</p>
</td></tr></table>
<?php
do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);
if (!empty($additional_content)) { echo wp_kses_post(wpautop(wptexturize($additional_content))); }
do_action('woocommerce_email_footer', $email);

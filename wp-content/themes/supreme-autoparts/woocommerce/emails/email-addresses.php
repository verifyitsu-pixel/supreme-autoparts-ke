<?php
/**
 * Billing and shipping addresses in WooCommerce emails: one quiet block that sits directly
 * under the order details (replaces the default bordered table with its own heading).
 *
 * @version 5.6.0 (overrides woocommerce/templates/emails/email-addresses.php)
 */
defined('ABSPATH') || exit;

$font = defined('SA_RCPT_FONT') ? SA_RCPT_FONT : 'Arial,Helvetica,sans-serif';
$label = 'font-family:' . $font . ';font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6B6B6B;font-weight:600;margin:0 0 6px';
$body = 'font-family:' . $font . ';font-size:14px;line-height:1.6;color:#222222;margin:0;word-break:normal;overflow-wrap:anywhere';
$billing = $order->get_formatted_billing_address();
$shipping = (!wc_ship_to_billing_address_only() && $order->needs_shipping_address()) ? $order->get_formatted_shipping_address() : '';
if (!$billing && !$shipping) {
    return;
}
$contact = '';
if ($order->get_billing_phone()) {
    $contact .= '<br>' . esc_html($order->get_billing_phone());
}
if ($order->get_billing_email()) {
    $contact .= '<br>' . esc_html($order->get_billing_email());
}
?>
<div style="border-top:1px solid #e6e6e6;margin:6px 0 20px;padding-top:16px">
<?php if ($billing) : ?>
	<div style="display:inline-block;vertical-align:top;width:48%;min-width:220px;margin:0 0 12px">
		<p style="<?php echo esc_attr($label); ?>"><?php esc_html_e('Billing address', 'woocommerce'); ?></p>
		<p style="<?php echo esc_attr($body); ?>"><?php echo wp_kses_post($billing . $contact); ?></p>
	</div>
<?php endif; ?>
<?php if ($shipping) : ?>
	<div style="display:inline-block;vertical-align:top;width:48%;min-width:220px;margin:0 0 12px">
		<p style="<?php echo esc_attr($label); ?>"><?php esc_html_e('Shipping address', 'woocommerce'); ?></p>
		<p style="<?php echo esc_attr($body); ?>"><?php echo wp_kses_post($shipping); ?></p>
	</div>
<?php endif; ?>
</div>

<?php
/**
 * Billing and shipping addresses in WooCommerce emails: one quiet block that sits directly
 * under the order details (replaces the default bordered table with its own heading).
 * Side by side on wide screens, stacked and tight on phones. Phone and email sit on their own
 * lines in small type so an address never wraps mid-word.
 *
 * @version 5.6.0 (overrides woocommerce/templates/emails/email-addresses.php)
 */
defined('ABSPATH') || exit;

$font = defined('SA_RCPT_FONT') ? SA_RCPT_FONT : 'Arial,Helvetica,sans-serif';
$label = 'font-family:' . $font . ';font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6B6B6B;font-weight:600;margin:0 0 4px;line-height:1.3';
$body = 'font-family:' . $font . ';font-size:14px;line-height:1.5;color:#222222;margin:0';
$small = 'font-family:' . $font . ';font-size:13px;line-height:1.5;color:#555555;margin:4px 0 0;white-space:nowrap';
$billing = $order->get_formatted_billing_address();
$shipping = (!wc_ship_to_billing_address_only() && $order->needs_shipping_address()) ? $order->get_formatted_shipping_address() : '';
if (!$billing && !$shipping) {
    return;
}
$cell = 'vertical-align:top;padding:0 16px 14px 0';
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;border-top:1px solid #e6e6e6;margin:6px 0 14px">
<tr>
<?php if ($billing) : ?>
	<td class="sa-col" valign="top" style="<?php echo esc_attr($cell); ?>;padding-top:14px">
		<p style="<?php echo esc_attr($label); ?>"><?php esc_html_e('Billing address', 'woocommerce'); ?></p>
		<p style="<?php echo esc_attr($body); ?>"><?php echo wp_kses_post($billing); ?></p>
		<?php if ($order->get_billing_phone()) : ?><p style="<?php echo esc_attr($small); ?>"><?php echo esc_html($order->get_billing_phone()); ?></p><?php endif; ?>
		<?php if ($order->get_billing_email()) : ?><p style="<?php echo esc_attr($small); ?>"><?php echo esc_html($order->get_billing_email()); ?></p><?php endif; ?>
	</td>
<?php endif; ?>
<?php if ($shipping) : ?>
	<td class="sa-col" valign="top" style="<?php echo esc_attr($cell); ?>;padding-top:14px">
		<p style="<?php echo esc_attr($label); ?>"><?php esc_html_e('Shipping address', 'woocommerce'); ?></p>
		<p style="<?php echo esc_attr($body); ?>"><?php echo wp_kses_post($shipping); ?></p>
	</td>
<?php endif; ?>
</tr>
</table>

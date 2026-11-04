<?php
/**
 * Pay for order form
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/form-pay.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 10.9.0
 */

defined( 'ABSPATH' ) || exit;

$totals = $order->get_order_item_totals();
unset($totals['cart_subtotal']);
// Present current policy without rewriting any historical order record.
foreach($totals as &$total){$total['label']=str_ireplace(' (non-refundable)','',$total['label']);}unset($total);
if(isset($available_gateways['paystack']))$available_gateways['paystack']->chosen=true;
?>
<style>.sa-careers-payment .payment_methods{list-style:none;padding:0}.sa-careers-payment .payment_methods>li{padding:14px 0;border-bottom:1px solid #e2e7eb}.sa-careers-payment .payment_methods label{display:inline;font-weight:700}.sa-careers-payment .payment_methods img{max-height:28px;width:auto!important;max-width:130px;vertical-align:middle}.sa-careers-payment .payment_box{font-size:14px;line-height:1.6;color:#52606d}.sa-careers-payment #place_order{width:100%;padding:15px 20px;background:#ffba35!important;color:#101820!important;border:0;border-radius:8px;font-weight:800;white-space:normal}.sa-careers-payment .shop_table{width:100%;border-collapse:collapse}.sa-careers-payment .shop_table th,.sa-careers-payment .shop_table td{padding:14px 8px;border-bottom:1px solid #e2e7eb;text-align:left}@media(max-width:600px){.sa-careers-payment{padding:20px 16px!important}.sa-careers-payment .shop_table th,.sa-careers-payment .shop_table td{font-size:14px!important;padding:12px 4px!important}}</style>
<section class="sa-careers-payment" style="max-width:760px;margin:24px auto;background:#fff;border:1px solid #dce2e7;border-top:5px solid #ffba35;border-radius:16px;padding:24px;color:#101820">
<p style="font-size:11px;font-weight:800;letter-spacing:.14em;color:#976000">CAREERS / PAYMENT</p>
<h1 style="font-size:28px;line-height:1.2;margin:10px 0 16px">Your application is saved.</h1>
<p>Complete the KES 1,000 application fee securely with Paystack.</p>
<p class="sa-careers-fee-notice" style="background:#fff5df;border:1px solid #ebc783;padding:14px;border-radius:10px;font-size:14px;line-height:1.6"><?php echo esc_html(sa_careers_fee_disclosure()); ?></p>
<form id="order_review" method="post">

	<table class="shop_table">
		<tfoot>
			<?php if ( $totals ) : ?>
				<?php foreach ( $totals as $total ) : ?>
					<tr>
						<th scope="row" colspan="2"><?php echo $total['label']; ?></th><?php // @codingStandardsIgnoreLine ?>
						<td class="product-total"><?php echo $total['value']; ?></td><?php // @codingStandardsIgnoreLine ?>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tfoot>
	</table>

	<?php
	/**
	 * Triggered from within the checkout/form-pay.php template, immediately before the payment section.
	 *
	 * @since 8.2.0
	 */
	do_action( 'woocommerce_pay_order_before_payment' ); 
	?>

	<div id="payment">
		<?php if ( $order->needs_payment() ) : ?>
			<ul class="wc_payment_methods payment_methods methods" aria-label="<?php esc_attr_e( 'Payment methods', 'woocommerce' ); ?>">
				<?php
				if ( ! empty( $available_gateways ) ) {
					foreach ( $available_gateways as $gateway ) {
						wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
					}
				} else {
					echo '<li>';
					wc_print_notice( apply_filters( 'woocommerce_no_available_payment_methods_message', esc_html__( 'Sorry, it seems that there are no available payment methods for your location. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' ) ), 'notice' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
					echo '</li>';
				}
				?>
			</ul>
		<?php endif; ?>
		<div class="form-row">
			<input type="hidden" name="woocommerce_pay" value="1" />

			<?php wc_get_template( 'checkout/terms.php' ); ?>

			<?php do_action( 'woocommerce_pay_order_before_submit' ); ?>

			<?php echo apply_filters( 'woocommerce_pay_order_button_html', '<button type="submit" class="button alt' . esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ) . '" id="place_order" value="' . esc_attr( 'Continue to Paystack' ) . '" data-value="' . esc_attr( 'Continue to Paystack' ) . '">' . esc_html( 'Continue to Paystack' ) . '</button>' ); // @codingStandardsIgnoreLine ?>

			<?php do_action( 'woocommerce_pay_order_after_submit' ); ?>

			<?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
		</div>
	</div>
</form>

</section>

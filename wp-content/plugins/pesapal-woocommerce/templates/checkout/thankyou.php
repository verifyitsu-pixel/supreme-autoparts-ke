<?php
/**
 * Thankyou page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/thankyou.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version 3.7.0
 */

defined( 'ABSPATH' ) || exit; ?>

<div class="woocommerce-order">
	<?php
		if ($order) {
			do_action( 'woocommerce_before_thankyou', $order->get_id() );
			if ( $order->has_status( 'failed' ) ) { ?>
				<style type="text/css">.entry-title{ display: none; }</style>
				<h4 style="font-weight: 600">Payment Failed</h4>
				<div id="border-right" style="padding: 0 15px 0 0">
					<p>We have noted your payment has failed. This could be because of several reasons;</p>
					<ol>
						<li>Your bank may have declined this transaction, kindly check with your bank.</li>
						<li>The card details entered may be incorrect, please confirm and try again.</li>
						<li>You have insufficient funds in the card/mobile money account you are attempting to use.</li>
					</ol>
					<p>For further assistance, kindly contact us using our ticketing portal on <a target="_new" href="http://support.pesapal.com">http://support.pesapal.com</a> or send an email to <a href="mailto:helpdesk@pesapal.com">helpdesk@pesapal.com</a> or call +254-070-921-9000</p>
				</div> 

				<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions">
					<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="button pay"><?php esc_html_e( 'Try Again', 'woocommerce' ); ?></a>
					<?php if ( is_user_logged_in() ) { ?>
						<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="button pay"><?php esc_html_e( 'My account', 'woocommerce' ); ?></a>
					<?php } ?>
				</p> <hr />
			<?php }else { ?>
				<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">
					<?php 
						if($order->has_status('completed')) {
							echo apply_filters( 'woocommerce_thankyou_order_received_text', 'Thank you!<br>Your order has been received successfully.<hr />', $order );
						} else {
							echo apply_filters( 'woocommerce_thankyou_order_received_text', 'Thank you! Your order is being processed.<br>Once confirmed, you will receive an Email/SMS notification.<br><br>Click <a id="refresh-status" href="javascript:void(0);">here</a> to refresh payment status.<hr />', $order );
							echo "<script>
								jQuery(document).ready(function () {
									jQuery('#refresh-status').on('click', function () {
										location.reload(); 
									});
								});
							</script>";
						}
					?>
				</p>
				<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
					<li class="woocommerce-order-overview__order order">
						<?php esc_html_e( 'Order number:', 'woocommerce' ); ?>
						<strong><?php echo $order->get_order_number(); ?></strong>
					</li>
					<li class="woocommerce-order-overview__date date">
						<?php esc_html_e( 'Date:', 'woocommerce' ); ?>
						<strong><?php echo wc_format_datetime( $order->get_date_created() ); ?></strong>
					</li>
					<?php if ( is_user_logged_in() && $order->get_user_id() === get_current_user_id() && $order->get_billing_email() ) { ?>
						<li class="woocommerce-order-overview__email email">
							<?php esc_html_e( 'Email:', 'woocommerce' ); ?>
							<strong><?php echo $order->get_billing_email(); ?></strong>
						</li>
					<?php } ?>
					<li class="woocommerce-order-overview__total total">
						<?php esc_html_e( 'Total:', 'woocommerce' ); ?>
						<strong><?php echo $order->get_formatted_order_total(); ?></strong>
					</li>
					<?php if ( $order->get_payment_method_title() ) { ?>
						<li class="woocommerce-order-overview__payment-method method">
							<?php esc_html_e( 'Payment method:', 'woocommerce' ); ?>
							<strong><?php echo wp_kses_post( $order->get_payment_method_title() ); ?></strong>
						</li>
					<?php } ?>
					<li class="woocommerce-order-overview__payment-method method">
							<?php esc_html_e( 'Payment status:', 'woocommerce' ); ?>
						<strong><?php echo ucfirst($order->get_status()); ?></strong>
					</li>
				</ul><?php 
			} 
			do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() );
			do_action( 'woocommerce_thankyou', $order->get_id() );
		} else { ?>
			<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">
				<?php echo apply_filters( 'woocommerce_thankyou_order_received_text', esc_html__( 'Thank you. Your order has been received.', 'woocommerce' ), null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</p>
	<?php } ?>
</div>

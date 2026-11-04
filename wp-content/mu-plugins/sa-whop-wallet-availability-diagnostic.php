<?php
/** Authenticated read-only wallet diagnostic. Never creates a token/order/payment. */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
add_action('admin_menu',static function():void {
    add_management_page('Wallet availability','Wallet availability','manage_woocommerce','sa-whop-wallet-availability',static function():void {
        if (!current_user_can('manage_woocommerce')) wp_die('Access denied.');
        if (!class_exists('WC_Gateway_Whop')) {echo '<p>Whop gateway unavailable.</p>';return;}
        $gateway=new WC_Gateway_Whop();
        if ($gateway->is_sandbox_mode()) {echo '<p>Existing production account required for this read-only wallet check.</p>';return;}
        $account=$gateway->get_company_id();
        if (!preg_match('/^biz_[A-Za-z0-9]+$/D',$account)) {echo '<p>Whop account unavailable.</p>';return;}
        echo '<div class="wrap"><h1>Wallet availability</h1><p>Read-only account, domain and device check. No wallet opens. No token, order or payment is created.</p><p>Display-only amount: USD1.00. It is never charged.</p><pre id="sa-whop-wallet-result" style="padding:20px;background:#fff;white-space:pre-wrap">Checking...</pre></div>';
        ?>
        <script src="https://cdn.whop.com/elements/amber/elements.js" data-whop-elements></script>
        <script>
        (async function(){
            const target=document.getElementById('sa-whop-wallet-result');
            try {
                const whop=window.WhopElements();
                const request=whop.payments.paymentRequest.create({accountId:<?php echo wp_json_encode($account); ?>,currency:'usd',amount:100,requestPayerEmail:true,paymentMethodConfiguration:{enabled:['apple_pay','google_pay'],disabled:['paypal'],include_platform_defaults:false}});
                const a=await request.canMakePayment({types:['apple_pay','google_pay']});
                target.textContent=JSON.stringify({origin:location.origin,accountId:<?php echo wp_json_encode($account); ?>,applePay:!!a.applePay,googlePay:!!a.googlePay,wallets:Object.keys(a.wallets||{}),diagnosticOnly:true,noPaymentAttempt:true},null,2);
                if(typeof request.destroy==='function')request.destroy();
            } catch(e) {target.textContent=JSON.stringify({error:e.message,diagnosticOnly:true,noPaymentAttempt:true},null,2);}
        })();
        </script>
        <?php
    });
});

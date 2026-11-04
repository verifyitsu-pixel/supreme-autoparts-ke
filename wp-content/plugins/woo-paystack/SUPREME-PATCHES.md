Vendored Paystack WooCommerce Payment Gateway 5.8.6 from WordPress.org.
Supreme patch: transaction verification fails closed unless successful, reference and order match, payment method is Paystack, exact amount and currency match, and test/live domain matches. Webhook HMAC uses hash_equals.
Paystack-only KES conversion and credential-gated visibility live in mu-plugins/sa-paystack.php. Other gateways and catalogue USD values are unchanged.

- Hosted initialization fails closed unless API status is true and the authorization URL is HTTPS on checkout.paystack.com.
Supreme patch: orders keep their own currency (USD); the Paystack charge is quoted server-side in the account settlement currency (KES) by SA_Paystack_Charge_Quote (includes/class-sa-paystack-charge-quote.php). The exact quote (order currency/total, rate, charge currency/amount) is locked per transaction reference at initialization, and the verify callback, webhook, token-charge path and card-receipt capture only accept the exact locked figures for that reference - anything else fails closed. Converted charge details are recorded in order meta and an order note; nothing is relabelled and order totals stay in USD.

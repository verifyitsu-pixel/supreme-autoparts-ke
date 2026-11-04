Vendored Paystack WooCommerce Payment Gateway 5.8.6 from WordPress.org.
Supreme patch: transaction verification fails closed unless successful, reference and order match, payment method is Paystack, exact amount and currency match, and test/live domain matches. Webhook HMAC uses hash_equals.
Paystack-only KES conversion and credential-gated visibility live in mu-plugins/sa-paystack.php. Other gateways and catalogue USD values are unchanged.

- Hosted initialization fails closed unless API status is true and the authorization URL is HTTPS on checkout.paystack.com.

<?php
/**
 * Send Woo order-pay and /pay customers to the Whop checkout URL.
 *
 * Payment is not collected in an on-site card embed. Unpaid orders redirect
 * to the stored Whop purchase URL (whop.com / sandbox.whop.com).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Whop_Checkout_Embed {

    public static function init(): void {
        add_action('template_redirect', [self::class, 'maybe_redirect_paid'], 5);
        add_action('template_redirect', [self::class, 'maybe_redirect_to_whop'], 6);
        add_filter('the_content', [self::class, 'filter_pay_page_content'], 20);
        add_action('before_woocommerce_pay', [self::class, 'hijack_order_pay'], 5);
    }

    /**
     * On-site payment URL (never whop.com). Works for guests via order key.
     */
    public static function pay_url(WC_Order $order): string {
        return add_query_arg(
            [
                'sa_whop_pay' => '1',
                'order'       => $order->get_id(),
                'key'         => $order->get_order_key(),
            ],
            home_url('/pay/')
        );
    }

    /**
     * Resolve order from ?sa_whop_pay=1&order=&key= (guest OK).
     */
    public static function order_from_request(): ?WC_Order {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['sa_whop_pay'])) {
            return null;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_id = absint($_GET['order'] ?? 0);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $key = sanitize_text_field(wp_unslash((string) ($_GET['key'] ?? '')));
        if ($order_id <= 0 || $key === '') {
            return null;
        }
        $order = wc_get_order($order_id);
        if (!$order || !hash_equals($order->get_order_key(), $key)) {
            return null;
        }
        return $order;
    }


    /**
     * True when $url is an https Whop checkout host (not an open redirect).
     */
    public static function is_allowed_whop_url(string $url): bool {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            return false;
        }
        return $host === 'whop.com' || str_ends_with($host, '.whop.com');
    }

    /**
     * Whop checkout link for this order (stored purchase URL, else checkout id).
     */
    public static function whop_checkout_url(WC_Order $order): string {
        $stored = trim((string) $order->get_meta('_whop_purchase_url'));
        if (self::is_allowed_whop_url($stored)) {
            return $stored;
        }
        $checkout_id = trim((string) $order->get_meta('_whop_checkout_id'));
        if ($checkout_id === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $checkout_id)) {
            return '';
        }
        $host = 'https://whop.com';
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            $gateway = $gateways['whop'] ?? null;
            if ($gateway instanceof WC_Gateway_Whop && $gateway->is_sandbox_mode()) {
                $host = 'https://sandbox.whop.com';
            }
        }
        return $host . '/checkout/' . rawurlencode($checkout_id) . '/';
    }

    /**
     * 302 to Whop. Caller must pass a URL that is_allowed_whop_url().
     */
    public static function redirect_customer_to_whop(string $url): void {
        if (!self::is_allowed_whop_url($url)) {
            return;
        }
        nocache_headers();
        wp_redirect($url, 302);
        exit;
    }

    /**
     * Unpaid Whop orders on /pay or order-pay go to Whop, not the card embed.
     */
    public static function maybe_redirect_to_whop(): void {
        $order = self::unpaid_whop_order_from_request();
        if (!$order instanceof WC_Order) {
            return;
        }
        $url = self::whop_checkout_url($order);
        if ($url === '') {
            return;
        }
        self::redirect_customer_to_whop($url);
    }

    private static function unpaid_whop_order_from_request(): ?WC_Order {
        $order = self::order_from_request();
        if (!$order && function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            global $wp;
            $oid = absint($wp->query_vars['order-pay'] ?? 0);
            $candidate = $oid ? wc_get_order($oid) : null;
            if ($candidate instanceof WC_Order) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $key = sanitize_text_field(wp_unslash((string) ($_GET['key'] ?? '')));
                if ($key !== '' && hash_equals($candidate->get_order_key(), $key)) {
                    $order = $candidate;
                }
            }
        }
        if (!$order instanceof WC_Order) {
            return null;
        }
        if ($order->get_payment_method() !== 'whop') {
            return null;
        }
        if ($order->is_paid() || $order->has_status(['processing', 'completed', 'cancelled', 'refunded', 'failed'])) {
            return null;
        }
        return $order;
    }

    public static function order_has_embed(WC_Order $order): bool {
        if ($order->get_payment_method() !== 'whop') {
            return false;
        }
        $plan = (string) $order->get_meta('_whop_plan_id');
        return $plan !== '';
    }

    public static function maybe_redirect_paid(): void {
        $order = self::order_from_request();
        if (!$order) {
            return;
        }
        if ($order->is_paid() || $order->has_status(['processing', 'completed'])) {
            $open = (string) $order->get_meta('_sa_open_pay');
            if ($open === '1') {
                wp_safe_redirect(
                    add_query_arg(
                        [
                            'paid'  => '1',
                            'order' => $order->get_id(),
                            'key'   => $order->get_order_key(),
                        ],
                        home_url('/pay/')
                    ),
                    302
                );
                exit;
            }
            wp_safe_redirect($order->get_checkout_order_received_url(), 302);
            exit;
        }
    }

    public static function is_embed_context(): bool {
        if (self::order_from_request()) {
            return true;
        }
        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            return true;
        }
        return false;
    }

    public static function maybe_enqueue(): void {
        $order = self::order_from_request();
        if (!$order && function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            global $wp;
            $oid = absint($wp->query_vars['order-pay'] ?? 0);
            $order = $oid ? wc_get_order($oid) : null;
        }
        if (!$order instanceof WC_Order || !self::order_has_embed($order)) {
            return;
        }
        if ($order->is_paid()) {
            return;
        }

        self::enqueue_for_order($order);
    }

    public static function maybe_print_preloads(): void {
        if (!self::is_embed_context()) {
            return;
        }
        if (class_exists('Whop_Payment_Methods')) {
            Whop_Payment_Methods::print_whop_preloads();
        }
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    public static function filter_csp_headers(array $headers): array {
        if (!self::is_embed_context()) {
            return $headers;
        }
        if (class_exists('Whop_Payment_Methods')) {
            return Whop_Payment_Methods::filter_csp_headers($headers);
        }
        return $headers;
    }

    public static function enqueue_for_order(WC_Order $order): void {
        wp_enqueue_style(
            'sa-whop-open-pay',
            WHOP_PAYMENTS_URL . 'assets/css/open-pay.css',
            [],
            WHOP_PAYMENTS_VERSION
        );

        wp_enqueue_script(
            'whop-checkout-loader',
            'https://js.whop.com/static/checkout/loader.js',
            [],
            null,
            false
        );
        wp_enqueue_script(
            'whop-checkout-index',
            'https://js.whop.com/static/checkout/index.js',
            ['whop-checkout-loader'],
            null,
            false
        );
        if (function_exists('wp_script_add_data')) {
            wp_script_add_data('whop-checkout-loader', 'strategy', 'defer');
            wp_script_add_data('whop-checkout-index', 'strategy', 'defer');
        }
        if (class_exists('Whop_Payment_Methods')) {
            add_filter('script_loader_src', [Whop_Payment_Methods::class, 'strip_whop_loader_ver'], 100, 2);
            add_filter('script_loader_tag', [Whop_Payment_Methods::class, 'tag_whop_loader_async_defer'], 100, 3);
        }

        wp_enqueue_script(
            'sa-whop-checkout-embed',
            WHOP_PAYMENTS_URL . 'assets/js/sa-whop-checkout-embed.js',
            ['whop-checkout-loader', 'whop-checkout-index'],
            WHOP_PAYMENTS_VERSION,
            true
        );

        $return = Whop_Webhook::return_url($order);
        $complete = $order->get_meta('_sa_open_pay') === '1'
            ? add_query_arg(
                [
                    'paid'  => '1',
                    'order' => $order->get_id(),
                    'key'   => $order->get_order_key(),
                ],
                home_url('/pay/')
            )
            : $order->get_checkout_order_received_url();

        wp_localize_script('sa-whop-checkout-embed', 'saWhopCheckoutEmbed', [
            'planId'     => (string) $order->get_meta('_whop_plan_id'),
            'sessionId'  => (string) $order->get_meta('_whop_checkout_id'),
            'returnUrl'  => $return,
            'completeUrl'=> $complete,
            'email'      => (string) $order->get_billing_email(),
            'orderId'    => (string) $order->get_id(),
            'i18n'       => [
                'loading'  => __('Loading secure payment…', 'whop-payments'),
                'ready'    => __('Enter payment details below.', 'whop-payments'),
                'error'    => __('Could not load payment form. Refresh and try again.', 'whop-payments'),
                'success'  => __('Payment received. Redirecting…', 'whop-payments'),
                'payError' => __('Payment failed. Check details and try again.', 'whop-payments'),
            ],
        ]);
    }

    /**
     * Replace /pay/ shortcode content with embed when paying an order.
     */
    public static function filter_pay_page_content(string $content): string {
        if (!is_singular('page')) {
            return $content;
        }
        $order = self::order_from_request();
        if (!$order instanceof WC_Order) {
            return $content;
        }
        if (!self::order_has_embed($order)) {
            return $content;
        }
        if ($order->is_paid()) {
            return $content;
        }

        return self::render_redirect_fallback($order);
    }

    /**
     * Woo order-pay with a valid key goes to Whop (not the on-site card form).
     */
    public static function hijack_order_pay(): void {
        global $wp;
        $order_id = absint($wp->query_vars['order-pay'] ?? 0);
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order || $order->get_payment_method() !== 'whop') {
            return;
        }
        if ($order->is_paid() || $order->has_status(['processing', 'completed', 'cancelled', 'refunded', 'failed'])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $key = sanitize_text_field(wp_unslash((string) ($_GET['key'] ?? '')));
        if ($key === '' || !hash_equals($order->get_order_key(), $key)) {
            return;
        }
        $url = self::whop_checkout_url($order);
        if ($url === '') {
            return;
        }
        self::redirect_customer_to_whop($url);
    }

    /**
     * Fallback if headers were already sent. No card iframe — link/JS to Whop only.
     */
    public static function render_redirect_fallback(WC_Order $order): string {
        return self::render_embed_markup($order);
    }

    public static function render_embed_markup(WC_Order $order): string {
        $url = self::whop_checkout_url($order);
        $logo = WHOP_PAYMENTS_URL . 'assets/img/logo-light.png';
        $total = wp_strip_all_tags($order->get_formatted_order_total());
        $num = $order->get_order_number();

        ob_start();
        ?>
        <div class="sa-open-pay" id="sa-whop-pay-redirect">
            <div class="sa-open-pay__card">
                <div class="sa-open-pay__brand">
                    <img class="sa-open-pay__logo" src="<?php echo esc_url($logo); ?>" alt="Supreme Autoparts" width="180" height="48" loading="eager" />
                    <h1 class="sa-open-pay__title"><?php echo esc_html__('Complete payment', 'whop-payments'); ?></h1>
                    <p class="sa-open-pay__sub">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: order number 2: formatted total */
                                __('Order #%1$s — %2$s. Continue to Whop to pay securely.', 'whop-payments'),
                                $num,
                                $total
                            )
                        );
                        ?>
                    </p>
                </div>
                <?php if ($url !== '') : ?>
                    <p class="sa-open-pay__notice" role="status">
                        <?php echo esc_html__('Redirecting to Whop…', 'whop-payments'); ?>
                    </p>
                    <p>
                        <a class="button" href="<?php echo esc_url($url); ?>">
                            <?php echo esc_html__('Pay on Whop', 'whop-payments'); ?>
                        </a>
                    </p>
                    <script>
                        window.location.replace(<?php echo wp_json_encode($url); ?>);
                    </script>
                <?php else : ?>
                    <p class="sa-open-pay__notice sa-open-pay__notice--err" role="alert">
                        <?php echo esc_html__('Payment link is unavailable. Return to checkout and try again.', 'whop-payments'); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

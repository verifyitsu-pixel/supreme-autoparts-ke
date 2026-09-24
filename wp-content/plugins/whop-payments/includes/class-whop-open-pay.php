<?php
/**
 * Public open-amount Whop pay page: [sa_open_pay]
 *
 * Customer enters USD amount + email → pending Woo order + Whop checkout.
 * payment.succeeded webhook marks the Woo order paid (processing/completed).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Whop_Open_Pay {

    private const MIN_AMOUNT = 1.00;
    private const MAX_AMOUNT = 100000.00;
    private const NOTE_MAX   = 200;
    private const ACTION     = 'sa_open_pay';

    public static function init(): void {
        add_shortcode('sa_open_pay', [self::class, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue_assets']);
        add_action('admin_post_nopriv_' . self::ACTION, [self::class, 'handle_post']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle_post']);
        add_action('init', [self::class, 'maybe_seed_pay_page'], 30);
    }

    /**
     * Idempotent: ensure /pay/ page exists with the shortcode (even if core seed lag).
     */
    public static function maybe_seed_pay_page(): void {
        if (get_option('sa_whop_open_pay_page_ver') === '2') {
            return;
        }
        $existing = get_page_by_path('pay');
        if (!$existing) {
            $by_name = get_posts([
                'name'           => 'pay',
                'post_type'      => 'page',
                'post_status'    => ['publish', 'draft', 'private'],
                'numberposts'    => 1,
                'posts_per_page' => 1,
            ]);
            $existing = $by_name[0] ?? null;
        }
        $content = '[sa_open_pay]';
        if ($existing) {
            $needs = !str_contains((string) $existing->post_content, '[sa_open_pay]');
            if ($needs || $existing->post_status !== 'publish' || $existing->post_name !== 'pay') {
                wp_update_post([
                    'ID'           => (int) $existing->ID,
                    'post_title'   => 'Pay',
                    'post_name'    => 'pay',
                    'post_content' => $content,
                    'post_status'  => 'publish',
                ]);
            }
        } else {
            wp_insert_post([
                'post_title'   => 'Pay',
                'post_name'    => 'pay',
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ], true);
        }
        update_option('sa_whop_open_pay_page_ver', '2');
        flush_rewrite_rules(false);
    }

    public static function maybe_enqueue_assets(): void {
        if (!is_singular('page')) {
            return;
        }
        $post = get_post();
        if (!$post || !has_shortcode((string) $post->post_content, 'sa_open_pay')) {
            if (!$post || $post->post_name !== 'pay') {
                return;
            }
        }
        wp_enqueue_style(
            'sa-whop-open-pay',
            WHOP_PAYMENTS_URL . 'assets/css/open-pay.css',
            [],
            WHOP_PAYMENTS_VERSION
        );
    }

    /**
     * @param array<string,string>|string $atts
     */
    public static function render_shortcode($atts = []): string {
        $error   = isset($_GET['sa_pay_err']) ? sanitize_text_field(wp_unslash((string) $_GET['sa_pay_err'])) : '';
        $paid    = isset($_GET['paid']) && (string) $_GET['paid'] === '1';
        $order_q = isset($_GET['order']) ? absint($_GET['order']) : 0;
        $logo    = WHOP_PAYMENTS_URL . 'assets/img/logo-light.png';
        $action  = esc_url(admin_url('admin-post.php'));
        $nonce   = wp_nonce_field(self::ACTION, 'sa_open_pay_nonce', true, false);
        $prefill_email = '';
        if (is_user_logged_in()) {
            $u = wp_get_current_user();
            $prefill_email = (string) $u->user_email;
        }

        $paid_notice = '';
        if ($paid && $order_q > 0) {
            $ord = wc_get_order($order_q);
            if ($ord) {
                $paid_notice = sprintf(
                    /* translators: 1: order number 2: formatted total */
                    __('Payment received for order #%1$s — %2$s. A confirmation email is on the way.', 'whop-payments'),
                    $ord->get_order_number(),
                    wp_strip_all_tags($ord->get_formatted_order_total())
                );
            }
        }
        if ($paid && $paid_notice === '') {
            $paid_notice = __('Thank you. If payment completed successfully, your order will appear in the store shortly.', 'whop-payments');
        }

        ob_start();
        ?>
        <div class="sa-open-pay" id="sa-open-pay">
            <div class="sa-open-pay__card">
                <div class="sa-open-pay__brand">
                    <img class="sa-open-pay__logo" src="<?php echo esc_url($logo); ?>" alt="Supreme Autoparts" width="180" height="48" loading="eager" />
                    <h1 class="sa-open-pay__title"><?php echo esc_html__('Make a payment', 'whop-payments'); ?></h1>
                    <p class="sa-open-pay__sub"><?php echo esc_html__('Enter the amount in US dollars (USD). You will be redirected to a secure checkout. Paid amounts create an order in our store.', 'whop-payments'); ?></p>
                </div>

                <?php if ($paid_notice !== '') : ?>
                    <div class="sa-open-pay__notice sa-open-pay__notice--ok" role="status">
                        <?php echo esc_html($paid_notice); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error !== '') : ?>
                    <div class="sa-open-pay__notice sa-open-pay__notice--err" role="alert">
                        <?php echo esc_html($error); ?>
                    </div>
                <?php endif; ?>

                <form class="sa-open-pay__form" method="post" action="<?php echo $action; ?>" novalidate>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
                    <?php echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                    <label class="sa-open-pay__label" for="sa_open_pay_email">
                        <?php echo esc_html__('Email (for receipt)', 'whop-payments'); ?>
                    </label>
                    <input
                        class="sa-open-pay__input sa-open-pay__input--note"
                        type="email"
                        name="email"
                        id="sa_open_pay_email"
                        required
                        autocomplete="email"
                        value="<?php echo esc_attr($prefill_email); ?>"
                        placeholder="<?php echo esc_attr__('you@example.com', 'whop-payments'); ?>"
                    />

                    <label class="sa-open-pay__label" for="sa_open_pay_amount">
                        <?php echo esc_html__('Amount (USD)', 'whop-payments'); ?>
                    </label>
                    <div class="sa-open-pay__amount-wrap">
                        <span class="sa-open-pay__currency" aria-hidden="true">USD</span>
                        <input
                            class="sa-open-pay__input"
                            type="number"
                            name="amount"
                            id="sa_open_pay_amount"
                            inputmode="decimal"
                            min="1"
                            max="100000"
                            step="0.01"
                            required
                            placeholder="0.00"
                            autocomplete="off"
                        />
                    </div>
                    <p class="sa-open-pay__hint"><?php echo esc_html__('Minimum $1.00 · Maximum $100,000.00', 'whop-payments'); ?></p>

                    <label class="sa-open-pay__label" for="sa_open_pay_note">
                        <?php echo esc_html__('Note (optional)', 'whop-payments'); ?>
                    </label>
                    <input
                        class="sa-open-pay__input sa-open-pay__input--note"
                        type="text"
                        name="note"
                        id="sa_open_pay_note"
                        maxlength="<?php echo (int) self::NOTE_MAX; ?>"
                        placeholder="<?php echo esc_attr__('Invoice #, order ref, or description', 'whop-payments'); ?>"
                        autocomplete="off"
                    />

                    <button type="submit" class="sa-open-pay__btn">
                        <?php echo esc_html__('Pay', 'whop-payments'); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function handle_post(): void {
        $pay_url = home_url('/pay/');

        if (!isset($_POST['sa_open_pay_nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash((string) $_POST['sa_open_pay_nonce'])),
            self::ACTION
        )) {
            self::redirect_error($pay_url, __('Security check failed. Please try again.', 'whop-payments'));
        }

        $amount_raw = isset($_POST['amount']) ? wp_unslash((string) $_POST['amount']) : '';
        $amount_raw = str_replace([',', ' '], ['', ''], $amount_raw);
        $amount     = round((float) $amount_raw, 2);

        if (!is_numeric($amount_raw) || $amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            self::redirect_error(
                $pay_url,
                __('Enter a valid USD amount between $1.00 and $100,000.00.', 'whop-payments')
            );
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash((string) $_POST['email'])) : '';
        if ($email === '' || !is_email($email)) {
            self::redirect_error($pay_url, __('Enter a valid email address for your receipt.', 'whop-payments'));
        }

        $note = isset($_POST['note']) ? sanitize_text_field(wp_unslash((string) $_POST['note'])) : '';
        if (strlen($note) > self::NOTE_MAX) {
            $note = substr($note, 0, self::NOTE_MAX);
        }

        $client = self::client();
        if ($client === null) {
            self::redirect_error($pay_url, __('Payments are temporarily unavailable. Please try again later.', 'whop-payments'));
        }

        $order = self::create_pending_order($amount, $email, $note);
        if (!$order instanceof WC_Order) {
            self::redirect_error($pay_url, __('Could not create order. Please try again.', 'whop-payments'));
        }

        $order_id  = (string) $order->get_id();
        $order_key = $order->get_order_key();
        $redirect  = add_query_arg(
            [
                'paid'  => '1',
                'order' => $order_id,
                'key'   => $order_key,
            ],
            home_url('/pay/')
        );

        $description = $note !== ''
            ? $note
            : sprintf(
                /* translators: %s: order number */
                __('Custom payment — Order #%s — Supreme Autoparts', 'whop-payments'),
                $order->get_order_number()
            );

        $result = $client->create_checkout_configuration([
            'amount'              => $amount,
            'currency'            => 'usd',
            'order_id'            => $order_id,
            'order_key'           => $order_key,
            'title'               => sprintf('Payment — Order #%s', $order->get_order_number()),
            'product_title'       => 'Custom payment — Supreme Autoparts',
            'product_external_id' => 'open-pay-' . $order_id,
            'description'         => $description,
            'redirect_url'        => $redirect,
            'source'              => 'supreme-autoparts-open-pay',
            'note'                => $note,
            'metadata'            => [
                'amount_usd' => (string) $amount,
                'open_pay'   => '1',
                'email'      => $email,
            ],
        ]);

        if (empty($result['success']) || empty($result['purchase_url'])) {
            $order->update_status('cancelled', __('Whop checkout creation failed; order cancelled.', 'whop-payments'));
            $msg = (string) ($result['message'] ?? __('Could not start checkout. Please try again.', 'whop-payments'));
            self::redirect_error($pay_url, $msg);
        }

        $checkout_id = (string) ($result['checkout_id'] ?? '');
        if ($checkout_id !== '') {
            $order->update_meta_data('_whop_checkout_id', $checkout_id);
            $order->save();
        }
        $order->add_order_note(sprintf(
            /* translators: %s: Whop purchase URL host */
            __('Open-pay: redirected customer to Whop checkout (%s).', 'whop-payments'),
            (string) wp_parse_url((string) $result['purchase_url'], PHP_URL_HOST)
        ));

        $purchase_url = (string) $result['purchase_url'];
        $host = wp_parse_url($purchase_url, PHP_URL_HOST);
        $allowed = ['whop.com', 'www.whop.com', 'sandbox.whop.com'];
        if (is_string($host) && in_array(strtolower($host), $allowed, true)) {
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
            wp_redirect($purchase_url, 302);
            exit;
        }
        self::redirect_error($pay_url, __('Invalid checkout URL returned. Please try again.', 'whop-payments'));
    }

    /**
     * Create a pending Woo order for an open-amount payment.
     */
    public static function create_pending_order(float $amount, string $email, string $note = '', array $extra_meta = []): ?WC_Order {
        if (!function_exists('wc_create_order')) {
            return null;
        }

        try {
            $order = wc_create_order([
                'status'      => 'pending',
                'customer_id' => self::resolve_customer_id($email),
                'created_via' => 'sa_open_pay',
            ]);
        } catch (Throwable $e) {
            error_log('[whop-payments] open-pay create order failed: ' . $e->getMessage());
            return null;
        }

        if (!$order instanceof WC_Order) {
            return null;
        }

        $item = new WC_Order_Item_Fee();
        $item->set_name(__('Custom payment', 'whop-payments'));
        $item->set_total($amount);
        $item->set_tax_status('none');
        $order->add_item($item);

        $order->set_currency('USD');
        $order->set_billing_email($email);
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($user->user_email === $email) {
                $order->set_billing_first_name((string) $user->first_name);
                $order->set_billing_last_name((string) $user->last_name);
            }
        }

        $order->set_payment_method('whop');
        $order->set_payment_method_title(__('Whop', 'whop-payments'));
        $order->update_meta_data('_sa_open_pay', '1');
        $order->update_meta_data('_sa_open_pay_amount_usd', (string) $amount);
        if ($note !== '') {
            $order->update_meta_data('_sa_open_pay_note', $note);
            $order->add_order_note(
                sprintf(
                    /* translators: %s: customer note */
                    __('Customer note: %s', 'whop-payments'),
                    $note
                ),
                false,
                true
            );
        }
        foreach ($extra_meta as $k => $v) {
            $order->update_meta_data((string) $k, $v);
        }

        $order->calculate_totals(false);
        $order->set_total($amount);
        $order->save();

        return $order;
    }

    private static function resolve_customer_id(string $email): int {
        if (is_user_logged_in()) {
            $uid = get_current_user_id();
            $user = get_userdata($uid);
            if ($user && strcasecmp((string) $user->user_email, $email) === 0) {
                return $uid;
            }
        }
        $by_email = get_user_by('email', $email);
        return $by_email ? (int) $by_email->ID : 0;
    }

    private static function redirect_error(string $pay_url, string $message): void {
        $url = add_query_arg('sa_pay_err', $message, $pay_url);
        wp_safe_redirect($url, 302);
        exit;
    }

    private static function env(string $key): string {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = $_ENV[$key] ?? $_SERVER[$key] ?? '';
        }
        return is_string($v) ? trim($v) : '';
    }

    private static function client(): ?Whop_Api_Client {
        $api_key    = self::env('WHOP_API_KEY');
        $company_id = self::env('WHOP_COMPANY_ID');
        if ($api_key === '' || $company_id === '') {
            if (class_exists('WC_Gateway_Whop')) {
                $gw = new WC_Gateway_Whop();
                $api_key    = $gw->get_api_key();
                $company_id = $gw->get_company_id();
            }
        }
        if ($api_key === '' || $company_id === '') {
            return null;
        }
        $sandbox_env = self::env('WHOP_SANDBOX');
        if ($sandbox_env !== '') {
            $sandbox = in_array(strtolower($sandbox_env), ['1', 'true', 'yes', 'on'], true);
        } elseif (class_exists('WC_Gateway_Whop')) {
            $gw = new WC_Gateway_Whop();
            $sandbox = $gw->is_sandbox_mode();
        } else {
            $sandbox = false;
        }
        return new Whop_Api_Client($api_key, $company_id, $sandbox);
    }
}

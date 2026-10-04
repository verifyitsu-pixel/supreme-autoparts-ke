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
        // Existing unpaid order: send them to Whop (HTTP redirect already ran; this is the fallback).
        if (class_exists('Whop_Checkout_Embed')) {
            $pay_order = Whop_Checkout_Embed::order_from_request();
            if ($pay_order instanceof WC_Order && $pay_order->get_payment_method() === 'whop' && !$pay_order->is_paid()) {
                return Whop_Checkout_Embed::render_redirect_fallback($pay_order);
            }
        }

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
        if (isset($_GET['email'])) {
            $ge = sanitize_email(wp_unslash((string) $_GET['email']));
            if ($ge !== '' && is_email($ge)) {
                $prefill_email = $ge;
            }
        }
        $prefill_amount = '';
        if (isset($_GET['amount'])) {
            $ga = str_replace([',', ' '], ['', ''], wp_unslash((string) $_GET['amount']));
            if (is_numeric($ga)) {
                $av = round((float) $ga, 2);
                if ($av >= self::MIN_AMOUNT && $av <= self::MAX_AMOUNT) {
                    $prefill_amount = number_format($av, 2, '.', '');
                }
            }
        }
        $prefill_note = '';
        if (isset($_GET['note'])) {
            $prefill_note = sanitize_text_field(wp_unslash((string) $_GET['note']));
            if (strlen($prefill_note) > self::NOTE_MAX) {
                $prefill_note = substr($prefill_note, 0, self::NOTE_MAX);
            }
        }

        // Success return from Whop: full confirmation page (hide the pay form).
        if ($paid) {
            return self::render_success_page($order_q, $logo);
        }

        ob_start();
        ?>
        <div class="sa-open-pay" id="sa-open-pay">
            <div class="sa-open-pay__card">
                <div class="sa-open-pay__brand">
                    <img class="sa-open-pay__logo" src="<?php echo esc_url($logo); ?>" alt="Supreme Autoparts" width="180" height="48" loading="eager" />
                    <h1 class="sa-open-pay__title"><?php echo esc_html__('Make a payment', 'whop-payments'); ?></h1>
                    <p class="sa-open-pay__sub"><?php echo esc_html__('Enter the amount in US dollars (USD). You will be redirected to Whop to pay securely. Paid amounts create an order in our store.', 'whop-payments'); ?></p>
                </div>

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
                            value="<?php echo esc_attr($prefill_amount); ?>"
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
                        value="<?php echo esc_attr($prefill_note); ?>"
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

    /**
     * Full-page confirmation after Whop return (?paid=1&order=&key=).
     * Requires a matching order key before showing order details.
     */
    private static function render_success_page(int $order_id, string $logo): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';

        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        $can_view = $order instanceof WC_Order
            && $key !== ''
            && hash_equals($order->get_order_key(), $key);

        if (!$can_view) {
            ob_start();
            ?>
            <div class="sa-open-pay sa-open-pay--success" id="sa-open-pay">
                <div class="sa-open-pay__card sa-open-pay__card--success">
                    <div class="sa-open-pay__brand sa-open-pay__brand--success">
                        <img class="sa-open-pay__logo" src="<?php echo esc_url($logo); ?>" alt="Supreme Autoparts" width="180" height="48" loading="eager" />
                    </div>
                    <div class="sa-open-pay__success-hero" role="status">
                        <div class="sa-open-pay__success-check" aria-hidden="true">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="11" stroke="currentColor" stroke-width="1.75"/>
                                <path d="M7 12.5l3.2 3.2L17 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h1 class="sa-open-pay__success-title"><?php echo esc_html__('Payment received', 'whop-payments'); ?></h1>
                        <p class="sa-open-pay__success-lead"><?php echo esc_html__('Thank you. If payment completed successfully, your order will appear in the store shortly. Check your email for a confirmation.', 'whop-payments'); ?></p>
                    </div>
                    <a class="sa-open-pay__btn sa-open-pay__btn--secondary" href="<?php echo esc_url(home_url('/pay/')); ?>">
                        <?php echo esc_html__('Make another payment', 'whop-payments'); ?>
                    </a>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /** @var WC_Order $order */
        $email      = (string) $order->get_billing_email();
        $total_html = wp_strip_all_tags($order->get_formatted_order_total());
        $method     = (string) $order->get_payment_method_title();
        if ($method === '') {
            $method = __('Whop', 'whop-payments');
        }
        $status_label = wc_get_order_status_name($order->get_status());
        $is_open_pay  = (string) $order->get_meta('_sa_open_pay') === '1';
        $open_note    = (string) $order->get_meta('_sa_open_pay_note');
        $received_url = $order->get_checkout_order_received_url();
        $view_url     = '';
        if (is_user_logged_in() && (int) $order->get_user_id() === get_current_user_id() && $order->get_user_id() > 0) {
            $view_url = $order->get_view_order_url();
        }

        $line_rows = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $qty = (int) $item->get_quantity();
            $name = $item->get_name();
            if ($qty > 1) {
                $name = sprintf('%s × %d', $name, $qty);
            }
            $line_rows[] = [
                'label' => $name,
                'value' => wp_strip_all_tags($order->get_formatted_line_subtotal($item)),
            ];
        }
        foreach ($order->get_fees() as $fee) {
            if (!$fee instanceof WC_Order_Item_Fee) {
                continue;
            }
            $line_rows[] = [
                'label' => $fee->get_name(),
                'value' => wp_strip_all_tags(wc_price((float) $fee->get_total(), ['currency' => $order->get_currency()])),
            ];
        }
        foreach ($order->get_shipping_methods() as $ship) {
            if (!$ship instanceof WC_Order_Item_Shipping) {
                continue;
            }
            $ship_total = (float) $ship->get_total();
            $line_rows[] = [
                'label' => sprintf(
                    /* translators: %s: shipping method name */
                    __('Shipping — %s', 'whop-payments'),
                    $ship->get_name()
                ),
                'value' => $ship_total > 0
                    ? wp_strip_all_tags(wc_price($ship_total, ['currency' => $order->get_currency()]))
                    : __('Free', 'whop-payments'),
            ];
        }
        if ($line_rows === [] && $is_open_pay) {
            $line_rows[] = [
                'label' => __('Custom payment', 'whop-payments'),
                'value' => $total_html,
            ];
        }

        ob_start();
        ?>
        <div class="sa-open-pay sa-open-pay--success" id="sa-open-pay">
            <div class="sa-open-pay__card sa-open-pay__card--success">
                <div class="sa-open-pay__brand sa-open-pay__brand--success">
                    <img class="sa-open-pay__logo" src="<?php echo esc_url($logo); ?>" alt="Supreme Autoparts" width="180" height="48" loading="eager" />
                </div>

                <div class="sa-open-pay__success-hero" role="status">
                    <div class="sa-open-pay__success-check" aria-hidden="true">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="12" cy="12" r="11" stroke="currentColor" stroke-width="1.75"/>
                            <path d="M7 12.5l3.2 3.2L17 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h1 class="sa-open-pay__success-title"><?php echo esc_html__('Payment received', 'whop-payments'); ?></h1>
                    <p class="sa-open-pay__success-order">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s: order number */
                                __('Order #%s', 'whop-payments'),
                                $order->get_order_number()
                            )
                        );
                        ?>
                    </p>
                    <?php if ($email !== '') : ?>
                        <p class="sa-open-pay__success-lead">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %s: customer email */
                                    __('A confirmation email is on the way to %s.', 'whop-payments'),
                                    $email
                                )
                            );
                            ?>
                        </p>
                    <?php else : ?>
                        <p class="sa-open-pay__success-lead"><?php echo esc_html__('A confirmation email is on the way.', 'whop-payments'); ?></p>
                    <?php endif; ?>
                </div>

                <div class="sa-open-pay__success-total">
                    <span class="sa-open-pay__success-total-label"><?php echo esc_html__('Amount paid', 'whop-payments'); ?></span>
                    <span class="sa-open-pay__success-total-value"><?php echo esc_html($total_html); ?></span>
                </div>

                <section class="sa-open-pay__summary" aria-labelledby="sa-pay-summary-heading">
                    <h2 id="sa-pay-summary-heading" class="sa-open-pay__summary-heading"><?php echo esc_html__('Payment summary', 'whop-payments'); ?></h2>
                    <dl class="sa-open-pay__summary-list">
                        <div class="sa-open-pay__summary-row">
                            <dt><?php echo esc_html__('Status', 'whop-payments'); ?></dt>
                            <dd><span class="sa-open-pay__status-pill"><?php echo esc_html($status_label); ?></span></dd>
                        </div>
                        <div class="sa-open-pay__summary-row">
                            <dt><?php echo esc_html__('Order', 'whop-payments'); ?></dt>
                            <dd>#<?php echo esc_html($order->get_order_number()); ?></dd>
                        </div>
                        <div class="sa-open-pay__summary-row">
                            <dt><?php echo esc_html__('Amount', 'whop-payments'); ?></dt>
                            <dd><?php echo esc_html($total_html); ?></dd>
                        </div>
                        <div class="sa-open-pay__summary-row">
                            <dt><?php echo esc_html__('Method', 'whop-payments'); ?></dt>
                            <dd><?php echo esc_html($method); ?></dd>
                        </div>
                        <?php if ($email !== '') : ?>
                            <div class="sa-open-pay__summary-row">
                                <dt><?php echo esc_html__('Receipt email', 'whop-payments'); ?></dt>
                                <dd><?php echo esc_html($email); ?></dd>
                            </div>
                        <?php endif; ?>
                    </dl>
                </section>

                <details class="sa-open-pay__order-details" open>
                    <summary class="sa-open-pay__order-details-summary"><?php echo esc_html__('See order info', 'whop-payments'); ?></summary>
                    <div class="sa-open-pay__order-details-body">
                        <h2 class="sa-open-pay__summary-heading"><?php echo esc_html__('Order summary', 'whop-payments'); ?></h2>
                        <?php if ($is_open_pay && $open_note !== '') : ?>
                            <p class="sa-open-pay__order-note">
                                <span class="sa-open-pay__order-note-label"><?php echo esc_html__('Note', 'whop-payments'); ?></span>
                                <?php echo esc_html($open_note); ?>
                            </p>
                        <?php elseif ($is_open_pay) : ?>
                            <p class="sa-open-pay__order-note sa-open-pay__order-note--muted">
                                <?php echo esc_html__('Custom amount payment (no note provided).', 'whop-payments'); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($line_rows !== []) : ?>
                            <ul class="sa-open-pay__line-items">
                                <?php foreach ($line_rows as $row) : ?>
                                    <li class="sa-open-pay__line-item">
                                        <span class="sa-open-pay__line-item-label"><?php echo esc_html($row['label']); ?></span>
                                        <span class="sa-open-pay__line-item-value"><?php echo esc_html($row['value']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <div class="sa-open-pay__line-total">
                            <span><?php echo esc_html__('Order total', 'whop-payments'); ?></span>
                            <strong><?php echo esc_html($total_html); ?></strong>
                        </div>
                    </div>
                </details>

                <div class="sa-open-pay__success-actions">
                    <a class="sa-open-pay__btn" href="<?php echo esc_url($received_url); ?>">
                        <?php echo esc_html__('View order details', 'whop-payments'); ?>
                    </a>
                    <?php if ($view_url !== '') : ?>
                        <a class="sa-open-pay__link" href="<?php echo esc_url($view_url); ?>">
                            <?php echo esc_html__('Open in my account', 'whop-payments'); ?>
                        </a>
                    <?php endif; ?>
                    <a class="sa-open-pay__link" href="<?php echo esc_url(home_url('/pay/')); ?>">
                        <?php echo esc_html__('Make another payment', 'whop-payments'); ?>
                    </a>
                </div>
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

        // Rate-limit open-pay checkout creation (abuse → pending Woo order spam).
        $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $ip = preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0.0.0.0';
        $rate_key = 'sa_open_pay_' . md5($ip);
        $hits = (int) get_transient($rate_key);
        if ($hits >= 5) {
            self::redirect_error($pay_url, __('Too many payment attempts. Please wait a minute and try again.', 'whop-payments'));
        }
        set_transient($rate_key, $hits + 1, MINUTE_IN_SECONDS);

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
            'adaptive_pricing_enabled' => true,
            'order_id'            => $order_id,
            'order_key'           => $order_key,
            'title'               => Whop_Api_Client::default_order_plan_title((string) $order->get_order_number()),
            'product_title'       => 'SA Custom Pay',
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

        if (empty($result['success']) || empty($result['plan_id'])) {
            $order->update_status('cancelled', __('Whop checkout creation failed; order cancelled.', 'whop-payments'));
            $msg = (string) ($result['message'] ?? __('Could not start checkout. Please try again.', 'whop-payments'));
            self::redirect_error($pay_url, $msg);
        }

        $checkout_id = (string) ($result['checkout_id'] ?? '');
        $plan_id     = (string) ($result['plan_id'] ?? '');
        if ($checkout_id !== '') {
            $order->update_meta_data('_whop_checkout_id', $checkout_id);
        }
        $order->update_meta_data('_whop_plan_id', $plan_id);
        $purchase_url = (string) ($result['purchase_url'] ?? '');
        if ($purchase_url !== '') {
            $order->update_meta_data('_whop_purchase_url', $purchase_url);
        }
        $order->add_order_note(__('Open-pay: customer redirected to Whop checkout.', 'whop-payments'));
        $order->save();

        if (class_exists('Whop_Checkout_Embed') && Whop_Checkout_Embed::is_allowed_whop_url($purchase_url)) {
            Whop_Checkout_Embed::redirect_customer_to_whop($purchase_url);
        }
        self::redirect_error($pay_url, __('Could not start checkout. Please try again.', 'whop-payments'));
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

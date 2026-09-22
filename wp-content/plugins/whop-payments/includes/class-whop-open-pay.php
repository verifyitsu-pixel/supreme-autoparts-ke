<?php
/**
 * Public open-amount Whop pay page: [sa_open_pay]
 *
 * Customer enters a USD amount → server creates checkout_configurations → redirect to purchase_url.
 * Does not touch WooCommerce cart checkout.
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
        if (get_option('sa_whop_open_pay_page_ver') === '1') {
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
        update_option('sa_whop_open_pay_page_ver', '1');
        flush_rewrite_rules(false);
    }

    public static function maybe_enqueue_assets(): void {
        if (!is_singular('page')) {
            return;
        }
        $post = get_post();
        if (!$post || !has_shortcode((string) $post->post_content, 'sa_open_pay')) {
            // Also allow slug pay even if content was filtered.
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
        $logo    = WHOP_PAYMENTS_URL . 'assets/img/logo-light.png';
        $action  = esc_url(admin_url('admin-post.php'));
        $nonce   = wp_nonce_field(self::ACTION, 'sa_open_pay_nonce', true, false);

        ob_start();
        ?>
        <div class="sa-open-pay" id="sa-open-pay">
            <div class="sa-open-pay__card">
                <div class="sa-open-pay__brand">
                    <img class="sa-open-pay__logo" src="<?php echo esc_url($logo); ?>" alt="Supreme Autoparts" width="180" height="48" loading="eager" />
                    <h1 class="sa-open-pay__title"><?php echo esc_html__('Make a payment', 'whop-payments'); ?></h1>
                    <p class="sa-open-pay__sub"><?php echo esc_html__('Enter the amount in US dollars (USD). You will be redirected to a secure checkout to complete payment.', 'whop-payments'); ?></p>
                </div>

                <?php if ($paid) : ?>
                    <div class="sa-open-pay__notice sa-open-pay__notice--ok" role="status">
                        <?php echo esc_html__('Thank you. If payment completed successfully, confirmation may take a few seconds.', 'whop-payments'); ?>
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

        $note = isset($_POST['note']) ? sanitize_text_field(wp_unslash((string) $_POST['note'])) : '';
        if (strlen($note) > self::NOTE_MAX) {
            $note = substr($note, 0, self::NOTE_MAX);
        }

        $client = self::client();
        if ($client === null) {
            self::redirect_error($pay_url, __('Payments are temporarily unavailable. Please try again later.', 'whop-payments'));
        }

        $order_id = 'openpay-' . uniqid('', false);
        $redirect = home_url('/pay/?paid=1');

        $description = $note !== ''
            ? $note
            : __('Open amount payment — Supreme Autoparts', 'whop-payments');

        $result = $client->create_checkout_configuration([
            'amount'               => $amount,
            'currency'             => 'usd',
            'order_id'             => $order_id,
            'order_key'            => '',
            'title'                => 'Payment — Supreme Autoparts',
            'product_title'        => 'Payment — Supreme Autoparts',
            'product_external_id'  => $order_id,
            'description'          => $description,
            'redirect_url'         => $redirect,
            'source'               => 'supreme-autoparts-open-pay',
            'note'                 => $note,
            'metadata'             => [
                'amount_usd' => (string) $amount,
                'open_pay'   => '1',
            ],
        ]);

        if (empty($result['success']) || empty($result['purchase_url'])) {
            $msg = (string) ($result['message'] ?? __('Could not start checkout. Please try again.', 'whop-payments'));
            self::redirect_error($pay_url, $msg);
        }

        $purchase_url = (string) $result['purchase_url'];
        // External Whop checkout host — allowlist then hard redirect.
        $host = wp_parse_url($purchase_url, PHP_URL_HOST);
        $allowed = ['whop.com', 'www.whop.com', 'sandbox.whop.com'];
        if (is_string($host) && in_array(strtolower($host), $allowed, true)) {
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
            wp_redirect($purchase_url, 302);
            exit;
        }
        self::redirect_error($pay_url, __('Invalid checkout URL returned. Please try again.', 'whop-payments'));
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
            // Fallback to gateway options if env missing (local/dev).
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

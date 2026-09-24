<?php
/**
 * WooCommerce payment gateway: Whop
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Whop extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'whop';
        $this->method_title       = __('Whop Checkout', 'whop-payments');
        $this->method_description = __(
            'Accept payments via Whop Checkout (one-time plans created from the cart total). Configure secrets via Railway env vars WHOP_* or below.',
            'whop-payments'
        );
        $this->has_fields         = true;
        $this->supports           = ['products', 'tokenization'];
        $this->icon               = ''; // Custom badge rendered in get_icon() / payment_fields().

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled     = $this->get_option('enabled', 'no');
        // Auto-enable when WHOP_API_KEY + WHOP_COMPANY_ID are present (env wins).
        if ($this->enabled !== 'yes' && $this->env('WHOP_API_KEY') !== '' && $this->env('WHOP_COMPANY_ID') !== '') {
            $this->enabled = 'yes';
        }
        $this->title             = $this->get_option('title', __('Card', 'whop-payments'));
        $this->description       = $this->get_option('description', '');
        $this->order_button_text = __('Pay', 'whop-payments');

        // Keep checkout label clean (no processor branding for shoppers).
        $legacy = ['', 'Whop', 'Whop Checkout'];
        if (in_array(trim((string) $this->title), $legacy, true)) {
            $this->title = __('Card', 'whop-payments');
        }

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

        // One-time shopper-facing cleanup (drop Whop branding at checkout).
        if (get_option('sa_whop_checkout_clean_v1') !== '1') {
            $s = get_option('woocommerce_whop_settings', []);
            if (!is_array($s)) {
                $s = [];
            }
            $s['title'] = 'Card';
            $s['description'] = '';
            $s['enabled'] = 'yes';
            update_option('woocommerce_whop_settings', $s);
            update_option('sa_whop_checkout_clean_v1', '1');
            $this->title = 'Card';
            $this->description = '';
        }
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
    }

    /**
     * Badge shown next to the payment method title at checkout.
     */
    public function get_icon(): string {
        return apply_filters('woocommerce_gateway_icon', '', $this->id);
    }

    /**
     * Minimal payment box — card entry happens on the secure pay page after Pay.
     */
    public function payment_fields(): void {
        // Intentionally empty: no processor badges or redirect marketing copy.
        if ($this->description !== '') {
            echo wpautop(wp_kses_post($this->description));
        }
    }

    public function init_form_fields(): void {
        $callback = Whop_Webhook::webhook_url();

        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'whop-payments'),
                'type'    => 'checkbox',
                'label'   => __('Enable Whop', 'whop-payments'),
                'default' => 'yes',
            ],
            'title' => [
                'title'       => __('Title', 'whop-payments'),
                'type'        => 'text',
                'description' => __('Payment method title shown at checkout.', 'whop-payments'),
                'default'     => __('Card', 'whop-payments'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'whop-payments'),
                'type'        => 'textarea',
                'description' => __('Payment method description shown at checkout.', 'whop-payments'),
                'default'     => '',
            ],
            'company_id' => [
                'title'       => __('Company / Account ID', 'whop-payments'),
                'type'        => 'text',
                'description' => __('Whop company id (biz_…). Overridden by WHOP_COMPANY_ID env when set.', 'whop-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'api_key' => [
                'title'       => __('API Key', 'whop-payments'),
                'type'        => 'password',
                'description' => __('Account API key. Overridden by WHOP_API_KEY env when set. Never commit secrets.', 'whop-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'webhook_secret' => [
                'title'       => __('Webhook Secret', 'whop-payments'),
                'type'        => 'password',
                'description' => __('Signing secret from Whop webhook endpoint (ws_… / whsec_…). Overridden by WHOP_WEBHOOK_SECRET.', 'whop-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'sandbox' => [
                'title'       => __('Sandbox', 'whop-payments'),
                'type'        => 'checkbox',
                'label'       => __('Use Whop sandbox API (sandbox-api.whop.com)', 'whop-payments'),
                'default'     => 'yes',
                'description' => __('Overridden by WHOP_SANDBOX=1|0 env when set.', 'whop-payments'),
            ],
            'callback_url' => [
                'title'       => __('Webhook / callback URL', 'whop-payments'),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: %s: webhook URL */
                    __('Register this URL in the Whop dashboard → Developer → Webhooks, subscribe to <code>payment.succeeded</code> and <code>setup_intent.succeeded</code>, then paste the signing secret above (or set WHOP_WEBHOOK_SECRET).<br><code>%s</code>', 'whop-payments'),
                    esc_html($callback)
                ),
            ],
        ];
    }

    private function env(string $key): string {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = $_ENV[$key] ?? $_SERVER[$key] ?? '';
        }
        return is_string($v) ? trim($v) : '';
    }

    public function get_api_key(): string {
        $env = $this->env('WHOP_API_KEY');
        return $env !== '' ? $env : (string) $this->get_option('api_key', '');
    }

    public function get_company_id(): string {
        $env = $this->env('WHOP_COMPANY_ID');
        return $env !== '' ? $env : (string) $this->get_option('company_id', '');
    }

    public function get_webhook_secret(): string {
        $env = $this->env('WHOP_WEBHOOK_SECRET');
        return $env !== '' ? $env : (string) $this->get_option('webhook_secret', '');
    }

    public function is_sandbox_mode(): bool {
        $env = $this->env('WHOP_SANDBOX');
        if ($env !== '') {
            return in_array(strtolower($env), ['1', 'true', 'yes', 'on'], true);
        }
        return $this->get_option('sandbox', 'yes') === 'yes';
    }

    private function client(): Whop_Api_Client {
        return new Whop_Api_Client(
            $this->get_api_key(),
            $this->get_company_id(),
            $this->is_sandbox_mode()
        );
    }

    public function is_available(): bool {
        // When Railway/env credentials exist, treat gateway as enabled even if
        // the options row was never saved (fresh deploys / empty DB settings).
        if ($this->get_api_key() !== '' && $this->get_company_id() !== '') {
            $this->enabled = 'yes';
        }
        if (!parent::is_available()) {
            return false;
        }
        return $this->get_api_key() !== '' && $this->get_company_id() !== '';
    }

    public function admin_options(): void {
        parent::admin_options();
        echo '<p><strong>' . esc_html__('Environment', 'whop-payments') . ':</strong> ';
        echo $this->is_sandbox_mode()
            ? esc_html__('Sandbox (sandbox-api.whop.com)', 'whop-payments')
            : esc_html__('Production (api.whop.com)', 'whop-payments');
        echo '</p>';
        echo '<p><strong>' . esc_html__('Webhook URL', 'whop-payments') . ':</strong> <code>'
            . esc_html(Whop_Webhook::webhook_url()) . '</code></p>';
        $set = [];
        foreach (['WHOP_API_KEY', 'WHOP_COMPANY_ID', 'WHOP_WEBHOOK_SECRET', 'WHOP_SANDBOX'] as $k) {
            if ($this->env($k) !== '') {
                $set[] = $k;
            }
        }
        if ($set) {
            echo '<p>' . esc_html__('Env vars detected (values hidden): ', 'whop-payments')
                . esc_html(implode(', ', $set)) . '</p>';
        }
    }

    /**
     * @param int $order_id
     * @return array{result:string,redirect?:string}
     */
    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Invalid order.', 'whop-payments'), 'error');
            return ['result' => 'failure'];
        }

        $amount   = (float) $order->get_total();
        $currency = strtolower($order->get_currency() ?: get_woocommerce_currency());

        if ($amount <= 0) {
            $order->payment_complete();
            if (function_exists('sa_core_ensure_customer_order_email')) {
                sa_core_ensure_customer_order_email($order);
            }
            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        $client = $this->client();
        $return = Whop_Webhook::return_url($order);
        $line   = sprintf(
            /* translators: 1: store name 2: order number */
            __('%1$s order #%2$s', 'whop-payments'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            $order->get_order_number()
        );

        $result = $client->create_checkout_configuration([
            'amount'         => $amount,
            'currency'       => $currency,
            'order_id'       => $order->get_id(),
            'order_key'      => $order->get_order_key(),
            'description'    => $line,
            'redirect_url'   => $return,
            'customer_email' => $order->get_billing_email(),
            'customer_name'  => trim($order->get_formatted_billing_full_name()),
        ]);

        if (empty($result['success'])) {
            $msg = (string) ($result['message'] ?? __('Could not start Whop Checkout.', 'whop-payments'));
            wc_add_notice($msg, 'error');
            $order->add_order_note('Whop checkout error: ' . $msg);
            return ['result' => 'failure'];
        }

        $order->update_meta_data('_whop_checkout_id', (string) $result['checkout_id']);
        $order->update_meta_data('_whop_plan_id', (string) ($result['plan_id'] ?? ''));
        $order->update_meta_data('_whop_purchase_url', (string) $result['purchase_url']);
        $order->update_status('pending', __('Awaiting Whop payment.', 'whop-payments'));
        $order->save();

        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => (string) $result['purchase_url'],
        ];
    }

    public function thankyou_page($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }
        if ($order->is_paid()) {
            echo '<p>' . esc_html__('Thank you — your Whop payment was received.', 'whop-payments') . '</p>';
            return;
        }
        echo '<p>' . esc_html__('If you completed payment on Whop, confirmation can take a few seconds via webhook. Refresh this page if the status does not update.', 'whop-payments') . '</p>';
    }
}

<?php
/**
 * Whop REST API client (Checkout Configurations).
 *
 * Docs: https://docs.whop.com/api-reference/checkout-configurations/create-checkout-configuration
 * Guide: https://docs.whop.com/developer/guides/accept-payments
 *
 * Production: https://api.whop.com/api/v1
 * Sandbox:    https://sandbox-api.whop.com/api/v1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Whop_Api_Client {

    private const API_VERSION_DATE = '2026-08-21';

    private string $api_key;
    private string $company_id;
    private bool $sandbox;

    public function __construct(string $api_key, string $company_id, bool $sandbox) {
        $this->api_key    = $api_key;
        $this->company_id = $company_id;
        $this->sandbox    = $sandbox;
    }

    public function is_sandbox(): bool {
        return $this->sandbox;
    }

    public function get_base_url(): string {
        return $this->sandbox
            ? 'https://sandbox-api.whop.com/api/v1'
            : 'https://api.whop.com/api/v1';
    }

    public function get_checkout_host(): string {
        return $this->sandbox ? 'https://sandbox.whop.com' : 'https://whop.com';
    }

    /**
     * Create a checkout configuration with an inline one-time plan for the cart total.
     *
     * @param array<string,mixed> $args
     * @return array{success:bool,checkout_id?:string,plan_id?:string,purchase_url?:string,message?:string,raw?:mixed}
     */
    public function create_checkout_configuration(array $args): array {
        if ($this->api_key === '' || $this->company_id === '') {
            return [
                'success' => false,
                'message' => __('Whop API key or company ID is not configured.', 'whop-payments'),
            ];
        }

        $currency = strtolower((string) ($args['currency'] ?? 'usd'));
        $amount   = round((float) $args['amount'], 2);
        $order_id = (string) $args['order_id'];

        $body = [
            'mode'         => 'payment',
            'company_id'   => $this->company_id,
            'account_id'   => $this->company_id,
            'redirect_url' => $args['redirect_url'],
            'metadata'     => [
                'order_id'  => $order_id,
                'order_key' => (string) ($args['order_key'] ?? ''),
                'source'    => 'supreme-autoparts-woocommerce',
            ],
            'plan'         => [
                'company_id'            => $this->company_id,
                'currency'              => $currency,
                'initial_price'         => $amount,
                'plan_type'             => 'one_time',
                'title'                 => sprintf(
                    /* translators: %s: order number */
                    __('Order #%s — Supreme Autoparts', 'whop-payments'),
                    $order_id
                ),
                'description'           => (string) ($args['description'] ?? ''),
                'visibility'            => 'hidden',
                'force_create_new_plan' => true,
                'product'               => [
                    'external_identifier'   => 'woo-order-' . $order_id,
                    'title'                 => sprintf(
                        /* translators: %s: order number */
                        __('WooCommerce Order #%s', 'whop-payments'),
                        $order_id
                    ),
                    'redirect_purchase_url' => $args['redirect_url'],
                    'visibility'            => 'hidden',
                ],
            ],
        ];

        $response = $this->request('POST', '/checkout_configurations', $body);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($raw)) {
            $api_msg = '';
            if (is_array($raw) && isset($raw['error']['message'])) {
                $api_msg = (string) $raw['error']['message'];
            }
            return [
                'success' => false,
                'message' => $api_msg !== ''
                    ? $api_msg
                    : sprintf(
                        /* translators: %d: HTTP status */
                        __('Whop checkout create failed (HTTP %d).', 'whop-payments'),
                        $code
                    ),
                'raw'     => $raw,
            ];
        }

        $checkout_id  = (string) ($raw['id'] ?? '');
        $plan_id      = (string) ($raw['plan']['id'] ?? '');
        $purchase_url = (string) ($raw['purchase_url'] ?? '');

        if ($purchase_url !== '' && str_starts_with($purchase_url, '/')) {
            $purchase_url = $this->get_checkout_host() . $purchase_url;
        }

        if ($purchase_url === '' && $checkout_id !== '') {
            $purchase_url = $this->get_checkout_host() . '/checkout/' . rawurlencode($checkout_id) . '/';
        }

        if ($checkout_id === '' || $purchase_url === '') {
            return [
                'success' => false,
                'message' => __('Whop response missing checkout id or purchase_url.', 'whop-payments'),
                'raw'     => $raw,
            ];
        }

        return [
            'success'      => true,
            'checkout_id'  => $checkout_id,
            'plan_id'      => $plan_id,
            'purchase_url' => $purchase_url,
            'raw'          => $raw,
        ];
    }

    /**
     * Verify Standard Webhooks signature (Whop).
     * signed_content = "{webhook-id}.{webhook-timestamp}.{raw_body}"
     */
    public static function verify_webhook_signature(
        string $raw_body,
        string $webhook_id,
        string $webhook_timestamp,
        string $webhook_signature,
        string $webhook_secret
    ): bool {
        if ($webhook_secret === '' || $webhook_id === '' || $webhook_timestamp === '' || $webhook_signature === '') {
            return false;
        }

        $ts = (int) $webhook_timestamp;
        if ($ts <= 0 || abs(time() - $ts) > 300) {
            return false;
        }

        $signed_content = $webhook_id . '.' . $webhook_timestamp . '.' . $raw_body;
        $key            = self::derive_webhook_hmac_key($webhook_secret);
        $digest         = hash_hmac('sha256', $signed_content, $key, true);
        $expected       = base64_encode($digest);

        foreach (preg_split('/\s+/', trim($webhook_signature)) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (str_contains($part, ',')) {
                [$version, $sig] = array_pad(explode(',', $part, 2), 2, '');
                if (strtolower($version) !== 'v1' || $sig === '') {
                    continue;
                }
            } else {
                $sig = $part;
            }
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    private static function derive_webhook_hmac_key(string $secret): string {
        if (str_starts_with($secret, 'whsec_')) {
            $decoded = base64_decode(substr($secret, 6), true);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }
        return $secret;
    }

    /**
     * @param array<string,mixed> $body
     * @return array|\WP_Error
     */
    private function request(string $method, string $path, array $body = []) {
        $url  = untrailingslashit($this->get_base_url()) . $path;
        $args = [
            'method'  => $method,
            'timeout' => 45,
            'headers' => [
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json',
                'Authorization'    => 'Bearer ' . $this->api_key,
                'Api-Version-Date' => self::API_VERSION_DATE,
            ],
        ];

        if ($method !== 'GET' && $method !== 'HEAD') {
            $args['body'] = wp_json_encode($body);
        }

        return wp_remote_request($url, $args);
    }
}

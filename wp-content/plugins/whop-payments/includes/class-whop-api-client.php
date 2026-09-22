<?php
/**
 * Whop REST API client (Checkout Configurations + Payment Methods).
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

    public function get_company_id(): string {
        return $this->company_id;
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
        $source   = (string) ($args['source'] ?? 'supreme-autoparts-woocommerce');

        $plan_title = (string) ($args['title'] ?? '');
        if ($plan_title === '') {
            $plan_title = sprintf(
                /* translators: %s: order number */
                __('Order #%s — Supreme Autoparts', 'whop-payments'),
                $order_id
            );
        }

        $product_title = (string) ($args['product_title'] ?? '');
        if ($product_title === '') {
            $product_title = sprintf(
                /* translators: %s: order number */
                __('WooCommerce Order #%s', 'whop-payments'),
                $order_id
            );
        }

        $external_id = (string) ($args['product_external_id'] ?? '');
        if ($external_id === '') {
            $external_id = 'woo-order-' . $order_id;
        }

        $metadata = [
            'order_id'  => $order_id,
            'order_key' => (string) ($args['order_key'] ?? ''),
            'source'    => $source,
        ];
        if (!empty($args['note'])) {
            $metadata['note'] = (string) $args['note'];
        }
        if (is_array($args['metadata'] ?? null)) {
            $metadata = array_merge($metadata, $args['metadata']);
        }

        $body = [
            'mode'         => 'payment',
            'company_id'   => $this->company_id,
            'account_id'   => $this->company_id,
            'redirect_url' => $args['redirect_url'],
            'metadata'     => $metadata,
            'plan'         => [
                'company_id'            => $this->company_id,
                'currency'              => $currency,
                'initial_price'         => $amount,
                'plan_type'             => 'one_time',
                'title'                 => $plan_title,
                'description'           => (string) ($args['description'] ?? ''),
                'visibility'            => 'hidden',
                'force_create_new_plan' => true,
                'product'               => [
                    'external_identifier'   => $external_id,
                    'title'                 => $product_title,
                    'redirect_purchase_url' => $args['redirect_url'],
                    'visibility'            => 'hidden',
                ],
            ],
        ];

        $response = $this->request('POST', '/checkout_configurations', $body);

        return $this->parse_checkout_response($response);
    }

    /**
     * Create a setup-mode checkout to save a card without charging.
     *
     * @param array<string,mixed> $args
     * @return array{success:bool,checkout_id?:string,purchase_url?:string,message?:string,raw?:mixed}
     */
    public function create_setup_checkout(array $args): array {
        if ($this->api_key === '' || $this->company_id === '') {
            return [
                'success' => false,
                'message' => __('Whop API key or company ID is not configured.', 'whop-payments'),
            ];
        }

        $currency = strtolower((string) ($args['currency'] ?? 'usd'));
        $meta     = is_array($args['metadata'] ?? null) ? $args['metadata'] : [];
        $meta     = array_merge([
            'source'  => 'supreme-autoparts-myaccount',
            'purpose' => 'save_payment_method',
        ], $meta);

        $body = [
            'mode'         => 'setup',
            'company_id'   => $this->company_id,
            'account_id'   => $this->company_id,
            'currency'     => $currency,
            'redirect_url' => (string) ($args['redirect_url'] ?? ''),
            'metadata'     => $meta,
            'payment_method_configuration' => [
                'enabled'                   => ['card'],
                'include_platform_defaults' => false,
            ],
        ];

        $response = $this->request('POST', '/checkout_configurations', $body);

        return $this->parse_checkout_response($response);
    }

    /**
     * List payment methods for a member (or company when member omitted).
     *
     * @param array<string,mixed> $args
     * @return array{success:bool,data?:array<int,array<string,mixed>>,message?:string,raw?:mixed}
     */
    public function list_payment_methods(array $args = []): array {
        $query = [];
        if (!empty($args['member_id'])) {
            $query['member_id'] = (string) $args['member_id'];
        } elseif (!empty($args['account_id'])) {
            $query['account_id'] = (string) $args['account_id'];
        } else {
            $query['account_id'] = $this->company_id;
        }
        if (isset($args['first'])) {
            $query['first'] = (int) $args['first'];
        }
        if (!empty($args['after'])) {
            $query['after'] = (string) $args['after'];
        }

        $response = $this->request('GET', '/payment_methods', [], $query);
        return $this->parse_list_response($response, 'payment methods');
    }

    /**
     * @return array{success:bool,data?:array<string,mixed>,message?:string,raw?:mixed}
     */
    public function get_payment_method(string $id): array {
        if ($id === '') {
            return ['success' => false, 'message' => 'Missing payment method id.'];
        }
        $response = $this->request('GET', '/payment_methods/' . rawurlencode($id));
        return $this->parse_object_response($response, 'payment method');
    }

    /**
     * Delete a saved payment method on Whop.
     *
     * @return array{success:bool,message?:string,raw?:mixed}
     */
    public function delete_payment_method(string $id, array $args = []): array {
        if ($id === '') {
            return ['success' => false, 'message' => 'Missing payment method id.'];
        }
        $query = [];
        if (!empty($args['member_id'])) {
            $query['member_id'] = (string) $args['member_id'];
        } elseif (!empty($args['account_id'])) {
            $query['account_id'] = (string) $args['account_id'];
        }

        $response = $this->request('DELETE', '/payment_methods/' . rawurlencode($id), [], $query);
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'raw' => $raw];
        }
        $api_msg = is_array($raw) && isset($raw['error']['message']) ? (string) $raw['error']['message'] : '';
        return [
            'success' => false,
            'message' => $api_msg !== '' ? $api_msg : sprintf('Whop delete payment method failed (HTTP %d).', $code),
            'raw'     => $raw,
        ];
    }

    /**
     * Find company members (search by exact email when query is an email).
     *
     * @param array<string,mixed> $args
     * @return array{success:bool,data?:array<int,array<string,mixed>>,message?:string,raw?:mixed}
     */
    public function list_members(array $args = []): array {
        $query = [
            'account_id' => (string) ($args['account_id'] ?? $this->company_id),
            'first'      => (int) ($args['first'] ?? 20),
        ];
        if (!empty($args['query'])) {
            $query['query'] = (string) $args['query'];
        }
        if (!empty($args['status'])) {
            $query['status'] = (string) $args['status'];
        }

        $response = $this->request('GET', '/members', [], $query);
        return $this->parse_list_response($response, 'members');
    }

    /**
     * Resolve Whop member id for a customer email (cached by caller).
     */
    public function find_member_id_by_email(string $email): ?string {
        if (!is_email($email)) {
            return null;
        }
        $result = $this->list_members([
            'query'  => $email,
            'status' => 'joined',
            'first'  => 10,
        ]);
        if (empty($result['success']) || empty($result['data']) || !is_array($result['data'])) {
            return null;
        }
        foreach ($result['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $row_email = strtolower((string) (
                $row['email']
                ?? $row['user']['email']
                ?? $row['member_email']
                ?? ''
            ));
            if ($id !== '' && ($row_email === '' || $row_email === strtolower($email))) {
                return $id;
            }
        }
        $first = $result['data'][0] ?? null;
        if (is_array($first) && !empty($first['id'])) {
            return (string) $first['id'];
        }
        return null;
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
     * @param array|\WP_Error $response
     * @return array{success:bool,checkout_id?:string,plan_id?:string,purchase_url?:string,message?:string,raw?:mixed}
     */
    private function parse_checkout_response($response): array {
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
     * @param array|\WP_Error $response
     * @return array{success:bool,data?:array<int,array<string,mixed>>,message?:string,raw?:mixed}
     */
    private function parse_list_response($response, string $label): array {
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($raw)) {
            $api_msg = is_array($raw) && isset($raw['error']['message']) ? (string) $raw['error']['message'] : '';
            return [
                'success' => false,
                'message' => $api_msg !== '' ? $api_msg : sprintf('Whop %s list failed (HTTP %d).', $label, $code),
                'raw'     => $raw,
            ];
        }
        $data = [];
        if (isset($raw['data']) && is_array($raw['data'])) {
            $data = $raw['data'];
        } elseif (array_is_list($raw)) {
            $data = $raw;
        }
        return ['success' => true, 'data' => $data, 'raw' => $raw];
    }

    /**
     * @param array|\WP_Error $response
     * @return array{success:bool,data?:array<string,mixed>,message?:string,raw?:mixed}
     */
    private function parse_object_response($response, string $label): array {
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($raw)) {
            $api_msg = is_array($raw) && isset($raw['error']['message']) ? (string) $raw['error']['message'] : '';
            return [
                'success' => false,
                'message' => $api_msg !== '' ? $api_msg : sprintf('Whop %s get failed (HTTP %d).', $label, $code),
                'raw'     => $raw,
            ];
        }
        return ['success' => true, 'data' => $raw, 'raw' => $raw];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,scalar> $query
     * @return array|\WP_Error
     */
    private function request(string $method, string $path, array $body = [], array $query = []) {
        $url = untrailingslashit($this->get_base_url()) . $path;
        if ($query !== []) {
            $url = add_query_arg($query, $url);
        }
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

        if (!in_array($method, ['GET', 'HEAD', 'DELETE'], true) || $body !== []) {
            if ($method !== 'GET' && $method !== 'HEAD') {
                $args['body'] = wp_json_encode($body);
            }
        }

        return wp_remote_request($url, $args);
    }
}

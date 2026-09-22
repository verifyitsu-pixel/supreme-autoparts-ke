<?php
/**
 * Saved payment methods: Whop ↔ WooCommerce tokens + user meta sync.
 *
 * Meta keys:
 * - _sa_whop_payment_method_id (on WC_Payment_Token)
 * - _sa_whop_member_id (user meta)
 * - _sa_whop_payment_methods_synced_at (user meta)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Whop_Payment_Methods {

    public const TOKEN_META_WHOP_ID = '_sa_whop_payment_method_id';
    public const USER_META_MEMBER   = '_sa_whop_member_id';
    public const USER_META_SYNCED   = '_sa_whop_payment_methods_synced_at';

    public static function init(): void {
        add_action('woocommerce_api_whop_setup_return', [self::class, 'handle_setup_return']);
        add_action('template_redirect', [self::class, 'maybe_handle_account_actions'], 5);
        add_action('wp_ajax_sa_whop_sync_payment_methods', [self::class, 'ajax_sync']);
    }

    public static function client_from_gateway(): ?Whop_Api_Client {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return null;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gw       = $gateways['whop'] ?? null;
        if (!$gw instanceof WC_Gateway_Whop || !$gw->is_available()) {
            // Still allow API if credentials exist even when gateway disabled at checkout.
            if (!$gw instanceof WC_Gateway_Whop) {
                return null;
            }
        }
        if (!$gw instanceof WC_Gateway_Whop) {
            return null;
        }
        $key = $gw->get_api_key();
        $co  = $gw->get_company_id();
        if ($key === '' || $co === '') {
            return null;
        }
        return new Whop_Api_Client($key, $co, $gw->is_sandbox_mode());
    }

    public static function setup_return_url(int $user_id): string {
        return add_query_arg(
            [
                'wc-api'  => 'whop_setup_return',
                'user_id' => $user_id,
                'nonce'   => wp_create_nonce('whop_setup_' . $user_id),
            ],
            home_url('/')
        );
    }

    public static function handle_setup_return(): void {
        $user_id = absint($_GET['user_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce   = sanitize_text_field(wp_unslash((string) ($_GET['nonce'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status  = sanitize_text_field(wp_unslash((string) ($_GET['status'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $redirect = wc_get_account_endpoint_url('payment-methods');

        if (!$user_id || !wp_verify_nonce($nonce, 'whop_setup_' . $user_id)) {
            wc_add_notice(__('Could not verify payment method return.', 'whop-payments'), 'error');
            wp_safe_redirect($redirect);
            exit;
        }

        if (!is_user_logged_in() || get_current_user_id() !== $user_id) {
            // Allow return while session cookie may lag; require login for sync notice.
            if (!is_user_logged_in()) {
                wp_safe_redirect(wc_get_page_permalink('myaccount'));
                exit;
            }
        }

        if (in_array($status, ['error', 'cancel', 'failed'], true)) {
            wc_add_notice(
                __('Verification payment was cancelled or failed. No $1.00 charge was completed. You can try again.', 'whop-payments'),
                'error'
            );
            wp_safe_redirect($redirect);
            exit;
        }

        $sync = self::sync_user_payment_methods($user_id, true);
        if (!empty($sync['success'])) {
            $count = (int) ($sync['count'] ?? 0);
            if ($count > 0) {
                wc_add_notice(
                    __('Verification payment received ($1.00). Your payment method is saved.', 'whop-payments'),
                    'success'
                );
            } else {
                wc_add_notice(
                    __('Verification payment received ($1.00). If your method is not listed yet, tap Refresh — sync may take a moment.', 'whop-payments'),
                    'success'
                );
            }
        } else {
            wc_add_notice(
                (string) ($sync['message'] ?? __('Returned from verification; sync will retry when you refresh.', 'whop-payments')),
                'notice'
            );
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Account endpoint actions: add / delete / refresh.
     */
    public static function maybe_handle_account_actions(): void {
        if (!is_account_page() || !is_user_logged_in()) {
            return;
        }

        $action = sanitize_key((string) ($_GET['sa_whop_action'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($action === '') {
            return;
        }

        $user_id = get_current_user_id();
        $nonce   = sanitize_text_field(wp_unslash((string) ($_GET['_wpnonce'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($action === 'add') {
            if (!wp_verify_nonce($nonce, 'sa_whop_add_pm')) {
                wc_add_notice(__('Security check failed.', 'whop-payments'), 'error');
                return;
            }
            self::start_add_payment_method($user_id);
            return;
        }

        if ($action === 'refresh') {
            if (!wp_verify_nonce($nonce, 'sa_whop_refresh_pm')) {
                wc_add_notice(__('Security check failed.', 'whop-payments'), 'error');
                return;
            }
            $sync = self::sync_user_payment_methods($user_id, true);
            if (!empty($sync['success'])) {
                wc_add_notice(__('Synced payment methods from Whop.', 'whop-payments'), 'success');
            } else {
                wc_add_notice((string) ($sync['message'] ?? __('Sync failed.', 'whop-payments')), 'error');
            }
            wp_safe_redirect(wc_get_account_endpoint_url('payment-methods'));
            exit;
        }

        if ($action === 'delete') {
            if (!wp_verify_nonce($nonce, 'sa_whop_delete_pm')) {
                wc_add_notice(__('Security check failed.', 'whop-payments'), 'error');
                return;
            }
            $token_id = absint($_GET['token_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $whop_id  = sanitize_text_field(wp_unslash((string) ($_GET['whop_id'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $result   = self::delete_user_payment_method($user_id, $token_id, $whop_id);
            if (!empty($result['success'])) {
                wc_add_notice(__('Payment method removed.', 'whop-payments'), 'success');
            } else {
                wc_add_notice((string) ($result['message'] ?? __('Could not remove payment method.', 'whop-payments')), 'error');
            }
            wp_safe_redirect(wc_get_account_endpoint_url('payment-methods'));
            exit;
        }
    }

    /** Non-refundable verification fee charged when adding a card/bank (USD). */
    public const VERIFY_FEE_USD = 1.00;

    public static function start_add_payment_method(int $user_id): void {
        $client = self::client_from_gateway();
        if (!$client) {
            wc_add_notice(__('Whop payments are not configured.', 'whop-payments'), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('payment-methods'));
            exit;
        }

        $user  = get_userdata($user_id);
        $email = $user ? (string) $user->user_email : '';

        // Live Add button: $1.00 USD payment-mode verification (saves method after charge).
        $result = $client->create_verify_checkout([
            'amount'       => self::VERIFY_FEE_USD,
            'wp_user_id'   => (string) $user_id,
            'email'        => $email,
            'redirect_url' => self::setup_return_url($user_id),
            'title'        => __('Card/bank verify $1', 'whop-payments'),
        ]);

        // Optional fallback: free setup-only if payment-mode verify cannot be created.
        if (empty($result['success']) && method_exists($client, 'create_setup_checkout')) {
            error_log('[whop-payments] verify checkout failed, falling back to setup: ' . (string) ($result['message'] ?? ''));
            $result = $client->create_setup_checkout([
                'currency'     => 'usd',
                'redirect_url' => self::setup_return_url($user_id),
                'metadata'     => [
                    'wp_user_id' => (string) $user_id,
                    'email'      => $email,
                    'fallback'   => 'setup_after_verify_fail',
                ],
            ]);
        }

        if (empty($result['success'])) {
            $err = (string) ($result['message'] ?? __('Could not start card/bank verification.', 'whop-payments'));
            if (!empty($result['raw']) && is_array($result['raw'])) {
                error_log('[whop-payments] start_add_payment_method: ' . wp_json_encode($result['raw']));
            }
            wc_add_notice($err, 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('payment-methods'));
            exit;
        }

        update_user_meta($user_id, '_sa_whop_pending_setup_checkout', (string) $result['checkout_id']);
        update_user_meta($user_id, '_sa_whop_pending_verify_fee', (string) self::VERIFY_FEE_USD);
        wp_safe_redirect((string) $result['purchase_url']);
        exit;
    }

    /**
     * @return array{success:bool,count?:int,message?:string}
     */
    public static function sync_user_payment_methods(int $user_id, bool $force = false): array {
        $client = self::client_from_gateway();
        if (!$client) {
            return ['success' => false, 'message' => __('Whop API unavailable.', 'whop-payments')];
        }

        $user = get_userdata($user_id);
        if (!$user || !is_email($user->user_email)) {
            return ['success' => false, 'message' => __('User email missing.', 'whop-payments')];
        }

        $member_id = (string) get_user_meta($user_id, self::USER_META_MEMBER, true);
        if ($member_id === '' || $force) {
            $found = $client->find_member_id_by_email($user->user_email);
            if ($found) {
                $member_id = $found;
                update_user_meta($user_id, self::USER_META_MEMBER, $member_id);
            }
        }

        if ($member_id === '') {
            // No Whop member yet — clear stale local Whop tokens but keep other gateways.
            self::prune_whop_tokens_not_in($user_id, []);
            update_user_meta($user_id, self::USER_META_SYNCED, time());
            return [
                'success' => true,
                'count'   => 0,
                'message' => __('No Whop member found for this email yet. Add a card to create one.', 'whop-payments'),
            ];
        }

        $list = $client->list_payment_methods([
            'member_id' => $member_id,
            'first'     => 50,
        ]);

        if (empty($list['success'])) {
            return [
                'success' => false,
                'message' => (string) ($list['message'] ?? __('Could not list Whop payment methods.', 'whop-payments')),
            ];
        }

        $remote_ids = [];
        foreach ($list['data'] ?? [] as $pm) {
            if (!is_array($pm)) {
                continue;
            }
            $id = (string) ($pm['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $remote_ids[] = $id;
            self::upsert_token_from_whop($user_id, $pm);
        }

        self::prune_whop_tokens_not_in($user_id, $remote_ids);
        update_user_meta($user_id, self::USER_META_SYNCED, time());

        return ['success' => true, 'count' => count($remote_ids)];
    }

    /**
     * @param array<string,mixed> $pm
     */
    /**
     * Detect bank / ACH payment method payloads from Whop.
     *
     * @param array<string,mixed> $pm
     */
    private static function is_bank_payment_method(array $pm): bool {
        $type = strtolower((string) ($pm['type'] ?? $pm['payment_method_type'] ?? ''));
        if (in_array($type, ['us_bank_account', 'bank', 'bank_account', 'ach', 'ach_debit'], true)) {
            return true;
        }
        if (is_array($pm['us_bank_account'] ?? null) || is_array($pm['bank'] ?? null) || is_array($pm['bank_account'] ?? null)) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $pm
     * @return array{brand:string,last4:string,exp_m:string,exp_y:string,is_bank:bool}
     */
    private static function extract_pm_display_fields(array $pm): array {
        $is_bank = self::is_bank_payment_method($pm);
        $card    = is_array($pm['card'] ?? null) ? $pm['card'] : [];
        $bank    = [];
        if (is_array($pm['us_bank_account'] ?? null)) {
            $bank = $pm['us_bank_account'];
        } elseif (is_array($pm['bank'] ?? null)) {
            $bank = $pm['bank'];
        } elseif (is_array($pm['bank_account'] ?? null)) {
            $bank = $pm['bank_account'];
        }

        if ($is_bank) {
            $brand = strtolower((string) ($bank['bank_name'] ?? $bank['brand'] ?? $pm['brand'] ?? 'bank'));
            if ($brand === '' || $brand === 'us_bank_account') {
                $brand = 'bank';
            }
            $last4 = (string) ($bank['last4'] ?? $bank['last_four'] ?? $pm['last4'] ?? '****');
            return [
                'brand'   => $brand,
                'last4'   => $last4,
                'exp_m'   => '',
                'exp_y'   => '',
                'is_bank' => true,
            ];
        }

        $brand = strtolower((string) ($card['brand'] ?? $pm['card_brand'] ?? $pm['brand'] ?? 'card'));
        $last4 = (string) ($card['last4'] ?? $pm['last4'] ?? $card['last_four'] ?? '****');
        $exp_m = (string) ($card['exp_month'] ?? $card['expiry_month'] ?? $pm['exp_month'] ?? '');
        $exp_y = (string) ($card['exp_year'] ?? $card['expiry_year'] ?? $pm['exp_year'] ?? '');
        return [
            'brand'   => $brand !== '' ? $brand : 'card',
            'last4'   => $last4,
            'exp_m'   => $exp_m,
            'exp_y'   => $exp_y,
            'is_bank' => false,
        ];
    }

    public static function upsert_token_from_whop(int $user_id, array $pm): void {
        $whop_id = (string) ($pm['id'] ?? '');
        if ($whop_id === '') {
            return;
        }

        $existing = self::find_token_by_whop_id($user_id, $whop_id);
        $fields   = self::extract_pm_display_fields($pm);
        $last4    = preg_replace('/\D/', '', $fields['last4']) ?: '0000';
        $is_bank  = $fields['is_bank'];

        $use_echeck = $is_bank && class_exists('WC_Payment_Token_ECheck');

        if ($use_echeck) {
            if ($existing instanceof WC_Payment_Token_ECheck) {
                $token = $existing;
            } else {
                if ($existing) {
                    WC_Payment_Tokens::delete($existing->get_id());
                }
                $token = new WC_Payment_Token_ECheck();
                $token->set_user_id($user_id);
                $token->set_gateway_id('whop');
            }
            $token->set_last4($last4);
            $token->update_meta_data('_sa_whop_pm_kind', 'bank');
            $token->update_meta_data('_sa_whop_pm_brand', $fields['brand']);
        } else {
            if ($existing instanceof WC_Payment_Token_CC) {
                $token = $existing;
            } else {
                if ($existing) {
                    WC_Payment_Tokens::delete($existing->get_id());
                }
                $token = new WC_Payment_Token_CC();
                $token->set_user_id($user_id);
                $token->set_gateway_id('whop');
            }
            $brand = $is_bank ? 'bank' : $fields['brand'];
            $token->set_card_type($brand !== '' ? $brand : 'card');
            $token->set_last4($last4);
            if (!$is_bank && $fields['exp_m'] !== '') {
                $token->set_expiry_month(str_pad(preg_replace('/\D/', '', $fields['exp_m']) ?: '01', 2, '0', STR_PAD_LEFT));
            } else {
                $token->set_expiry_month('01');
            }
            if (!$is_bank && $fields['exp_y'] !== '') {
                $y = preg_replace('/\D/', '', $fields['exp_y']) ?: '';
                if (strlen($y) === 2) {
                    $y = '20' . $y;
                }
                $token->set_expiry_year($y !== '' ? $y : (string) ((int) gmdate('Y') + 3));
            } else {
                $token->set_expiry_year((string) ((int) gmdate('Y') + 3));
            }
            $token->update_meta_data('_sa_whop_pm_kind', $is_bank ? 'bank' : 'card');
            $token->update_meta_data('_sa_whop_pm_brand', $fields['brand']);
        }

        $token->set_token($whop_id);
        $token->update_meta_data(self::TOKEN_META_WHOP_ID, $whop_id);
        $token->save();
    }

    public static function find_token_by_whop_id(int $user_id, string $whop_id): ?WC_Payment_Token {
        $tokens = WC_Payment_Tokens::get_customer_tokens($user_id, 'whop');
        foreach ($tokens as $token) {
            if (!$token instanceof WC_Payment_Token) {
                continue;
            }
            $meta = (string) $token->get_meta(self::TOKEN_META_WHOP_ID);
            if ($meta === $whop_id || $token->get_token() === $whop_id) {
                return $token;
            }
        }
        return null;
    }

    /**
     * @param list<string> $keep_ids
     */
    public static function prune_whop_tokens_not_in(int $user_id, array $keep_ids): void {
        $tokens = WC_Payment_Tokens::get_customer_tokens($user_id, 'whop');
        foreach ($tokens as $token) {
            $whop_id = (string) $token->get_meta(self::TOKEN_META_WHOP_ID);
            if ($whop_id === '') {
                $whop_id = (string) $token->get_token();
            }
            if ($whop_id !== '' && !in_array($whop_id, $keep_ids, true)) {
                WC_Payment_Tokens::delete($token->get_id());
            }
        }
    }

    /**
     * @return array{success:bool,message?:string}
     */
    public static function delete_user_payment_method(int $user_id, int $token_id, string $whop_id = ''): array {
        $token = $token_id ? WC_Payment_Tokens::get($token_id) : null;
        if ($token && (int) $token->get_user_id() !== $user_id) {
            return ['success' => false, 'message' => __('Invalid payment method.', 'whop-payments')];
        }
        if ($token && $whop_id === '') {
            $whop_id = (string) $token->get_meta(self::TOKEN_META_WHOP_ID);
            if ($whop_id === '') {
                $whop_id = (string) $token->get_token();
            }
        }

        $client = self::client_from_gateway();
        if ($client && $whop_id !== '') {
            $member_id = (string) get_user_meta($user_id, self::USER_META_MEMBER, true);
            $del = $client->delete_payment_method($whop_id, array_filter([
                'member_id' => $member_id,
            ]));
            if (empty($del['success'])) {
                // Still remove local copy if remote already gone.
                $msg = strtolower((string) ($del['message'] ?? ''));
                if (!str_contains($msg, 'not found') && !str_contains($msg, '404')) {
                    return [
                        'success' => false,
                        'message' => (string) ($del['message'] ?? __('Whop refused to delete this method.', 'whop-payments')),
                    ];
                }
            }
        }

        if ($token) {
            WC_Payment_Tokens::delete($token->get_id());
        } elseif ($whop_id !== '') {
            $found = self::find_token_by_whop_id($user_id, $whop_id);
            if ($found) {
                WC_Payment_Tokens::delete($found->get_id());
            }
        }

        return ['success' => true];
    }

    /**
     * Normalized list for templates (Woo tokens + Whop meta).
     *
     * @return list<array<string,mixed>>
     */
    public static function get_methods_for_display(int $user_id): array {
        $out = [];
        $tokens = WC_Payment_Tokens::get_customer_tokens($user_id);
        foreach ($tokens as $token) {
            $whop_id = '';
            if ($token->get_gateway_id() === 'whop') {
                $whop_id = (string) $token->get_meta(self::TOKEN_META_WHOP_ID);
                if ($whop_id === '') {
                    $whop_id = (string) $token->get_token();
                }
            }
            $kind  = (string) $token->get_meta('_sa_whop_pm_kind');
            $brand_meta = (string) $token->get_meta('_sa_whop_pm_brand');
            $row = [
                'token_id'   => $token->get_id(),
                'gateway'    => $token->get_gateway_id(),
                'is_default' => $token->is_default(),
                'whop_id'    => $whop_id,
                'label'      => $token->get_display_name(),
                'kind'       => $kind !== '' ? $kind : 'card',
            ];
            if ($token instanceof WC_Payment_Token_ECheck || $kind === 'bank') {
                $last4 = method_exists($token, 'get_last4') ? (string) $token->get_last4() : '';
                $brand = $brand_meta !== '' ? $brand_meta : 'bank';
                $row['brand'] = $brand;
                $row['last4'] = $last4;
                $row['kind']  = 'bank';
                $row['label'] = sprintf(
                    /* translators: %s: last 4 digits */
                    __('Bank •••• %s', 'whop-payments'),
                    $last4 !== '' ? $last4 : '****'
                );
            } elseif ($token instanceof WC_Payment_Token_CC) {
                $card_type = (string) $token->get_card_type();
                $last4     = (string) $token->get_last4();
                if (strtolower($card_type) === 'bank' || $kind === 'bank') {
                    $row['brand'] = $brand_meta !== '' ? $brand_meta : 'bank';
                    $row['last4'] = $last4;
                    $row['kind']  = 'bank';
                    $row['label'] = sprintf(
                        /* translators: %s: last 4 digits */
                        __('Bank •••• %s', 'whop-payments'),
                        $last4 !== '' ? $last4 : '****'
                    );
                } else {
                    $row['brand'] = $card_type;
                    $row['last4'] = $last4;
                    $row['exp']   = $token->get_expiry_month() . '/' . $token->get_expiry_year();
                }
            }
            $out[] = $row;
        }
        return $out;
    }


    /**
     * Webhook: setup_intent.succeeded — sync buyer payment methods into WP.
     *
     * @param array<string,mixed> $event
     */
    public static function handle_setup_intent_succeeded(array $event): void {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        if (!is_array($meta) || $meta === []) {
            $meta = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        }

        $user_id = absint($meta['wp_user_id'] ?? 0);
        $email   = sanitize_email((string) ($meta['email'] ?? $data['email'] ?? ''));

        if (!$user_id && $email !== '' && is_email($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                $user_id = (int) $user->ID;
            }
        }

        $pm = is_array($data['payment_method'] ?? null) ? $data['payment_method'] : null;
        $pm_id = (string) ($data['payment_method_id'] ?? ($pm['id'] ?? ''));

        if ($user_id && $pm_id !== '' && is_array($pm)) {
            self::upsert_token_from_whop($user_id, $pm);
        }

        if ($user_id) {
            self::sync_user_payment_methods($user_id, true);
        }
    }

    public static function ajax_sync(): void {
        if (!is_user_logged_in() || !check_ajax_referer('sa_whop_sync', 'nonce', false)) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        $result = self::sync_user_payment_methods(get_current_user_id(), true);
        if (!empty($result['success'])) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result);
    }

    public static function add_url(): string {
        return wp_nonce_url(
            add_query_arg('sa_whop_action', 'add', wc_get_account_endpoint_url('payment-methods')),
            'sa_whop_add_pm'
        );
    }

    public static function refresh_url(): string {
        return wp_nonce_url(
            add_query_arg('sa_whop_action', 'refresh', wc_get_account_endpoint_url('payment-methods')),
            'sa_whop_refresh_pm'
        );
    }

    public static function delete_url(int $token_id, string $whop_id = ''): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'sa_whop_action' => 'delete',
                    'token_id'       => $token_id,
                    'whop_id'        => $whop_id,
                ],
                wc_get_account_endpoint_url('payment-methods')
            ),
            'sa_whop_delete_pm'
        );
    }
}

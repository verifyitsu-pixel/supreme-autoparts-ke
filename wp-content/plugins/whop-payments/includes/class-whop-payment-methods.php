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
        add_action('template_redirect', [self::class, 'maybe_handle_embed_done'], 6);
        add_action('wp_ajax_sa_whop_sync_payment_methods', [self::class, 'ajax_sync']);
        add_action('wp_ajax_sa_whop_start_embed_verify', [self::class, 'ajax_start_embed_verify']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_embed_assets']);
        add_filter('wp_headers', [self::class, 'filter_csp_headers'], 20);
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

    /**
     * Create a $1.00 verify checkout session for embedded My Account add-card.
     *
     * @return array{success:bool,plan_id?:string,session_id?:string,checkout_id?:string,return_url?:string,email?:string,fee?:float,message?:string,raw?:mixed}
     */
    public static function create_embed_verify_session(int $user_id, string $method = 'card'): array {
        $method = strtolower($method);
        if (!in_array($method, ['card', 'bank'], true)) {
            $method = 'card';
        }

        $client = self::client_from_gateway();
        if (!$client) {
            return [
                'success' => false,
                'message' => __('Whop payments are not configured.', 'whop-payments'),
            ];
        }

        $user  = get_userdata($user_id);
        $email = $user ? (string) $user->user_email : '';
        $return_url = self::embed_return_url($user_id);

        $title = $method === 'bank'
            ? __('Add bank', 'whop-payments')
            : __('Add card', 'whop-payments');

        // Bank: prefer setup-mode us_bank_account (routing/account fields, no Plaid Link
        // required on our side). Multiple banks allowed — each Add bank creates a new session.
        // Card: $1 payment verify first, setup fallback.
        if ($method === 'bank' && method_exists($client, 'create_setup_checkout')) {
            $result = $client->create_setup_checkout([
                'currency'     => 'usd',
                'redirect_url' => $return_url,
                'method'       => 'bank',
                'metadata'     => [
                    'wp_user_id' => (string) $user_id,
                    'email'      => $email,
                    'purpose'    => 'save_bank_account',
                    'pm_method'  => 'bank',
                    'no_plaid'   => '1',
                ],
            ]);
            if (empty($result['success'])) {
                error_log('[whop-payments] bank setup failed, trying $1 verify: ' . (string) ($result['message'] ?? ''));
                $result = $client->create_verify_checkout([
                    'amount'       => self::VERIFY_FEE_USD,
                    'wp_user_id'   => (string) $user_id,
                    'email'        => $email,
                    'redirect_url' => $return_url,
                    'method'       => 'bank',
                    'title'        => $title,
                ]);
            }
        } else {
            $result = $client->create_verify_checkout([
                'amount'       => self::VERIFY_FEE_USD,
                'wp_user_id'   => (string) $user_id,
                'email'        => $email,
                'redirect_url' => $return_url,
                'method'       => $method,
                'title'        => $title,
            ]);

            // Optional fallback: free setup-only if payment-mode verify cannot be created.
            if (empty($result['success']) && method_exists($client, 'create_setup_checkout')) {
                error_log('[whop-payments] verify checkout failed, falling back to setup: ' . (string) ($result['message'] ?? ''));
                $result = $client->create_setup_checkout([
                    'currency'     => 'usd',
                    'redirect_url' => $return_url,
                    'method'       => $method,
                    'metadata'     => [
                        'wp_user_id' => (string) $user_id,
                        'email'      => $email,
                        'fallback'   => 'setup_after_verify_fail',
                        'pm_method'  => $method,
                    ],
                ]);
            }
        }

        if (empty($result['success'])) {
            if (!empty($result['raw']) && is_array($result['raw'])) {
                error_log('[whop-payments] create_embed_verify_session: ' . wp_json_encode($result['raw']));
            }
            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? __('Could not start card/bank verification.', 'whop-payments')),
                'raw'     => $result['raw'] ?? null,
            ];
        }

        $plan_id     = (string) ($result['plan_id'] ?? '');
        $checkout_id = (string) ($result['checkout_id'] ?? '');
        if ($plan_id === '' || $checkout_id === '') {
            return [
                'success' => false,
                'message' => __('Whop did not return plan_id and checkout session for embed.', 'whop-payments'),
                'raw'     => $result['raw'] ?? null,
            ];
        }

        update_user_meta($user_id, '_sa_whop_embed_plan_id', $plan_id);
        update_user_meta($user_id, '_sa_whop_embed_session_id', $checkout_id);
        update_user_meta($user_id, '_sa_whop_pending_setup_checkout', $checkout_id);
        update_user_meta($user_id, '_sa_whop_pending_verify_fee', (string) self::VERIFY_FEE_USD);
        update_user_meta($user_id, '_sa_whop_embed_method', $method);

        return [
            'success'     => true,
            'plan_id'     => $plan_id,
            'session_id'  => $checkout_id,
            'checkout_id' => $checkout_id,
            'return_url'  => $return_url,
            'email'       => $email,
            'fee'         => self::VERIFY_FEE_USD,
            'method'      => $method,
        ];
    }

    /**
     * Return URL after 3DS / external auth — always lands back on My Account.
     */
    public static function embed_return_url(int $user_id): string {
        // Prefer wc-api setup return (sync + notice), which then redirects to payment-methods.
        return self::setup_return_url($user_id);
    }

    public static function start_add_payment_method(int $user_id): void {
        $result = self::create_embed_verify_session($user_id);
        if (empty($result['success'])) {
            wc_add_notice(
                (string) ($result['message'] ?? __('Could not start card/bank verification.', 'whop-payments')),
                'error'
            );
            wp_safe_redirect(wc_get_account_endpoint_url('payment-methods'));
            exit;
        }

        // Stay on supremeautoparts.co.ke — open embedded checkout panel (no redirect to whop.com).
        wp_safe_redirect(
            add_query_arg('sa_whop_embed', '1', wc_get_account_endpoint_url('payment-methods'))
        );
        exit;
    }

    /**
     * Soft-sync when returning with ?sa_whop_embed_done=1 (3DS / skip-redirect fallback).
     */
    public static function maybe_handle_embed_done(): void {
        if (!is_account_page() || !is_user_logged_in()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['sa_whop_embed_done'])) {
            return;
        }
        $user_id = get_current_user_id();
        $sync    = self::sync_user_payment_methods($user_id, true);
        if (!empty($sync['success']) && (int) ($sync['count'] ?? 0) > 0) {
            wc_add_notice(
                __('Verification payment received ($1.00). Your payment method is saved.', 'whop-payments'),
                'success'
            );
        } elseif (!empty($sync['success'])) {
            wc_add_notice(
                __('Verification complete. If your method is not listed yet, tap Refresh — sync may take a moment.', 'whop-payments'),
                'success'
            );
        }
        delete_user_meta($user_id, '_sa_whop_embed_plan_id');
        delete_user_meta($user_id, '_sa_whop_embed_session_id');
        wp_safe_redirect(wc_get_account_endpoint_url('payment-methods'));
        exit;
    }

    public static function ajax_start_embed_verify(): void {
        if (!is_user_logged_in() || !check_ajax_referer('sa_whop_embed', 'nonce', false)) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        $method = isset($_POST['method']) ? sanitize_key((string) wp_unslash($_POST['method'])) : 'card'; // phpcs:ignore
        if (!in_array($method, ['card', 'bank'], true)) {
            $method = 'card';
        }
        $result = self::create_embed_verify_session(get_current_user_id(), $method);
        if (!empty($result['success'])) {
            wp_send_json_success([
                'plan_id'    => $result['plan_id'],
                'session_id' => $result['session_id'],
                'return_url' => $result['return_url'],
                'email'      => $result['email'],
                'fee'        => $result['fee'],
                'method'     => $method,
            ]);
        }
        wp_send_json_error([
            'message' => (string) ($result['message'] ?? 'Could not start verification.'),
        ]);
    }

    public static function enqueue_embed_assets(): void {
        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }
        // Payment methods endpoint, or any My Account view that exposes Add card.
        $on_pm = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('payment-methods');
        $on_account = is_account_page() && is_user_logged_in();
        if (!$on_account || (!$on_pm && !is_wc_endpoint_url(''))) {
            // Still allow dashboard/payment-methods; skip checkout-only noise.
            if (!$on_pm) {
                return;
            }
        }
        if (!is_user_logged_in()) {
            return;
        }

        // Preload Whop CDN scripts in <head> so Add card is not cold-start.
        add_action('wp_head', [self::class, 'print_whop_preloads'], 2);

        // CRITICAL: do NOT append ?ver= to Whop loader.js.
        // loader.js does: src.replace(/loader\.js$/, "index.js") — a query string
        // breaks that, index.js never loads, and the embed stays an empty black box.
        wp_enqueue_script(
            'whop-checkout-loader',
            'https://js.whop.com/static/checkout/loader.js',
            [],
            null,
            false // head — start loading before footer paint
        );
        // Also enqueue index.js directly (loader normally injects it; we warm it ourselves).
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
        // Belt-and-suspenders: strip any ver/ query WP or other filters may add.
        add_filter('script_loader_src', [self::class, 'strip_whop_loader_ver'], 100, 2);
        add_filter('script_loader_tag', [self::class, 'tag_whop_loader_async_defer'], 100, 3);

        wp_enqueue_script(
            'sa-whop-pm-embed',
            WHOP_PAYMENTS_URL . 'assets/js/sa-whop-pm-embed.js',
            ['whop-checkout-loader', 'whop-checkout-index'],
            WHOP_PAYMENTS_VERSION,
            true
        );

        $user = wp_get_current_user();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $auto_open = !empty($_GET['sa_whop_embed']);

        // Server-side warm: create embed session during page render so Add card
        // can mount immediately without waiting on a post-click AJAX round-trip.
        $warm = null;
        if ($on_pm && (int) $user->ID > 0) {
            $warm_result = self::create_embed_verify_session((int) $user->ID);
            if (!empty($warm_result['success'])) {
                $warm = [
                    'plan_id'    => (string) ($warm_result['plan_id'] ?? ''),
                    'session_id' => (string) ($warm_result['session_id'] ?? ''),
                    'return_url' => (string) ($warm_result['return_url'] ?? ''),
                    'email'      => (string) ($warm_result['email'] ?? $user->user_email),
                    'fee'        => (float) ($warm_result['fee'] ?? self::VERIFY_FEE_USD),
                    'method'     => 'card',
                ];
            }
        }

        wp_localize_script('sa-whop-pm-embed', 'saWhopPmEmbed', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('sa_whop_embed'),
            'syncNonce'  => wp_create_nonce('sa_whop_sync'),
            'returnUrl'  => self::embed_return_url((int) $user->ID),
            'email'      => (string) $user->user_email,
            'fee'        => self::VERIFY_FEE_USD,
            // Only auto-open when ?sa_whop_embed=1 — never from leftover meta (expired → empty box).
            'autoOpen'   => $auto_open,
            'fallbackAdd'=> self::add_url(),
            'warm'       => $warm,
            'i18n'       => [
                'starting'    => __('Preparing…', 'whop-payments'),
                'loadingForm' => __('Loading secure form…', 'whop-payments'),
                'error'       => __('Could not load the form. Please try again.', 'whop-payments'),
                'success'     => __('Saved. Refreshing…', 'whop-payments'),
                'syncing'     => __('Saving…', 'whop-payments'),
                'titleCard'   => __('Add card', 'whop-payments'),
                'titleBank'   => __('Add bank', 'whop-payments'),
                'verifyCard'  => __('Verify card', 'whop-payments'),
                'verifyBank'  => __('Verify bank', 'whop-payments'),
                'feeCard'     => __('You will be charged $1.00 USD once to verify this card is active. The charge is non-refundable.', 'whop-payments'),
                'feeBank'     => __('Enter your US bank routing and account numbers below (no Plaid Link). Multiple banks allowed. Whop may charge $1.00 USD once to verify.', 'whop-payments'),
                'notReady'    => __('Form not ready yet. Wait a moment and try again.', 'whop-payments'),
                'checkForm'   => __('Complete the highlighted fields, then tap Verify.', 'whop-payments'),
                'tryAgain'    => __('Form reloaded. Fill details and tap Verify.', 'whop-payments'),
                'reloadForm'  => __('Form lost connection. Reloading…', 'whop-payments'),
            ],
        ]);
    }

    /** Print <link rel=preload> for Whop checkout scripts early in <head>. */
    public static function print_whop_preloads(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        echo '<link rel="preload" href="https://js.whop.com/static/checkout/loader.js" as="script" crossorigin />' . "\n";
        echo '<link rel="preload" href="https://js.whop.com/static/checkout/index.js" as="script" crossorigin />' . "\n";
        echo '<link rel="dns-prefetch" href="https://js.whop.com" />' . "\n";
        echo '<link rel="preconnect" href="https://js.whop.com" crossorigin />' . "\n";
        echo '<link rel="dns-prefetch" href="https://whop.com" />' . "\n";
        echo '<link rel="preconnect" href="https://whop.com" crossorigin />' . "\n";
    }


    /**
     * Whop loader.js bootstraps index.js via /loader.js$/ replace — query strings break it.
     */
    public static function strip_whop_loader_ver(string $src, string $handle): string {
        if ($handle !== 'whop-checkout-loader' && $handle !== 'whop-checkout-index') {
            return $src;
        }
        // Keep only the clean CDN path (no ?ver= / &ver=).
        $q = strpos($src, '?');
        if ($q !== false) {
            $src = substr($src, 0, $q);
        }
        return $src;
    }

    /**
     * Match Whop docs: async + defer on the loader script tag.
     *
     * @param string $tag
     */
    public static function tag_whop_loader_async_defer(string $tag, string $handle, string $src): string {
        if ($handle !== 'whop-checkout-loader' && $handle !== 'whop-checkout-index') {
            return $tag;
        }
        if (!str_contains($tag, ' async')) {
            $tag = str_replace('<script ', '<script async ', $tag);
        }
        if (!str_contains($tag, ' defer')) {
            $tag = str_replace('<script ', '<script defer ', $tag);
        }
        return $tag;
    }

    /**
     * Allow Whop embed script + iframe if a CSP header is already present.
     *
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    public static function filter_csp_headers(array $headers): array {
        $key = '';
        foreach (array_keys($headers) as $k) {
            if (strtolower((string) $k) === 'content-security-policy') {
                $key = (string) $k;
                break;
            }
        }
        if ($key === '') {
            return $headers;
        }
        $csp = (string) $headers[$key];
        $additions = [
            'script-src' => ['https://js.whop.com', 'https://*.whop.com'],
            'frame-src'  => ['https://whop.com', 'https://*.whop.com', 'https://js.whop.com'],
            'connect-src'=> ['https://whop.com', 'https://*.whop.com', 'https://js.whop.com'],
            'img-src'    => ['https://*.whop.com'],
        ];
        foreach ($additions as $dir => $hosts) {
            foreach ($hosts as $host) {
                if (stripos($csp, $host) === false) {
                    // Append host to existing directive if present, else append a new directive clause.
                    if (preg_match('/\b' . preg_quote($dir, '/') . '\s([^;]*)/i', $csp, $m)) {
                        $csp = preg_replace(
                            '/\b' . preg_quote($dir, '/') . '\s([^;]*)/i',
                            $dir . ' ' . trim($m[1]) . ' ' . $host,
                            $csp,
                            1
                        );
                    } else {
                        $csp = rtrim($csp, '; ') . '; ' . $dir . ' ' . $host;
                    }
                }
            }
        }
        $headers[$key] = $csp;
        return $headers;
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
        $seen_remote = [];
        foreach ($list['data'] ?? [] as $pm) {
            if (!is_array($pm)) {
                continue;
            }
            $id = (string) ($pm['id'] ?? '');
            if ($id === '' || isset($seen_remote[$id])) {
                continue;
            }
            $seen_remote[$id] = true;
            $remote_ids[] = $id;
            self::upsert_token_from_whop($user_id, $pm);
        }

        self::prune_whop_tokens_not_in($user_id, $remote_ids);
        self::dedupe_local_tokens($user_id);
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


    /**
     * Fingerprint for idempotent display / local collapse (not a Whop id).
     */
    private static function token_fingerprint(WC_Payment_Token $token): string {
        $kind = (string) $token->get_meta('_sa_whop_pm_kind');
        if ($kind === '') {
            $kind = ($token instanceof WC_Payment_Token_ECheck) ? 'bank' : 'card';
        }
        $brand = strtolower((string) $token->get_meta('_sa_whop_pm_brand'));
        if ($brand === '' && $token instanceof WC_Payment_Token_CC) {
            $brand = strtolower((string) $token->get_card_type());
        }
        $last4 = method_exists($token, 'get_last4') ? (string) $token->get_last4() : '';
        $exp = '';
        if ($token instanceof WC_Payment_Token_CC && $kind !== 'bank') {
            $exp = (string) $token->get_expiry_month() . '/' . (string) $token->get_expiry_year();
        }
        return strtolower($kind . '|' . $brand . '|' . $last4 . '|' . $exp);
    }

    /**
     * Collapse duplicate local Whop tokens (same whop_id or same fingerprint).
     * Refresh must be idempotent — never leave clones on file.
     */
    public static function dedupe_local_tokens(int $user_id): int {
        $tokens = WC_Payment_Tokens::get_customer_tokens($user_id, 'whop');
        if (!$tokens) {
            return 0;
        }
        $by_whop = [];
        $by_fp = [];
        $drop = [];
        foreach ($tokens as $token) {
            if (!$token instanceof WC_Payment_Token) {
                continue;
            }
            $whop_id = (string) $token->get_meta(self::TOKEN_META_WHOP_ID);
            if ($whop_id === '') {
                $whop_id = (string) $token->get_token();
            }
            $tid = (int) $token->get_id();
            if ($whop_id !== '') {
                if (isset($by_whop[$whop_id])) {
                    $keep = $by_whop[$whop_id];
                    $drop_id = max($keep, $tid);
                    $stay = min($keep, $tid);
                    $drop[$drop_id] = true;
                    $by_whop[$whop_id] = $stay;
                    continue;
                }
                $by_whop[$whop_id] = $tid;
            }
            $fp = self::token_fingerprint($token);
            if ($fp === 'card||0000|' || $fp === 'bank||0000|' || str_ends_with($fp, '||')) {
                continue;
            }
            if (isset($by_fp[$fp])) {
                $keep = $by_fp[$fp];
                $other_whop = '';
                foreach ($tokens as $cand) {
                    if ((int) $cand->get_id() === $keep) {
                        $other_whop = (string) $cand->get_meta(self::TOKEN_META_WHOP_ID);
                        if ($other_whop === '') {
                            $other_whop = (string) $cand->get_token();
                        }
                        break;
                    }
                }
                if ($other_whop === '' && $whop_id !== '') {
                    $drop[$keep] = true;
                    $by_fp[$fp] = $tid;
                } else {
                    $drop[$tid] = true;
                }
                continue;
            }
            $by_fp[$fp] = $tid;
        }
        foreach (array_keys($drop) as $id) {
            WC_Payment_Tokens::delete((int) $id);
        }
        return count($drop);
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
        // Opportunistic local collapse so Refresh never paints clones.
        self::dedupe_local_tokens($user_id);

        $out = [];
        $seen_whop = [];
        $seen_fp = [];
        $tokens = WC_Payment_Tokens::get_customer_tokens($user_id);
        foreach ($tokens as $token) {
            $whop_id = '';
            if ($token->get_gateway_id() === 'whop') {
                $whop_id = (string) $token->get_meta(self::TOKEN_META_WHOP_ID);
                if ($whop_id === '') {
                    $whop_id = (string) $token->get_token();
                }
            }
            if ($whop_id !== '') {
                if (isset($seen_whop[$whop_id])) {
                    continue;
                }
                $seen_whop[$whop_id] = true;
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
            $fp = strtolower(
                (string) ($row['kind'] ?? 'card') . '|' .
                (string) ($row['brand'] ?? '') . '|' .
                (string) ($row['last4'] ?? '') . '|' .
                (string) ($row['exp'] ?? '')
            );
            if ($whop_id === '' && isset($seen_fp[$fp])) {
                continue;
            }
            $seen_fp[$fp] = true;
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

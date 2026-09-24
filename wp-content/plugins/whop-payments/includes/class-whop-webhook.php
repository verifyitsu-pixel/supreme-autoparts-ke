<?php
/**
 * Whop webhook endpoint via WooCommerce WC API: ?wc-api=whop_webhook
 * Listens for payment.succeeded and marks the matching order paid.
 * Open-amount /pay creates pending Woo orders; webhook completes them.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Whop_Webhook {

    public static function init(): void {
        add_action('woocommerce_api_whop_webhook', [self::class, 'handle']);
        add_action('woocommerce_api_whop_return', [self::class, 'handle_return']);
    }

    public static function webhook_url(): string {
        return home_url('/?wc-api=whop_webhook');
    }

    public static function return_url(WC_Order $order): string {
        return add_query_arg(
            [
                'wc-api'   => 'whop_return',
                'order_id' => $order->get_id(),
                'key'      => $order->get_order_key(),
            ],
            home_url('/')
        );
    }

    public static function handle_return(): void {
        $order_id  = absint($_GET['order_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_key = sanitize_text_field(wp_unslash((string) ($_GET['key'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status    = sanitize_text_field(wp_unslash((string) ($_GET['status'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $order = wc_get_order($order_id);
        if (!$order || !hash_equals($order->get_order_key(), $order_key)) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }

        if ($order->is_paid() || $order->has_status(['processing', 'completed'])) {
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        if (in_array($status, ['error', 'cancel', 'failed'], true)) {
            wc_add_notice(__('Payment was cancelled or failed. You can try again.', 'whop-payments'), 'notice');
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        $order->add_order_note(__('Customer returned from Whop Checkout; awaiting payment.succeeded webhook if not yet paid.', 'whop-payments'));
        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    public static function handle(): void {
        $raw = file_get_contents('php://input');
        if ($raw === false) {
            $raw = '';
        }

        $headers = self::collect_headers();
        $gateway = self::get_gateway();

        if (!$gateway) {
            status_header(503);
            echo 'gateway_unavailable';
            exit;
        }

        $secret = $gateway->get_webhook_secret();
        $id     = $headers['webhook-id'] ?? '';
        $ts     = $headers['webhook-timestamp'] ?? '';
        $sig    = $headers['webhook-signature'] ?? '';

        $sandbox = $gateway->is_sandbox_mode();

        if ($secret !== '') {
            if (!Whop_Api_Client::verify_webhook_signature($raw, $id, $ts, $sig, $secret)) {
                status_header(401);
                echo 'invalid_signature';
                exit;
            }
        } elseif (!$sandbox) {
            status_header(401);
            echo 'webhook_secret_required';
            exit;
        }

        $event = json_decode($raw, true);
        if (!is_array($event)) {
            status_header(400);
            echo 'invalid_json';
            exit;
        }

        $type = (string) ($event['type'] ?? '');
        if ($type === 'setup_intent.succeeded') {
            if (class_exists('Whop_Payment_Methods')) {
                Whop_Payment_Methods::handle_setup_intent_succeeded($event);
            }
            status_header(200);
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode(['received' => true, 'type' => $type]);
            exit;
        }
        if ($type !== 'payment.succeeded') {
            status_header(200);
            echo 'ignored';
            exit;
        }

        if ($id !== '') {
            $seen_key = 'whop_wh_' . md5($id);
            if (get_transient($seen_key)) {
                status_header(200);
                echo 'already_processed';
                exit;
            }
            set_transient($seen_key, 1, WEEK_IN_SECONDS);
        }

        $data       = is_array($event['data'] ?? null) ? $event['data'] : [];
        $metadata   = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        if ($metadata === [] && is_array($event['metadata'] ?? null)) {
            $metadata = $event['metadata'];
        }
        $order_id   = absint($metadata['order_id'] ?? 0);
        $payment_id = (string) ($data['id'] ?? '');
        $company    = (string) ($event['company_id'] ?? $event['account_id'] ?? $data['company_id'] ?? '');
        $purpose    = (string) ($metadata['purpose'] ?? '');

        $expected_company = $gateway->get_company_id();
        if ($expected_company !== '' && $company !== '' && !hash_equals($expected_company, $company)) {
            status_header(403);
            echo 'company_mismatch';
            exit;
        }

        // My Account $0.50 verify fee — no Woo order; sync saved payment method to WP user.
        if ($purpose === 'verify_payment_method') {
            $user_id = absint($metadata['wp_user_id'] ?? 0);
            $email   = sanitize_email((string) ($metadata['email'] ?? $data['email'] ?? ''));
            if (!$user_id && $email !== '' && is_email($email)) {
                $user = get_user_by('email', $email);
                if ($user) {
                    $user_id = (int) $user->ID;
                }
            }

            $pm = is_array($data['payment_method'] ?? null) ? $data['payment_method'] : null;
            $pm_id = (string) ($data['payment_method_id'] ?? ($pm['id'] ?? ''));

            if ($user_id && class_exists('Whop_Payment_Methods')) {
                if ($pm_id !== '' && is_array($pm)) {
                    Whop_Payment_Methods::upsert_token_from_whop($user_id, $pm);
                } elseif ($pm_id !== '') {
                    Whop_Payment_Methods::upsert_token_from_whop($user_id, ['id' => $pm_id]);
                }
                Whop_Payment_Methods::sync_user_payment_methods($user_id, true);
                error_log(sprintf(
                    '[whop-payments] verify_payment_method succeeded payment=%s user=%d',
                    $payment_id !== '' ? $payment_id : 'n/a',
                    $user_id
                ));
            }

            status_header(200);
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode([
                'received' => true,
                'type'     => 'verify_payment_method',
                'user_id'  => $user_id,
            ]);
            exit;
        }

        $order = $order_id ? wc_get_order($order_id) : false;

        // Open-amount /pay: create Woo order from webhook if pending order missing
        // (legacy checkouts used openpay-* synthetic ids before WC order wiring).
        $is_open_pay = ((string) ($metadata['open_pay'] ?? '') === '1')
            || ((string) ($metadata['source'] ?? '') === 'supreme-autoparts-open-pay')
            || str_starts_with((string) ($metadata['order_id'] ?? ''), 'openpay-');

        if (!$order && $is_open_pay && class_exists('Whop_Open_Pay')) {
            $amount = null;
            foreach (['total', 'final_amount', 'amount', 'subtotal'] as $field) {
                if (isset($data[$field]) && is_numeric($data[$field])) {
                    $amount = (float) $data[$field];
                    break;
                }
            }
            if ($amount === null && isset($metadata['amount_usd']) && is_numeric($metadata['amount_usd'])) {
                $amount = (float) $metadata['amount_usd'];
            }
            $email = sanitize_email((string) ($metadata['email'] ?? $data['email'] ?? $data['user_email'] ?? ''));
            if ($email === '' || !is_email($email)) {
                $email = 'openpay+' . substr(md5($payment_id !== '' ? $payment_id : wp_generate_password(8, false)), 0, 10) . '@supremeautoparts.co.ke';
            }
            $note = sanitize_text_field((string) ($metadata['note'] ?? ''));
            if ($amount !== null && $amount >= 1) {
                $order = Whop_Open_Pay::create_pending_order($amount, $email, $note, [
                    '_whop_payment_id_pending' => $payment_id,
                    '_sa_open_pay_legacy'      => '1',
                ]);
                if ($order instanceof WC_Order) {
                    $order_id = (int) $order->get_id();
                    $order->add_order_note(__('Open-pay: Woo order created from payment.succeeded webhook (no prior pending order).', 'whop-payments'));
                }
            }
        }

        if (!$order) {
            status_header(404);
            echo 'order_not_found';
            exit;
        }

        if ($order->is_paid() || $order->has_status(['processing', 'completed'])) {
            status_header(200);
            echo 'already_paid';
            exit;
        }

        $paid_amount = null;
        foreach (['total', 'final_amount', 'amount', 'subtotal'] as $field) {
            if (isset($data[$field]) && is_numeric($data[$field])) {
                $paid_amount = (float) $data[$field];
                break;
            }
        }
        if ($paid_amount !== null) {
            $order_total = (float) $order->get_total();
            if (abs($paid_amount - $order_total) > max(0.05, $order_total * 0.02)) {
                $order->add_order_note(sprintf(
                    /* translators: 1: webhook amount 2: order total */
                    __('Whop webhook amount mismatch (paid %1$s vs order %2$s). Completing with note for review.', 'whop-payments'),
                    (string) $paid_amount,
                    (string) $order_total
                ));
            }
        }

        $txn = $payment_id !== '' ? $payment_id : ('whop_' . ($id !== '' ? $id : wp_generate_password(8, false)));
        $order->payment_complete($txn);
        if ($order->get_meta('_sa_open_pay') === '1' && $order->has_status('processing')) {
            // Digital/custom payment — no shipping fulfillment required.
            $order->update_status('completed', __('Open-pay custom payment marked completed.', 'whop-payments'));
        }
        $order->add_order_note(sprintf(
            /* translators: 1: Whop payment id 2: webhook message id */
            __('Whop payment.succeeded — payment %1$s (webhook %2$s).', 'whop-payments'),
            $payment_id !== '' ? $payment_id : 'n/a',
            $id !== '' ? $id : 'n/a'
        ));
        $order->update_meta_data('_whop_payment_id', $payment_id);
        $pm_id = (string) ($data['payment_method_id'] ?? $data['payment_method']['id'] ?? '');
        if ($pm_id !== '') {
            $order->update_meta_data('_sa_whop_payment_method_id', $pm_id);
        }
        $order->save();

        // Whop buyer receipts are off — store must email the customer confirmation.
        if (function_exists('sa_core_ensure_customer_order_email')) {
            sa_core_ensure_customer_order_email($order);
        }

        $buyer_user = (int) $order->get_user_id();
        if ($buyer_user && class_exists('Whop_Payment_Methods')) {
            // Soft sync — reconcile Whop wallet after a successful charge.
            Whop_Payment_Methods::sync_user_payment_methods($buyer_user);
        }

        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(['received' => true, 'order_id' => $order_id]);
        exit;
    }

    /**
     * @return array<string,string>
     */
    private static function collect_headers(): array {
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name         = strtolower(str_replace('_', '-', substr($key, 5)));
                $out[$name] = (string) $value;
            }
        }
        foreach (['WEBHOOK_ID' => 'webhook-id', 'WEBHOOK_TIMESTAMP' => 'webhook-timestamp', 'WEBHOOK_SIGNATURE' => 'webhook-signature'] as $srv => $hdr) {
            if (!isset($out[$hdr]) && isset($_SERVER[$srv])) {
                $out[$hdr] = (string) $_SERVER[$srv];
            }
        }
        return $out;
    }

    private static function get_gateway(): ?WC_Gateway_Whop {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gw       = $gateways['whop'] ?? null;
        return $gw instanceof WC_Gateway_Whop ? $gw : null;
    }
}

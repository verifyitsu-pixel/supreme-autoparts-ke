<?php
/**
 * Supreme Autoparts: hardening layer for the official Pesapal WooCommerce plugin (v3.1.3).
 * Keeps the vendor files untouched. Swaps in a subclass that fixes:
 *  - SQL built from request values (IPN / callback) -> prepared statements
 *  - callback marking an order paid using any caller-supplied tracking id -> id is taken from our own
 *    order tracking row, and the Pesapal status must carry this order's merchant reference
 *  - IPN replays flipping paid orders to failed, double payment_complete -> state guarded
 *  - 5 CREATE TABLE queries on every gateway instantiation -> run once
 *  - TLS verification disabled in the API helper -> enabled, token cached, errors returned as objects
 *  - secrets / tokens written to the debug log -> redacted
 *  - unauthenticated cron endpoint that echoed order data -> needs SA_PESAPAL_CRON_KEY
 *  - clear USD total + KES conversion note at checkout and on the pay page
 * Keys stay in Railway env (see supreme-gateways-env.php). No secrets here.
 */
if (!defined('ABSPATH')) { exit; }

add_filter('woocommerce_payment_gateways', function ($methods) {
    if (getenv('SA_PESAPAL_HARDEN') === '0') { return $methods; } // kill switch
    if (!class_exists('WC_TheBunchKE_PesaPal_Pay_Gateway') || !class_exists('pesapalV30Helper')) {
        return $methods;
    }
    sa_pesapal_define_classes();
    foreach ($methods as $i => $m) {
        if ($m === 'WC_TheBunchKE_PesaPal_Pay_Gateway') { $methods[$i] = 'SA_Pesapal_Gateway'; }
    }
    return $methods;
}, 99);

/* Clear total on checkout (shown under the Pesapal option). */
add_filter('woocommerce_gateway_description', function ($desc, $id) {
    if ($id !== 'pesapal') { return $desc; }
    $note = 'Pay with M-Pesa, Airtel Money or card. Your order total stays in US dollars; the equivalent in KES is shown on the payment screen, at the payment provider's exchange rate, before you confirm.';
    return trim((string) $desc) === '' ? $note : $desc . '<br><small>' . esc_html($note) . '</small>';
}, 10, 2);

/* Currency clarity at checkout, right under the Total (theme hides the gateway description). */
add_action('woocommerce_review_order_after_order_total', function () {
    echo '<tr class="sa-usd-note"><td colspan="2" style="font-size:13px;line-height:1.4;color:#555;padding-top:6px">'
        . esc_html__("Charged in USD. M-Pesa / Airtel amounts are shown in KES at the payment provider's rate.", 'supreme-autoparts')
        . '</td></tr>';
});

/* Same note + exact total above the Pesapal iframe on the pay page. */
add_action('woocommerce_receipt_pesapal', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) { return; }
    echo '<p class="sa-pesapal-total" style="margin:0 0 12px;font-size:15px;line-height:1.4">'
        . 'Order total: <strong>' . wp_kses_post($order->get_formatted_order_total()) . '</strong>. '
        . 'The KES amount (converted at the provider's rate) is shown before you pay.</p>';
}, 1);

/* Mobile: no sideways scroll from the Pesapal frame. */
add_action('wp_head', function () {
    if (function_exists('is_checkout') && is_checkout()) {
        echo '<style>#pesapal-iframe{width:100%!important;max-width:100%}@media(max-width:600px){#pesapal-iframe{height:680px!important}}</style>';
    }
});

function sa_pesapal_define_classes() {
    if (class_exists('SA_Pesapal_Gateway')) { return; }

    class SA_Pesapal_Helper extends pesapalV30Helper {
        public function getAccessToken($consumer_key, $consumer_secret) {
            $ck = 'sa_pp_tok_' . md5($this->url . $consumer_key);
            $cached = get_transient($ck);
            if ($cached) { return (object) array('token' => $cached, 'status' => '200'); }
            $res = parent::getAccessToken($consumer_key, $consumer_secret);
            if (is_object($res) && !empty($res->token)) { set_transient($ck, $res->token, 240); }
            return $res;
        }
        public function curlRequest($url, $headers = null, $postData = null) {
            $args = array(
                'timeout' => 20,
                'redirection' => 0,
                'sslverify' => true,
                'headers' => array(),
            );
            if (!empty($headers['accept'])) { $args['headers']['Accept'] = $headers['accept']; }
            if (!empty($headers['content-type'])) { $args['headers']['Content-Type'] = $headers['content-type']; }
            if (!empty($headers['authorization'])) { $args['headers']['Authorization'] = $headers['authorization']; }
            if ($postData && count($postData)) {
                $args['body'] = wp_json_encode($postData);
                $res = wp_remote_post($url, $args);
            } else {
                $res = wp_remote_get($url, $args);
            }
            if (is_wp_error($res)) {
                return (object) array('status' => '500', 'error' => (object) array(
                    'error_type' => 'connection_error', 'code' => 'connection_error', 'message' => 'Could not reach Pesapal. Please try again.'));
            }
            $obj = json_decode(wp_remote_retrieve_body($res));
            if (!is_object($obj)) {
                return (object) array('status' => '500', 'error' => (object) array(
                    'error_type' => 'bad_response', 'code' => 'bad_response', 'message' => 'Unexpected response from Pesapal.'));
            }
            return $obj;
        }
    }

    class SA_Pesapal_Gateway extends WC_TheBunchKE_PesaPal_Pay_Gateway {
        public function __construct() {
            parent::__construct();
            $this->pesapalV30Helper = new SA_Pesapal_Helper($this->apimode);
        }

        /* ---------- logging without secrets ---------- */
        public function write_log($log) {
            if (true !== WP_DEBUG) { return; }
            if (is_object($log) || is_array($log)) { $log = print_r($log, true); }
            $log = (string) $log;
            if (stripos($log, 'secret') !== false || stripos($log, 'token') !== false || stripos($log, 'consumer') !== false) {
                $log = '[redacted: contains credentials]';
            }
            error_log('[pesapal] ' . substr($log, 0, 1500));
        }

        /* ---------- run table setup once ---------- */
        public function create_pesapal_table() {
            if (get_option('sa_pesapal_tables_v1') === 'done') { return; }
            parent::create_pesapal_table();
            update_option('sa_pesapal_tables_v1', 'done', false);
        }

        /* ---------- prepared statements ---------- */
        public function insert_transaction($order_id, $request) {
            global $wpdb;
            $t = $wpdb->prefix . 'woocommerce_pesapal_transactions';
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `$t` (`order_id`,`recurring_product`,`amount`,`currency`,`payment_status`,`merchant_ref`,`notification_type`,`first_name`,`last_name`,`phone`,`email`,`recurring_account`,`callback_url`) VALUES (%d,%s,%s,%s,'PLACED',%s,'PLACED',%s,%s,%s,%s,%s,%s)",
                (int) $order_id,
                (string) ($request->recurring_product ?? ''), (string) $request->amount, (string) $request->currency,
                (string) $request->pesapalMerchantReference, (string) $request->billing_first_name, (string) $request->billing_last_name,
                (string) $request->billing_phone, (string) $request->billing_email, (string) ($request->account_number ?? ''), (string) $request->callback_url
            ));
        }
        public function update_transaction($id, $ref, $type, $transaction) {
            global $wpdb;
            $t = $wpdb->prefix . 'woocommerce_pesapal_transactions';
            $wpdb->query($wpdb->prepare(
                "UPDATE `$t` SET payment_method=%s, payment_status=%s, confirmation_code=%s, tracking_id=%s, notification_type=%s WHERE merchant_ref=%s",
                (string) ($transaction->payment_method ?? ''), (string) ($transaction->payment_status_description ?? ''),
                (string) ($transaction->confirmation_code ?? ''), (string) $id, (string) $type, (string) $ref
            ));
        }
        public function get_merchant() {
            global $wpdb;
            if (!$this->consumer_key || !$this->consumer_secret) { return null; }
            $t = $wpdb->prefix . 'woocommerce_pesapal_merchant_details';
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `$t` WHERE pesapal_consumer_key = %s AND pesapal_secret_key = %s", $this->consumer_key, $this->consumer_secret
            ));
        }
        public function insert_order_tracking_data($orderId, $pesapalTrackingId = null, $orderTrackingId = null, $redirectURL = null) {
            global $wpdb;
            $t = $wpdb->prefix . 'woocommerce_pesapal_order_tracking_data';
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `$t` (`order_id`,`pesapal_tracking_id`,`order_tracking_id`,`redirect_url`) VALUES (%d,%s,%s,%s)",
                (int) $orderId, (string) $pesapalTrackingId, (string) $orderTrackingId, (string) $redirectURL
            ));
        }
        public function get_order_tracking_data($orderId, $orderTrackingId = null) {
            global $wpdb;
            $t = $wpdb->prefix . 'woocommerce_pesapal_order_tracking_data';
            if ($orderTrackingId) {
                return $wpdb->get_row($wpdb->prepare("SELECT order_id,pesapal_tracking_id,order_tracking_id,redirect_url FROM `$t` WHERE order_tracking_id = %s", (string) $orderTrackingId));
            }
            if ($orderId) {
                return $wpdb->get_row($wpdb->prepare("SELECT order_id,pesapal_tracking_id,order_tracking_id,redirect_url FROM `$t` WHERE order_id = %d ORDER BY id DESC", (int) $orderId));
            }
            return null;
        }
        public function createMpesaSTKRequest($object, $order_id, $phone) {
            global $wpdb;
            $t = $wpdb->prefix . 'woocommerce_pesapal_mobile_payments';
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `$t` (`order_id`,`phone`,`reference`,`transaction_id`,`request_code`,`payment_status`,`status`,`confirmation_code`,`created_at`,`call_back_received`,`is_active`) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,%s,0,0)",
                (int) $order_id, (string) $phone, (string) ($object->merchant_reference ?? ''), (string) ($object->transaction_id ?? ''),
                (string) ($object->request_code ?? ''), (string) ($object->payment_status ?? ''), (string) ($object->status ?? ''),
                (string) ($object->confirmation_code ?? ''), current_time('mysql')
            ));
        }

        /* ---------- shared, guarded status application ---------- */
        protected function sa_merchant_ref($order) {
            $prefix = $this->get_option('ppprefix');
            return $prefix ? strtoupper(str_replace(' ', '', $prefix)) . '-' . $order->get_order_number() : (string) $order->get_order_number();
        }

        /** Fetch status from Pesapal for a tracking id and apply it to $order only if it really belongs to it. */
        protected function sa_apply_status($order, $trackingId, $source) {
            if (!$order || !$trackingId) { return ''; }
            $tok = $this->pesapalV30Helper->getAccessToken($this->consumer_key, $this->consumer_secret);
            $access = (is_object($tok) && !empty($tok->token)) ? $tok->token : '';
            if (!$access) { return ''; }
            $resp = $this->pesapalV30Helper->getTransactionStatus($trackingId, $access);
            if (!is_object($resp) || empty($resp->payment_status_description)) { return ''; }

            // The status must be for THIS order.
            $ref = isset($resp->merchant_reference) ? (string) $resp->merchant_reference : '';
            if ($ref === '' || strcasecmp($ref, $this->sa_merchant_ref($order)) !== 0) {
                $order->add_order_note('Pesapal ' . esc_html($source) . ': ignored a status whose merchant reference did not match this order.');
                return '';
            }
            $status = strtoupper((string) $resp->payment_status_description);
            $summary = esc_html(wp_json_encode(array(
                'status' => $status,
                'method' => $resp->payment_method ?? '',
                'amount' => $resp->amount ?? '',
                'currency' => $resp->currency ?? '',
                'confirmation_code' => $resp->confirmation_code ?? '',
                'tracking_id' => $trackingId,
            )));

            if ($status === 'COMPLETED') {
                if (!$order->is_paid()) {
                    $order->add_order_note('<strong>Pesapal payment completed</strong> (' . esc_html($source) . '). ' . $summary);
                    $order->payment_complete((string) ($resp->confirmation_code ?? $trackingId));
                    $target = (string) $this->orderstatus;
                    if ($target && $target !== $order->get_status() && in_array($target, array('processing', 'completed', 'on-hold'), true)) {
                        $order->update_status($target);
                    }
                }
            } elseif ($status === 'FAILED') {
                if (in_array($order->get_status(), array('pending', 'on-hold'), true)) {
                    $order->update_status('failed', 'Pesapal payment failed (' . esc_html($source) . '). ');
                    $order->add_order_note($summary);
                }
            } elseif ($status === 'REVERSED') {
                if ($order->is_paid() && $order->get_status() !== 'refunded') {
                    $order->update_status('refunded', 'Pesapal payment reversed (' . esc_html($source) . '). ');
                    $order->add_order_note($summary);
                }
            }
            return $status;
        }

        /* Thank-you page refresh: uses the tracking id saved for this order, never a request value. */
        public function update_order_status($order_id) {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== 'pesapal' || $order->is_paid()) { return; }
            $row = $this->get_order_tracking_data((int) $order_id);
            if ($row && !empty($row->order_tracking_id)) {
                $this->sa_apply_status($order, $row->order_tracking_id, 'return page');
            }
        }

        /* IPN from Pesapal. Status is re-read from Pesapal's API, never trusted from the request. */
        public function ipn_response() {
            $trackingId = isset($_REQUEST['OrderTrackingId']) ? sanitize_text_field(wp_unslash($_REQUEST['OrderTrackingId'])) : '';
            $merchantRef = isset($_REQUEST['OrderMerchantReference']) ? sanitize_text_field(wp_unslash($_REQUEST['OrderMerchantReference'])) : '';
            $type = isset($_REQUEST['OrderNotificationType']) ? sanitize_text_field(wp_unslash($_REQUEST['OrderNotificationType'])) : '';

            $ok = false;
            if ($trackingId !== '' && $type === 'IPNCHANGE' && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $trackingId)) {
                $row = $this->get_order_tracking_data(null, $trackingId);
                $order = ($row && !empty($row->order_id)) ? wc_get_order((int) $row->order_id) : null;
                if ($order) {
                    $status = $this->sa_apply_status($order, $trackingId, 'IPN');
                    $ok = ($status !== '');
                    if ($ok) {
                        $this->update_transaction($trackingId, $this->sa_merchant_ref($order), $type, (object) array('payment_status_description' => $status));
                    }
                }
            }
            // Pesapal expects this body echoed back; unknown or unverifiable ids get 500 so Pesapal retries.
            $body = array(
                'orderNotificationType' => $type,
                'OrderTrackingId' => $trackingId,
                'orderMerchantReference' => $merchantRef,
                'status' => $ok ? '200' : '500',
            );
            wp_send_json($body, $ok ? 200 : 500);
        }

        /* Reconcile job: closed unless a key is set in env, and prints nothing about orders. */
        public function pesapalCron() {
            $key = getenv('SA_PESAPAL_CRON_KEY');
            $given = isset($_REQUEST['key']) ? (string) wp_unslash($_REQUEST['key']) : '';
            if (!$key || !hash_equals($key, $given)) { status_header(403); exit; }
            $orders = wc_get_orders(array(
                'status' => array('pending', 'on-hold', 'failed'),
                'payment_method' => 'pesapal',
                'date_created' => '>' . (time() - DAY_IN_SECONDS),
                'limit' => 50,
            ));
            $n = 0;
            foreach ($orders as $order) {
                $row = $this->get_order_tracking_data($order->get_id());
                if ($row && !empty($row->order_tracking_id)) { $this->sa_apply_status($order, $row->order_tracking_id, 'reconcile'); $n++; }
            }
            wp_send_json(array('checked' => $n));
        }
    }
}

/* Quiet the vendor plugin's "Undefined array key" / deprecation notices (log flood). Other errors pass through. */
$sa_pp_prev_handler = null;
$sa_pp_prev_handler = set_error_handler(function ($no, $str, $file = '', $line = 0) use (&$sa_pp_prev_handler) {
    if (in_array($no, array(E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_DEPRECATED), true)
        && is_string($file) && strpos($file, '/plugins/pesapal-woocommerce/') !== false) {
        return true;
    }
    return $sa_pp_prev_handler ? call_user_func($sa_pp_prev_handler, $no, $str, $file, $line) : false;
});

<?php
/**
 * Import historical external card gateway transactions as Supreme Autoparts Woo orders + ledger.
 *
 * Successful / positive captures → WC orders (payment_method sa_imported_gateway).
 * Failed / Initiated / refunds → option sa_imported_gateway_txs (ledger only).
 * Dedupes by PaymentReference / OrderPaymentReference / gateway Id / dedupe_key.
 *
 * Branding: Maxout Nutrition / Pharma Labs labels are rewritten to Supreme Autoparts.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array{ok:bool,message?:string,created:int,skipped_dup:int,ledger:int,errors:int,sample_order_ids:int[],counts:array}
 */
function sa_core_import_gateway_txs_file(string $file, array $opts = []): array
{
    $dry = !empty($opts['dry_run']);
    $limit = isset($opts['limit']) ? (int) $opts['limit'] : 0;

    if (!is_readable($file)) {
        return [
            'ok' => false,
            'message' => 'File not readable: ' . $file,
            'created' => 0,
            'skipped_dup' => 0,
            'ledger' => 0,
            'errors' => 1,
            'sample_order_ids' => [],
            'counts' => [],
        ];
    }

    $raw = file_get_contents($file);
    $payload = json_decode((string) $raw, true);
    if (!is_array($payload) || empty($payload['transactions']) || !is_array($payload['transactions'])) {
        return [
            'ok' => false,
            'message' => 'Invalid JSON payload (expected transactions[])',
            'created' => 0,
            'skipped_dup' => 0,
            'ledger' => 0,
            'errors' => 1,
            'sample_order_ids' => [],
            'counts' => [],
        ];
    }

    $txs = $payload['transactions'];
    if ($limit > 0) {
        $txs = array_slice($txs, 0, $limit);
    }

    $mute = static function (): void {
        add_filter('woocommerce_email_enabled_new_order', '__return_false', 999);
        add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false', 999);
        add_filter('woocommerce_email_enabled_customer_completed_order', '__return_false', 999);
        add_filter('woocommerce_email_enabled_customer_on_hold_order', '__return_false', 999);
        add_filter('woocommerce_email_enabled_customer_invoice', '__return_false', 999);
        add_filter('pre_wp_mail', 'sa_core_import_gateway_block_mail', 999);
    };
    $unmute = static function (): void {
        remove_filter('woocommerce_email_enabled_new_order', '__return_false', 999);
        remove_filter('woocommerce_email_enabled_customer_processing_order', '__return_false', 999);
        remove_filter('woocommerce_email_enabled_customer_completed_order', '__return_false', 999);
        remove_filter('woocommerce_email_enabled_customer_on_hold_order', '__return_false', 999);
        remove_filter('woocommerce_email_enabled_customer_invoice', '__return_false', 999);
        remove_filter('pre_wp_mail', 'sa_core_import_gateway_block_mail', 999);
    };

    if (!function_exists('sa_core_import_gateway_block_mail')) {
        /**
         * @param mixed $null
         * @return false|null
         */
        function sa_core_import_gateway_block_mail($null)
        {
            return false;
        }
    }

    $mute();

    $created = 0;
    $skipped = 0;
    $ledger = 0;
    $errors = 0;
    $samples = [];

    $ledger_store = get_option('sa_imported_gateway_txs', []);
    if (!is_array($ledger_store)) {
        $ledger_store = [];
    }

    foreach ($txs as $tx) {
        if (!is_array($tx)) {
            $errors++;
            continue;
        }
        try {
            $result = sa_core_import_gateway_one_tx($tx, $ledger_store, $dry);
            if ($result === 'created') {
                $created++;
                if (count($samples) < 12 && !empty($tx['_last_order_id'])) {
                    $samples[] = (int) $tx['_last_order_id'];
                }
            } elseif ($result === 'duplicate') {
                $skipped++;
            } elseif ($result === 'ledger') {
                $ledger++;
            } else {
                $errors++;
            }
        } catch (Throwable $e) {
            $errors++;
            if (defined('WP_CLI') && WP_CLI) {
                WP_CLI::warning('TX error: ' . $e->getMessage());
            }
        }
    }

    if (!$dry) {
        update_option('sa_imported_gateway_txs', $ledger_store, false);
        update_option('sa_imported_gateway_txs_meta', [
            'updated_at' => gmdate('c'),
            'source_counts' => $payload['counts'] ?? [],
            'last_run' => [
                'created' => $created,
                'skipped_dup' => $skipped,
                'ledger' => $ledger,
                'errors' => $errors,
            ],
        ], false);
    }

    $unmute();

    // Collect sample IDs from recent imported orders if we missed inline.
    if (!$dry && count($samples) < 5 && function_exists('wc_get_orders')) {
        $recent = wc_get_orders([
            'limit' => 8,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_sa_imported_gateway',
            'meta_value' => '1',
            'return' => 'ids',
        ]);
        foreach ($recent as $oid) {
            $oid = (int) $oid;
            if ($oid && !in_array($oid, $samples, true)) {
                $samples[] = $oid;
            }
            if (count($samples) >= 8) {
                break;
            }
        }
    }

    return [
        'ok' => $errors === 0,
        'message' => $dry ? 'Dry run complete' : 'Import complete',
        'created' => $created,
        'skipped_dup' => $skipped,
        'ledger' => $ledger,
        'errors' => $errors,
        'sample_order_ids' => $samples,
        'counts' => is_array($payload['counts'] ?? null) ? $payload['counts'] : [],
    ];
}

/**
 * @param array<string,mixed> $tx
 * @param array<string,mixed> $ledger_store
 * @return 'created'|'duplicate'|'ledger'|'error'
 */
function sa_core_import_gateway_one_tx(array &$tx, array &$ledger_store, bool $dry): string
{
    $dedupe = sa_core_import_gateway_dedupe_keys($tx);
    if ($dedupe === []) {
        return 'error';
    }

    if (sa_core_import_gateway_exists($dedupe, $ledger_store)) {
        return 'duplicate';
    }

    $create_order = !empty($tx['create_woo_order']);
    $amount = (float) ($tx['amount'] ?? 0);

    if (!$create_order || $amount <= 0) {
        if ($dry) {
            return 'ledger';
        }
        $key = $dedupe[0];
        $ledger_store[$key] = sa_core_import_gateway_ledger_row($tx, $dedupe);
        // Index aliases so re-runs skip.
        foreach ($dedupe as $k) {
            if ($k !== $key) {
                $ledger_store[$k] = ['_alias_of' => $key];
            }
        }
        return 'ledger';
    }

    if ($dry) {
        return 'created';
    }

    if (!function_exists('wc_create_order')) {
        return 'error';
    }

    $email = strtolower(trim((string) ($tx['customer_email'] ?? '')));
    $name = trim((string) ($tx['customer_name'] ?? ''));
    $phone = trim((string) ($tx['customer_phone'] ?? ''));
    $currency = strtoupper(trim((string) ($tx['currency'] ?? 'USD')));
    if ($currency === '') {
        $currency = 'USD';
    }

    if ($email === '' || !is_email($email)) {
        $ref = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($tx['order_reference'] ?? $tx['payment_reference'] ?? 'guest'));
        $email = 'imported+' . strtolower(substr((string) $ref, 0, 40)) . '@supremeautoparts.co.ke';
    }

    $user_id = sa_core_import_gateway_ensure_customer($email, $name, $phone);

    $order = wc_create_order([
        'customer_id' => $user_id,
        'created_via' => 'sa_imported_gateway',
        'status' => 'pending',
    ]);
    if (is_wp_error($order) || !$order instanceof WC_Order) {
        return 'error';
    }

    if (method_exists($order, 'set_currency')) {
        $order->set_currency($currency);
    } else {
        $order->update_meta_data('_order_currency', $currency);
    }

    $parts = preg_split('/\s+/', $name, 2) ?: [];
    $first = $parts[0] ?? '';
    $last = $parts[1] ?? '';
    $order->set_billing_first_name($first !== '' ? $first : 'Customer');
    $order->set_billing_last_name($last);
    $order->set_billing_email($email);
    if ($phone !== '') {
        $order->set_billing_phone($phone);
    }

    $narration = sa_core_import_gateway_clean_brand((string) ($tx['narration'] ?? 'Imported payment'));
    if ($narration === '') {
        $narration = 'Imported payment';
    }

    $item = new WC_Order_Item_Fee();
    $item->set_name($narration);
    $item->set_total($amount);
    $item->set_tax_status('none');
    $order->add_item($item);

    $order->set_payment_method('sa_imported_gateway');
    $order->set_payment_method_title('Card (imported)');

    $pay_ref = (string) ($tx['payment_reference'] ?? '');
    $gw_id = (string) ($tx['gateway_tx_id'] ?? '');
    $order_ref = (string) ($tx['order_reference'] ?? '');
    $order_pay_ref = (string) ($tx['order_payment_reference'] ?? '');
    $source_file = (string) ($tx['source_file'] ?? '');
    $fee = isset($tx['fee']) ? (float) $tx['fee'] : null;
    $settlement = isset($tx['settlement_amount']) ? (float) $tx['settlement_amount'] : null;
    $last4 = preg_replace('/\D/', '', (string) ($tx['masked_pan_last4'] ?? ''));
    if (strlen((string) $last4) > 4) {
        $last4 = substr((string) $last4, -4);
    }

    $order->update_meta_data('_sa_imported_gateway', '1');
    $order->update_meta_data('_sa_gateway_tx_id', $gw_id);
    $order->update_meta_data('_sa_payment_reference', $pay_ref);
    $order->update_meta_data('_sa_order_payment_reference', $order_pay_ref);
    $order->update_meta_data('_sa_original_order_reference', $order_ref);
    $order->update_meta_data('_sa_gateway_source_file', $source_file);
    $order->update_meta_data('_sa_gateway_source', (string) ($tx['source'] ?? ''));
    $order->update_meta_data('_sa_gateway_dedupe_key', $dedupe[0]);
    if ($fee !== null) {
        $order->update_meta_data('_sa_gateway_fee', wc_format_decimal($fee, 4));
    }
    if ($settlement !== null) {
        $order->update_meta_data('_sa_gateway_settlement', wc_format_decimal($settlement, 4));
    }
    if ($last4 !== '') {
        $order->update_meta_data('_sa_gateway_pan_last4', $last4);
    }
    if (!empty($tx['card_type'])) {
        $order->update_meta_data('_sa_gateway_card_type', sanitize_text_field((string) $tx['card_type']));
    }

    $ts = sa_core_import_gateway_parse_date((string) ($tx['date_created'] ?? ''));
    if ($ts > 0) {
        $order->set_date_created($ts);
        $order->set_date_paid($ts);
        $order->set_date_completed($ts);
    }

    $order->calculate_totals(false);
    $order->set_status('completed', 'Imported gateway payment marked paid.', true);
    $order->save();

    $note = sprintf(
        'Imported gateway tx %s as Supreme Autoparts (ref %s / order ref %s / source %s). Original subsidiary branding ignored.',
        $gw_id !== '' ? $gw_id : $pay_ref,
        $pay_ref !== '' ? $pay_ref : '—',
        $order_ref !== '' ? $order_ref : '—',
        $source_file !== '' ? $source_file : ((string) ($tx['source'] ?? 'export'))
    );
    $order->add_order_note($note, false, false);

    // Also register in ledger for Payments "Imported" unified view.
    $key = $dedupe[0];
    $row = sa_core_import_gateway_ledger_row($tx, $dedupe);
    $row['woo_order_id'] = (int) $order->get_id();
    $row['ledger_kind'] = 'order';
    $ledger_store[$key] = $row;
    foreach ($dedupe as $k) {
        if ($k !== $key) {
            $ledger_store[$k] = ['_alias_of' => $key];
        }
    }

    $tx['_last_order_id'] = (int) $order->get_id();
    return 'created';
}

/**
 * @param array<string,mixed> $tx
 * @return string[]
 */
function sa_core_import_gateway_dedupe_keys(array $tx): array
{
    $keys = [];
    foreach (['dedupe_key', 'payment_reference', 'order_payment_reference', 'gateway_tx_id'] as $f) {
        $v = trim((string) ($tx[$f] ?? ''));
        if ($v !== '' && $v !== '0' && strtoupper($v) !== 'N/A') {
            $keys[] = $v;
        }
    }
    // Source-scoped id fallback.
    $src = (string) ($tx['source'] ?? '');
    $gid = trim((string) ($tx['gateway_tx_id'] ?? ''));
    if ($src !== '' && $gid !== '') {
        $keys[] = $src . ':' . $gid;
    }
    return array_values(array_unique($keys));
}

/**
 * @param string[] $keys
 * @param array<string,mixed> $ledger_store
 */
function sa_core_import_gateway_exists(array $keys, array $ledger_store): bool
{
    foreach ($keys as $k) {
        if (isset($ledger_store[$k])) {
            return true;
        }
    }
    if (!function_exists('wc_get_orders')) {
        return false;
    }
    foreach ($keys as $k) {
        foreach (['_sa_payment_reference', '_sa_order_payment_reference', '_sa_gateway_tx_id', '_sa_gateway_dedupe_key'] as $meta) {
            $found = wc_get_orders([
                'limit' => 1,
                'return' => 'ids',
                'meta_key' => $meta,
                'meta_value' => $k,
            ]);
            if (!empty($found)) {
                return true;
            }
        }
    }
    return false;
}

function sa_core_import_gateway_ensure_customer(string $email, string $name, string $phone): int
{
    $existing = email_exists($email);
    if ($existing) {
        $uid = (int) $existing;
        $user = get_user_by('id', $uid);
        if ($user && !in_array('customer', (array) $user->roles, true) && !user_can($uid, 'manage_options')) {
            $user->add_role('customer');
        }
        if ($phone !== '') {
            update_user_meta($uid, 'billing_phone', $phone);
        }
        return $uid;
    }

    $parts = preg_split('/\s+/', trim($name), 2) ?: [];
    $first = $parts[0] ?? 'Customer';
    $last = $parts[1] ?? '';
    $login_base = sanitize_user(current(explode('@', $email)), true);
    if ($login_base === '') {
        $login_base = 'customer';
    }
    $login = $login_base;
    $n = 1;
    while (username_exists($login)) {
        $login = $login_base . $n;
        $n++;
        if ($n > 500) {
            $login = 'sa_imp_' . wp_generate_password(8, false);
            break;
        }
    }

    $uid = wp_insert_user([
        'user_login' => $login,
        'user_email' => $email,
        'user_pass' => wp_generate_password(24, true, true),
        'first_name' => $first,
        'last_name' => $last,
        'display_name' => trim($name) !== '' ? $name : $first,
        'role' => 'customer',
    ]);
    if (is_wp_error($uid)) {
        return 0;
    }
    $uid = (int) $uid;
    update_user_meta($uid, 'billing_email', $email);
    update_user_meta($uid, 'billing_first_name', $first);
    update_user_meta($uid, 'billing_last_name', $last);
    if ($phone !== '') {
        update_user_meta($uid, 'billing_phone', $phone);
    }
    update_user_meta($uid, '_sa_imported_gateway_customer', '1');
    return $uid;
}

/**
 * @param array<string,mixed> $tx
 * @param string[] $dedupe
 * @return array<string,mixed>
 */
function sa_core_import_gateway_ledger_row(array $tx, array $dedupe): array
{
    return [
        'dedupe_key' => $dedupe[0] ?? '',
        'dedupe_keys' => $dedupe,
        'gateway_tx_id' => (string) ($tx['gateway_tx_id'] ?? ''),
        'payment_reference' => (string) ($tx['payment_reference'] ?? ''),
        'order_payment_reference' => (string) ($tx['order_payment_reference'] ?? ''),
        'order_reference' => (string) ($tx['order_reference'] ?? ''),
        'date_created' => (string) ($tx['date_created'] ?? ''),
        'customer_name' => (string) ($tx['customer_name'] ?? ''),
        'customer_email' => (string) ($tx['customer_email'] ?? ''),
        'customer_phone' => (string) ($tx['customer_phone'] ?? ''),
        'amount' => (float) ($tx['amount'] ?? 0),
        'currency' => strtoupper((string) ($tx['currency'] ?? '')),
        'status' => (string) ($tx['status'] ?? ''),
        'narration' => sa_core_import_gateway_clean_brand((string) ($tx['narration'] ?? '')),
        'fee' => isset($tx['fee']) ? (float) $tx['fee'] : null,
        'settlement_amount' => isset($tx['settlement_amount']) ? (float) $tx['settlement_amount'] : null,
        'masked_pan_last4' => preg_replace('/\D/', '', (string) ($tx['masked_pan_last4'] ?? '')),
        'source' => (string) ($tx['source'] ?? ''),
        'source_file' => (string) ($tx['source_file'] ?? ''),
        'ledger_kind' => 'ledger',
        'brand' => 'Supreme Autoparts',
        'imported_at' => gmdate('c'),
        'woo_order_id' => 0,
    ];
}

function sa_core_import_gateway_clean_brand(string $s): string
{
    $map = [
        'MAXOUT NUTRITION' => 'Supreme Autoparts',
        'Maxout Nutrition' => 'Supreme Autoparts',
        'maxout nutrition' => 'Supreme Autoparts',
        'Pharma Labs UK' => 'Supreme Autoparts',
        'Pharma Labs' => 'Supreme Autoparts',
        'PHARMA LABS UK' => 'Supreme Autoparts',
        'PHARMA LABS' => 'Supreme Autoparts',
    ];
    return str_replace(array_keys($map), array_values($map), $s);
}

function sa_core_import_gateway_parse_date(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }
    $ts = strtotime($raw);
    if ($ts !== false && $ts > 0) {
        return (int) $ts;
    }
    // US style already covered by strtotime; try ISO-ish.
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $raw, $m)) {
        $ts = strtotime($m[1] . ' ' . $m[2]);
        return $ts !== false ? (int) $ts : 0;
    }
    return 0;
}

/**
 * @return array<int,array<string,mixed>>
 */
function sa_core_imported_gateway_ledger_rows(array $args = []): array
{
    $store = get_option('sa_imported_gateway_txs', []);
    if (!is_array($store)) {
        return [];
    }
    $status = isset($args['status']) ? (string) $args['status'] : '';
    $kind = isset($args['kind']) ? (string) $args['kind'] : ''; // order|ledger|''
    $q = isset($args['s']) ? strtolower(trim((string) $args['s'])) : '';
    $out = [];
    foreach ($store as $k => $row) {
        if (!is_array($row) || isset($row['_alias_of'])) {
            continue;
        }
        if ($kind !== '' && ($row['ledger_kind'] ?? '') !== $kind) {
            continue;
        }
        if ($status !== '' && strcasecmp((string) ($row['status'] ?? ''), $status) !== 0) {
            continue;
        }
        if ($q !== '') {
            $hay = strtolower(implode(' ', [
                (string) ($row['customer_email'] ?? ''),
                (string) ($row['customer_name'] ?? ''),
                (string) ($row['payment_reference'] ?? ''),
                (string) ($row['order_reference'] ?? ''),
                (string) ($row['gateway_tx_id'] ?? ''),
            ]));
            if (!str_contains($hay, $q)) {
                continue;
            }
        }
        $out[] = $row;
    }
    usort($out, static function ($a, $b) {
        return strcmp((string) ($b['date_created'] ?? ''), (string) ($a['date_created'] ?? ''));
    });
    $limit = isset($args['limit']) ? (int) $args['limit'] : 100;
    if ($limit > 0) {
        $out = array_slice($out, 0, $limit);
    }
    return $out;
}

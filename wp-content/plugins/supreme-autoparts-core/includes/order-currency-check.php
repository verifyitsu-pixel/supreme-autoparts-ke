<?php
/**
 * Token-gated QA: order document amounts stay in order currency under foreign geo.
 * GET /wp-json/supreme/v1/order-currency-check
 * Auth: X-SA-Recover-Token (same as recover-catalog).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', static function (): void {
    register_rest_route('supreme/v1', '/order-currency-check', [
        'methods'             => ['GET'],
        'permission_callback' => static function (): bool {
            if (current_user_can('manage_woocommerce') || current_user_can('manage_options')) {
                return true;
            }
            $token = (string) (getenv('SUPREME_RECOVER_TOKEN') ?: getenv('SUPREME_IMPORT_SECRET') ?: '');
            if ($token === '') {
                return false;
            }
            $provided = '';
            if (isset($_SERVER['HTTP_X_SA_RECOVER_TOKEN'])) {
                $provided = (string) $_SERVER['HTTP_X_SA_RECOVER_TOKEN'];
            } elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(\S+)/i', (string) $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                $provided = $m[1];
            } elseif (isset($_GET['token'])) {
                $provided = (string) $_GET['token'];
            }
            return $provided !== '' && hash_equals($token, $provided);
        },
        'callback' => 'sa_core_order_currency_check',
    ]);
});

/**
 * @return WP_REST_Response
 */
function sa_core_order_currency_check()
{
    if (!function_exists('wc_get_orders') || !function_exists('wc_price')) {
        return new WP_REST_Response(['ok' => false, 'error' => 'woocommerce_inactive'], 503);
    }

    $reset_geo = static function (string $cc): string {
        $_SERVER['HTTP_X_COUNTRY_CODE'] = $cc;
        $_SERVER['HTTP_CF_IPCOUNTRY'] = $cc;
        if (class_exists('SA_Geo_Detector')) {
            $ref = new ReflectionClass('SA_Geo_Detector');
            if ($ref->hasProperty('resolved')) {
                $p = $ref->getProperty('resolved');
                $p->setAccessible(true);
                $p->setValue(null, null);
            }
        }
        return class_exists('SA_Geo_Detector') ? SA_Geo_Detector::display_currency() : '?';
    };

    $find = static function (string $currency): ?WC_Order {
        $ids = wc_get_orders([
            'limit' => 40,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'ids',
            'status' => ['processing', 'completed', 'on-hold', 'pending', 'refunded'],
        ]);
        foreach ($ids as $id) {
            $o = wc_get_order($id);
            if ($o && strtoupper($o->get_currency()) === strtoupper($currency) && count($o->get_items()) > 0) {
                return $o;
            }
        }
        return null;
    };

    $has_code = static function (string $html, string $code): bool {
        $plain = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
        if (preg_match('/\b' . preg_quote($code, '/') . '\b/u', $plain)) {
            return true;
        }
        if ($code === 'USD' && (str_contains($plain, '$') || str_contains($html, 'woocommerce-Price-currencySymbol'))) {
            // Symbol alone is weak; require no KES/KSh when expecting USD under KE geo.
            return !preg_match('/\bKES\b|KSh/u', $plain);
        }
        if ($code === 'KES' && preg_match('/\bKES\b|KSh/u', $plain)) {
            return true;
        }
        return false;
    };

    $usd = $find('USD');
    if (!$usd) {
        return new WP_REST_Response(['ok' => false, 'error' => 'no_usd_order'], 404);
    }

    $kes = $find('KES');
    $created = false;
    if (!$kes) {
        $kes = wc_create_order(['customer_id' => 0, 'created_via' => 'sa-currency-check']);
        $kes->set_currency('KES');
        $kes->set_billing_first_name('Currency');
        $kes->set_billing_last_name('Check');
        $kes->set_billing_country('KE');
        $kes->set_billing_email('currency-check@example.com');
        $kes->set_shipping_country('KE');
        $item = new WC_Order_Item_Product();
        $item->set_name('KES Currency Check Part');
        $item->set_quantity(1);
        $item->set_subtotal(4500);
        $item->set_total(4500);
        $kes->add_item($item);
        $kes->set_total(4500);
        $kes->save();
        $created = true;
    }

    $cases = [];
    $fail = 0;
    foreach (
        [
            ['order' => $usd, 'geo' => 'KE', 'expect' => 'USD', 'forbid' => 'KES'],
            ['order' => $kes, 'geo' => 'US', 'expect' => 'KES', 'forbid' => 'USD'],
        ] as $c
    ) {
        /** @var WC_Order $o */
        $o = $c['order'];
        $display = $reset_geo($c['geo']);
        $should = class_exists('SA_Geo_Display') ? SA_Geo_Display::should_convert() : null;

        $raw = (string) wc_price((float) $o->get_total(), ['currency' => $o->get_currency()]);
        $d = function_exists('sa_rcpt_order_data') ? sa_rcpt_order_data($o) : null;
        $blob = $raw;
        if (is_array($d)) {
            foreach ($d['items'] as $it) {
                $blob .= ' ' . $it['unit'] . ' ' . $it['total'];
            }
            foreach ($d['totals'] as $row) {
                $blob .= ' ' . $row[1];
            }
        }
        $pay = function_exists('sa_hr_pay') ? sa_hr_pay($o) : ['amount' => ''];
        $sum = function_exists('sa_hr_summary_html') ? sa_hr_summary_html($o) : '';
        $blob .= ' ' . $pay['amount'] . ' ' . $sum;

        $forbidden = $has_code($blob, $c['forbid']) && !(
            // US geo + KES order: "USD" must not appear as a currency label on amounts.
            $c['forbid'] === 'USD' && !$has_code($blob, 'USD')
        );
        // Stricter forbid: KES/KSh must not appear for USD order under KE.
        if ($c['forbid'] === 'KES') {
            $forbidden = (bool) preg_match('/\bKES\b|KSh/u', html_entity_decode(wp_strip_all_tags($blob), ENT_QUOTES, 'UTF-8'));
        }
        if ($c['forbid'] === 'USD') {
            // Allow the word only if expect KES dominates; flag if $ amounts without KES.
            $plain = html_entity_decode(wp_strip_all_tags($blob), ENT_QUOTES, 'UTF-8');
            $forbidden = (bool) preg_match('/\bUSD\b/u', $plain) || (str_contains($plain, '$') && !preg_match('/\bKES\b|KSh/u', $plain));
        }

        $ok = !$forbidden && (
            $c['expect'] === 'USD'
                ? (bool) preg_match('/\bUSD\b|\$/u', html_entity_decode(wp_strip_all_tags($blob), ENT_QUOTES, 'UTF-8'))
                : (bool) preg_match('/\bKES\b|KSh/u', html_entity_decode(wp_strip_all_tags($blob), ENT_QUOTES, 'UTF-8'))
        );
        if (!$ok) {
            $fail++;
        }
        $cases[] = [
            'order_id' => $o->get_id(),
            'order_currency' => $o->get_currency(),
            'order_total' => $o->get_total(),
            'geo' => $c['geo'],
            'display_currency' => $display,
            'should_convert' => $should,
            'pay_amount' => $pay['amount'] ?? '',
            'wc_price_sample' => wp_strip_all_tags($raw),
            'ok' => $ok,
        ];
    }

    if ($created) {
        $kes->delete(true);
    }

    return new WP_REST_Response([
        'ok' => $fail === 0,
        'fail' => $fail,
        'geo_version' => defined('SA_GEO_CURRENCY_VERSION') ? SA_GEO_CURRENCY_VERSION : null,
        'core_version' => defined('SA_CORE_VERSION') ? SA_CORE_VERSION : null,
        'cases' => $cases,
    ], $fail === 0 ? 200 : 500);
}

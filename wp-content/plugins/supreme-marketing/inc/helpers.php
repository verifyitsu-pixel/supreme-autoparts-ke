<?php
/**
 * Shared helpers: settings, tokens, formatting, region handling.
 *
 * Regions: KE zone = Kenya + the rest of Africa (Nairobi shop details, KES prices,
 * store pickup, garage and roadside copy). US zone = United States and everyone
 * else (Portland address, USD prices, US/international shipping copy).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function sm_defaults(): array
{
    return [
        'master_enabled'       => 'no',   // owner switch: nothing sends while 'no'
        'test_mode'            => 'yes',  // while 'yes', all sends go to test_recipients only
        'test_recipients'      => 'standarddriveworks@gmail.com',
        'from_name'            => 'Supreme Autoparts',
        'from_email'           => '',     // '' = use the store's configured from address
        'reorder_days'         => 90,     // default days a consumable part lasts before a reorder reminder
        'winback_1_days'       => 60,
        'winback_2_days'       => 90,
        'browse_cap_days'      => 7,      // max one browse-abandonment email per this many days
        'send_batch'           => 50,     // max emails per 5-minute queue run
        'recommend_count'      => 4,
        'service_reminder_days'=> 150,    // days after a garage visit before the next-service reminder
    ];
}

function sm_seed_settings(): void
{
    if (!is_array(get_option('sm_settings'))) {
        update_option('sm_settings', sm_defaults(), false);
    }
    if (!is_array(get_option('sm_flows_enabled'))) {
        update_option('sm_flows_enabled', array_fill_keys(array_keys(SM_Flows::definitions()), 'yes'), false);
    }
    if (!is_array(get_option('sm_flow_delays'))) {
        update_option('sm_flow_delays', [], false);
    }
}

function sm_settings(): array
{
    $opts = get_option('sm_settings', []);
    return array_merge(sm_defaults(), is_array($opts) ? $opts : []);
}

function sm_setting(string $key)
{
    $opts = sm_settings();
    return $opts[$key] ?? null;
}

function sm_update_setting(string $key, $value): void
{
    $opts = sm_settings();
    $opts[$key] = $value;
    update_option('sm_settings', $opts, false);
}

function sm_master_on(): bool
{
    return sm_setting('master_enabled') === 'yes';
}

function sm_test_mode(): bool
{
    return sm_setting('test_mode') === 'yes';
}

function sm_test_recipients(): array
{
    $raw = (string) sm_setting('test_recipients');
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $e) {
        $e = trim($e);
        if (is_email($e)) {
            $out[] = $e;
        }
    }
    return $out;
}

function sm_from_email(): string
{
    $e = (string) sm_setting('from_email');
    if ($e !== '' && is_email($e)) {
        return $e;
    }
    if (function_exists('sa_core_store_email')) {
        return sa_core_store_email();
    }
    $woo = (string) get_option('woocommerce_email_from_address');
    return is_email($woo) ? $woo : (string) get_option('admin_email');
}

function sm_from_name(): string
{
    $n = trim((string) sm_setting('from_name'));
    return $n !== '' ? $n : 'Supreme Autoparts';
}

function sm_now(): string
{
    return current_time('mysql');
}

function sm_ts(): int
{
    return (int) current_time('timestamp');
}

/** Blog-local datetime string for a true (UTC) epoch. */
function sm_dt(int $true_epoch): string
{
    $offset = (int) current_time('timestamp') - time();
    return gmdate('Y-m-d H:i:s', $true_epoch + $offset);
}

/** HMAC token bound to an email + purpose. */
function sm_token(string $email, string $purpose): string
{
    return substr(hash_hmac('sha256', strtolower(trim($email)) . '|' . $purpose, wp_salt('auth')), 0, 32);
}

function sm_verify_token(string $email, string $purpose, string $token): bool
{
    return hash_equals(sm_token($email, $purpose), $token);
}

function sm_b64e(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function sm_b64d(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/'), true) ?: '';
}

/** Public endpoint URLs. */
function sm_unsubscribe_url(string $email): string
{
    return home_url('/sm/unsubscribe/' . sm_b64e(strtolower(trim($email))) . '/' . sm_token($email, 'unsub') . '/');
}

function sm_prefs_url(string $email): string
{
    return home_url('/sm/preferences/' . sm_b64e(strtolower(trim($email))) . '/' . sm_token($email, 'prefs') . '/');
}

function sm_confirm_url(string $email): string
{
    return home_url('/sm/confirm/' . sm_b64e(strtolower(trim($email))) . '/' . sm_token($email, 'confirm') . '/');
}

/** First name for greeting, with safe fallback. */
function sm_greet_name(array $sub): string
{
    $n = trim((string) ($sub['first_name'] ?? ''));
    if ($n !== '' && strlen($n) <= 40 && preg_match('/^[\p{L}\p{M}\'\- ]+$/u', $n)) {
        return $n;
    }
    return 'there';
}

/* ------------------------------------------------------------------ */
/* Region handling                                                     */
/* ------------------------------------------------------------------ */

/** 'KE' for Kenya/Africa, 'US' for everyone else (US + rest of world). */
function sm_zone_for_country(string $country): string
{
    $country = strtoupper(trim($country));
    if ($country === '') {
        return 'US';
    }
    if (function_exists('WC') && WC()->countries) {
        return WC()->countries->get_continent_code_for_country($country) === 'AF' ? 'KE' : 'US';
    }
    return $country === 'KE' ? 'KE' : 'US';
}

/** Zone for a subscriber row (region column holds the billing country code). */
function sm_subscriber_zone(?array $sub): string
{
    $cc = is_array($sub) ? (string) ($sub['region'] ?? '') : '';
    if ($cc === '' && is_array($sub) && !empty($sub['email']) && function_exists('sa_email_country_for_recipient')) {
        $cc = sa_email_country_for_recipient((string) $sub['email']);
    }
    return sm_zone_for_country($cc);
}

/** Business details for a zone (Nairobi shop for KE, Portland office for US). */
function sm_business(string $zone): array
{
    if (function_exists('sa_geo_business')) {
        return sa_geo_business($zone === 'KE' ? 'KE' : 'US');
    }
    return $zone === 'KE'
        ? ['one_line' => 'Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi, Kenya', 'country_name' => 'Kenya']
        : ['one_line' => '12800 NE Sandy Blvd, Portland, OR 97230', 'country_name' => 'United States'];
}

/** Customer chat link: WhatsApp username link only - customers never see a number. */
function sm_whatsapp_url(): string
{
    return function_exists('sa_whatsapp_url') ? sa_whatsapp_url() : 'https://wa.me/supremeautopart';
}

/**
 * Region-aware price for email. Product prices are stored in USD; KE zone sees KES.
 */
function sm_money(float $usd, string $zone): string
{
    $fmt = static function () use ($usd, $zone): string {
        if ($zone === 'KE' && class_exists('SA_Geo_FX')) {
            $kes = SA_Geo_FX::convert_usd($usd, 'KES');
            if ($kes !== null) {
                return html_entity_decode(wp_strip_all_tags(wc_price($kes, ['currency' => 'KES', 'decimals' => 0])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return html_entity_decode(wp_strip_all_tags(wc_price($usd, ['currency' => 'USD'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    };
    if (function_exists('sa_geo_without_conversion')) {
        return (string) sa_geo_without_conversion($fmt);
    }
    return $fmt();
}

/** Currency-aware price for a product, entity-decoded for email. */
function sm_product_price(WC_Product $p, string $zone = 'US'): string
{
    $price = $p->get_price();
    if ($price === '' || $price === null) {
        return '';
    }
    return sm_money((float) $price, $zone);
}

/** Absolute product image URL ('' when none); uses the store's email-safe image helper. */
function sm_product_image(WC_Product $p): string
{
    if (function_exists('sa_core_get_product_email_image_url')) {
        $src = sa_core_get_product_email_image_url($p, 300);
        if (is_string($src) && $src !== '') {
            return $src;
        }
    }
    $id = $p->get_image_id();
    if ($id) {
        $src = wp_get_attachment_image_url($id, 'woocommerce_thumbnail');
        if (is_string($src) && $src !== '') {
            return $src;
        }
    }
    return '';
}

/** Normalized product payload used by template product blocks. */
function sm_product_payload(WC_Product $p, string $zone = 'US'): array
{
    return [
        'id'    => $p->get_id(),
        'name'  => $p->get_name(),
        'url'   => get_permalink($p->get_id()),
        'price' => sm_product_price($p, $zone),
        'image' => sm_product_image($p),
    ];
}

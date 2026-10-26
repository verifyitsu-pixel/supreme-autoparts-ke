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
        'rotation_min_days'     => 1, // owner picked daily 2026-10-26 (iMessage phonemsg-01M5W5GT5AF63J5AVTABJRZ0T5): at most one rotation email per customer per day, spaced from other marketing mail
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

/**
 * Product IDs featured in marketing emails already sent to this address
 * (last $days days). Powers cross-email non-repeat selection: every new
 * email shows different products where the catalog allows.
 */
function sm_recent_sent_product_ids(string $email): array
{
    global $wpdb;
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }
    $table = SM_DB::table('send_products');
    // Full history, no time window: every new email features different products.
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT product_id FROM $table WHERE email = %s",
        $email
    ));
    return array_map('intval', (array) $rows);
}

/**
 * Cross-email non-repeat history + reservations.
 *
 * Lifecycle per queued email: selection runs under a per-recipient MySQL
 * lock, chosen products are written as state='reserved' rows keyed by
 * queue_id (closes the concurrent build/send race), the confirmed send
 * flips them to state='sent' with the send_id, and any failure or cancel
 * deletes the reservation so failed sends never mark history.
 * Exclusion reads every state - a product reserved for one queued email
 * is never picked for another email to the same recipient.
 */

/** Per-recipient selection lock. Returns the lock name or null if not held. */
function sm_grid_lock(string $email): ?string
{
    global $wpdb;
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }
    $name = 'sm_grid_' . md5($email);
    $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
    return (string) $got === '1' ? $name : null;
}

function sm_grid_unlock(?string $name): void
{
    global $wpdb;
    if ($name !== null && $name !== '') {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
}

/** Reserve selected products for a queued email (state=reserved). */
function sm_reserve_products(int $queue_id, string $email, array $product_ids): void
{
    global $wpdb;
    $table = SM_DB::table('send_products');
    $email = strtolower(trim($email));
    $now = sm_now();
    foreach (array_unique(array_filter(array_map('intval', $product_ids))) as $pid) {
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (send_id, queue_id, email, product_id, state, sent_at) VALUES (NULL, %d, %s, %d, 'reserved', %s)",
            $queue_id, $email, $pid, $now
        ));
    }
}

/** Mark a queue row's reserved products as sent once the provider accepted the mail. */
function sm_finalize_queue_products(int $queue_id, int $send_id): void
{
    global $wpdb;
    $table = SM_DB::table('send_products');
    $wpdb->query($wpdb->prepare(
        "UPDATE $table SET state = 'sent', send_id = %d WHERE queue_id = %d AND state = 'reserved'",
        $send_id, $queue_id
    ));
}

/** Release reservations for a queue row that failed or was cancelled. */
function sm_release_queue_products(int $queue_id): void
{
    global $wpdb;
    $table = SM_DB::table('send_products');
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE queue_id = %d AND state = 'reserved'", $queue_id));
}

/** Release all pending reservations for an email (unsubscribe/cancel-all path). */
function sm_release_email_products(string $email): void
{
    global $wpdb;
    $table = SM_DB::table('send_products');
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE email = %s AND state = 'reserved'", strtolower(trim($email))));
}

/** Word-safe clip for email captions. */
function sm_caption_clip(string $text, int $max = 140): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max - 1);
    $sp  = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > (int) ($max * 0.6)) {
        $cut = mb_substr($cut, 0, $sp);
    }
    return rtrim($cut, " \t\n\r\0\x0B,.;:-") . '...';
}

/**
 * Products the owner banned from marketing emails (user instruction
 * 2026-10-26: the pictured Spectre universal tubes and elbows must never
 * be featured). Marketing-only exclusion: catalog, prices and storefront
 * pages are untouched. Exact product IDs grounded from the live catalog.
 */
function sm_marketing_excluded_ids(): array
{
    return [76288, 76287, 76285, 76284, 76282, 76281, 76280, 76250, 76249,
            76248, 76246, 76245, 76244, 76242, 76241, 76240, 76238, 76237,
            76236, 76235];
}

/** Drop marketing-banned products from a candidate list. */
function sm_without_marketing_excluded(array $products): array
{
    $banned = array_flip(sm_marketing_excluded_ids());
    return array_values(array_filter($products, function ($p) use ($banned) {
        return !($p instanceof WC_Product) || !isset($banned[(int) $p->get_id()]);
    }));
}

/**
 * Marketing eligibility (owner instruction 2026-10-26, message
 * phonemsg-01M5VY3S6C3R26ASJJF3C8VTN1 + standing "focus on performance"):
 * marketing emails feature actual performance parts. A product is eligible
 * when any of its category names matches a performance-part type (grounded
 * in the live catalog's category structure: Cold Air Intakes, Brake Kits
 * Performance, Coilovers, Exhaust, Intercoolers, etc.). Fabrication raw
 * stock (aluminum tubing bins), wear/service items and brand-only
 * categories do not qualify on their own. The recommendation algorithm's
 * scoring is untouched - this only narrows the pool it scores over.
 */
function sm_marketing_performance_terms(): array
{
    return [
        'intake', 'air filter', 'exhaust', 'muffler', 'header', 'manifold',
        'cat-back', 'axle-back', 'downpipe', 'mid-pipe', 'x-pipe', 'h-pipe',
        'turbo', 'supercharg', 'intercooler', 'wastegate', 'blow-off',
        'boost', 'suspension', 'coilover', 'shock', 'strut', 'sway',
        'control arm', 'brake', 'rotor', 'caliper', 'clutch', 'drivetrain',
        'differential', 'ignition', 'tuner', 'programmer', 'throttle',
        'camshaft', 'pulley', 'nitrous',
    ];
}

/** True when the product belongs to a performance-part category. */
function sm_is_performance_product(WC_Product $p): bool
{
    $brand = '';
    if (taxonomy_exists('pa_brand')) {
        $brands = wc_get_product_terms($p->get_id(), 'pa_brand', ['fields' => 'names']);
        $brand  = trim((string) ($brands[0] ?? ''));
    }
    if ($brand === '') {
        $parts = preg_split('/\s+/', trim((string) $p->get_name()));
        $brand = (string) ($parts[0] ?? '');
    }
    $cats = (array) wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names']);
    foreach ($cats as $c) {
        $raw = html_entity_decode((string) $c);
        // Brand-led category names ("BMR Suspension") are the brand, not a
        // performance type - they cannot qualify a product on their own.
        if ($brand !== '' && stripos($raw, $brand) !== false) {
            continue;
        }
        $c = strtolower($raw);
        foreach (sm_marketing_performance_terms() as $term) {
            if (strpos($c, $term) !== false) {
                return true;
            }
        }
    }
    return false;
}

/** Category IDs/slugs matching the performance whitelist (resolved once). */
function sm_performance_category_slugs(): array
{
    static $slugs = null;
    if ($slugs !== null) {
        return $slugs;
    }
    $slugs = [];
    $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
    foreach ((array) $terms as $t) {
        if (!($t instanceof WP_Term)) {
            continue;
        }
        $name = strtolower(html_entity_decode($t->name));
        foreach (sm_marketing_performance_terms() as $term) {
            if (strpos($name, $term) !== false) {
                $slugs[] = $t->slug;
                break;
            }
        }
    }
    return $slugs;
}

/**
 * Category-bounded performance candidates (in stock, by popularity). The
 * algorithm's own top-N can be dominated by one hardware family; this
 * queries the performance categories directly so the eligible pool stays
 * deep. Same popularity ordering basis, presentation layer only.
 */
function sm_performance_candidates(int $count): array
{
    if (!function_exists('wc_get_products')) {
        return [];
    }
    $slugs = sm_performance_category_slugs();
    if (!$slugs) {
        return [];
    }
    return (array) wc_get_products([
        'status'       => 'publish',
        'stock_status' => 'instock',
        'limit'        => $count,
        'orderby'      => 'popularity',
        'order'        => 'DESC',
        'category'     => $slugs,
    ]);
}

/**
 * Backfill an eligible pool from the performance categories when the
 * algorithm's own fetch comes up short. Dedupes by product ID.
 */
function sm_marketing_pool_backfill(array $products, int $min, int $fetch): array
{
    if (count($products) >= $min) {
        return $products;
    }
    $have = [];
    foreach ($products as $p) {
        if ($p instanceof WC_Product) {
            $have[(int) $p->get_id()] = true;
        }
    }
    // Candidates come from category-bounded queries whose slugs can be
    // brand-led ("BMR Suspension"), so re-check full eligibility per item.
    foreach (sm_marketing_eligible(sm_performance_candidates($fetch)) as $c) {
        $id = (int) $c->get_id();
        if (isset($have[$id])) {
            continue;
        }
        $products[] = $c;
        $have[$id] = true;
        if (count($products) >= $fetch) {
            break;
        }
    }
    return $products;
}

/** Marketing-eligible candidates: not owner-banned, performance parts only. */
function sm_marketing_eligible(array $products): array
{
    $banned = array_flip(sm_marketing_excluded_ids());
    return array_values(array_filter($products, function ($p) use ($banned) {
        if (!($p instanceof WC_Product) || isset($banned[(int) $p->get_id()])) {
            return false;
        }
        return sm_is_performance_product($p);
    }));
}

/**
 * Category-level use context for grid captions. Factual use descriptions of
 * the category, never product performance promises.
 */
function sm_category_usecase(string $cat): string
{
    $c = strtolower($cat);
    $map = [
        'intake'      => 'for custom cold-air intake builds',
        'tube'        => 'for custom cold-air intake builds',
        'coupler'     => 'for intake and intercooler plumbing',
        'hose'        => 'for intake and intercooler plumbing',
        'exhaust'     => 'for exhaust work and custom routing',
        'muffler'     => 'for exhaust work and custom routing',
        'filter'      => 'a service replacement filter',
        'brake'       => 'for brake service',
        'suspension'  => 'for suspension work',
        'shock'       => 'for suspension work',
        'engine'      => 'for engine service',
        'ignition'    => 'for ignition service',
        'spark'       => 'for ignition service',
        'lighting'    => 'an electrical replacement',
        'electrical'  => 'an electrical replacement',
        'tyre'        => 'a replacement tyre',
        'tire'        => 'a replacement tyre',
        'motorcycle'  => 'for motorcycle upkeep',
        'wiper'       => 'a service replacement',
        'oil'         => 'for routine service',
        'coolant'     => 'for cooling-system service',
        'radiator'    => 'for cooling-system service',
        'sensor'      => 'a direct replacement sensor',
    ];
    foreach ($map as $k => $v) {
        if (strpos($c, $k) !== false) {
            return $v;
        }
    }
    return '';
}

/**
 * Performance theme of a product from its NON-brand-led category. Powers
 * coherent email storytelling: the grid's themes shape the headline, intro
 * and captions so the email reads as one relevant story, not a catalog dump.
 */
function sm_product_theme(WC_Product $p): string
{
    static $map = [
        'intake'     => ['intake', 'air filter'],
        'exhaust'    => ['exhaust', 'muffler', 'header', 'downpipe', 'manifold', 'cat-back', 'axle-back'],
        'boost'      => ['turbo', 'supercharg', 'intercooler', 'wastegate', 'blow-off', 'boost'],
        'suspension' => ['suspension', 'coilover', 'shock', 'strut', 'sway', 'control arm'],
        'braking'    => ['brake', 'rotor', 'caliper'],
        'drivetrain' => ['clutch', 'drivetrain', 'differential'],
        'ignition'   => ['ignition', 'spark'],
        'tuning'     => ['tuner', 'programmer'],
        'cooling'    => ['cooling', 'radiator'],
        'engine bay' => ['engine', 'alternator', 'pulley', 'camshaft', 'throttle'],
    ];
    $brand = '';
    if (taxonomy_exists('pa_brand')) {
        $brands = wc_get_product_terms($p->get_id(), 'pa_brand', ['fields' => 'names']);
        $brand  = trim((string) ($brands[0] ?? ''));
    }
    if ($brand === '') {
        $parts = preg_split('/\s+/', trim((string) $p->get_name()));
        $brand = (string) ($parts[0] ?? '');
    }
    $cats = (array) wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names']);
    foreach ($cats as $c) {
        $raw = html_entity_decode((string) $c);
        if ($brand !== '' && stripos($raw, $brand) !== false) {
            continue; // brand-led category, not the part's function
        }
        $c = strtolower($raw);
        foreach ($map as $theme => $terms) {
            foreach ($terms as $term) {
                if (strpos($c, $term) !== false) {
                    return $theme;
                }
            }
        }
    }
    return '';
}

/**
 * What the part DOES, from its specific category (more precise than the
 * theme phrase). Statements of function and application only - no invented
 * gains, no unverified claims.
 */
function sm_category_function(string $cat): string
{
    $c = strtolower($cat);
    $map = [
        // Accessories and service kits first: a hose/hardware kit FOR a
        // cold air kit is not an intake - caption its actual replacement
        // function, never the host part's (owner review 2026-10-26).
        'hoses and hardware' => 'to replace the intake kit\'s worn hoses and hardware',
        'replacement hose'   => 'to replace worn hoses on the kit',
        'hardware kit'       => 'to replace the original hardware for a secure fit',
        'rebuild kit'        => 'to refresh the original assembly with new wear parts',
        'alignment'   => 'to set alignment angles after suspension work',
        'air helper'  => 'adds load support when hauling or towing',
        'air spring'  => 'adds load support when hauling or towing',
        'air suspension' => 'adds load support when hauling or towing',
        'camber'      => 'to set camber after a lift or lowering',
        'coilover'    => 'ride-height adjustable suspension',
        'sway'        => 'for your suspension setup',
        'control arm' => 'for your suspension setup',
        'brake kit'   => 'a matched brake upgrade kit',
        'brake pads'  => 'replacement pads for your brake setup',
        'brake rotor' => 'replacement rotors for your brake setup',
        'cold air'    => 'feeds the engine cooler outside air',
        'intake'      => 'for your intake build',
        'exhaust'     => 'for your exhaust build',
        'muffler'     => 'for your exhaust build',
        'header'      => 'for your exhaust build',
        'intercooler' => 'cools boost charge air',
        'turbo'       => 'for your boosted setup',
        'clutch'      => 'for your drivetrain',
        'radiator'    => 'for your cooling system',
        'ignition'    => 'for your ignition system',
        'tuner'       => 'for tuning your setup',
        'replacement'        => 'to replace the worn original part',
    ];
    foreach ($map as $k => $v) {
        if (strpos($c, $k) !== false) {
            return $v;
        }
    }
    return '';
}

/** Factual "what it is for" phrase per theme. No performance promises. */
function sm_theme_phrase(string $theme): string
{
    $phrases = [
        'intake'     => 'for your intake build',
        'exhaust'    => 'for your exhaust build',
        'boost'      => 'for your boosted setup',
        'suspension' => 'for your suspension setup',
        'braking'    => 'for your brake setup',
        'drivetrain' => 'for your drivetrain',
        'ignition'   => 'for your ignition system',
        'tuning'     => 'for tuning your setup',
        'cooling'    => 'for your cooling system',
        'engine bay' => 'for your engine bay',
    ];
    return $phrases[$theme] ?? '';
}

/**
 * Distinguishing features parsed from the product name itself (material,
 * finish, options like a MAFS mount) - the details that tell two similar
 * listings apart. Name-derived, so always factual.
 */
function sm_name_features(string $name): string
{
    $f = [];
    if (preg_match('/\b(aluminum|aluminium|stainless|mild steel|steel|chrome|silicone|rubber|polished|powdercoated|black|red|blue)\b/i', $name, $m)) {
        $f[] = strtolower($m[1]);
    }
    if (preg_match('/\bw\/\s?([A-Za-z0-9 ]+?)(?:\s*[-\x{2013}\x{2014}]|$)/u', $name, $m)) {
        $f[] = 'with ' . trim($m[1]);
    }
    return implode(' ', array_slice($f, 0, 2));
}

/**
 * Short truthful caption for email product grids: a concise hook that says
 * what sets THIS part apart - distinguishing features from the name plus
 * category use context. Supplier sentences are used only when they carry
 * real function content without claims, boilerplate or spec repetition.
 * Duplicate captions within one grid fall back to a factual line.
 * Never invents claims.
 */
function sm_product_caption(WC_Product $p): string
{
    global $sm_used_captions;
    if (!is_array($sm_used_captions)) {
        $sm_used_captions = [];
    }
    $name  = trim((string) $p->get_name());
    $brand = '';
    if (taxonomy_exists('pa_brand')) {
        $brands = wc_get_product_terms($p->get_id(), 'pa_brand', ['fields' => 'names']);
        $brand  = trim((string) ($brands[0] ?? ''));
    }
    if ($brand === '') {
        $parts = preg_split('/\s+/', $name);
        $brand = (string) ($parts[0] ?? '');
    }
    // Most specific category that is not just the brand name repeated.
    $cats = array_filter(array_map('trim', (array) wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names'])));
    $cat  = '';
    foreach (array_reverse(array_values($cats)) as $c) {
        // Skip brand-led categories ("BMR Suspension" on a bracket is the
        // brand, not the part's function).
        if ($brand !== '' && stripos($c, $brand) !== false) {
            continue;
        }
        $cat = $c;
        break;
    }

    $caption = '';

    // 1) A supplier sentence only when it adds real function content.
    $desc = trim(html_entity_decode(wp_strip_all_tags((string) $p->get_short_description()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $desc = (string) preg_replace('/\s+/u', ' ', $desc);
    if ($desc !== '' && strcasecmp($desc, $name) !== 0) {
        $claimy      = '/(\b\d+\s?(?:hp|bhp|whp)\b|horsepower|torque\s+gain|boosts?\s+(?:power|performance)|\d+\s?%\s+(?:more|better|gain|increase)|guarantee|#1|number one|best[- ]selling|most popular|miracle)/i';
        $boilerplate = '/(design your very own|wide (?:range|selection)|high[- ]quality|we offer|our products are|shop (?:now|today)|order yours|manufactured using|made from|made of)/i';
        $catalog_open = '/^(each|this|these|the)\s/i';
        $function    = '/(designed for|fits?\s|replaces?|seals?|protects?|filters?|mounts?|adapts?|connects?|includes?|allows?)/i';
        $name_nums   = preg_split('/[^0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        foreach ((array) preg_split('/(?<=[.!?])\s+/u', $desc) as $s) {
            $s = trim($s);
            $len = mb_strlen($s);
            if ($len < 25 || $len > 120 || !preg_match($function, $s)) {
                continue;
            }
            if (preg_match($claimy, $s) || preg_match($boilerplate, $s) || preg_match($catalog_open, $s)) {
                continue;
            }
            // Problem statements ("outer tie rods can wear out...") are not
            // hooks; the function sentence is what earns the click.
            if (preg_match('/(wear out|over time|can cause|leads? to|factory (?:parts?|components?)|stock (?:parts?|components?))/i', $s)) {
                continue;
            }
            // Reject spec repetition: 2+ of the name's own numbers restated.
            $s_nums = preg_split('/[^0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
            if (count(array_intersect($s_nums, $name_nums)) >= 2) {
                continue;
            }
            $caption = $s;
            break;
        }
    }

    // 2) AI-composed caption when the approved provider is connected
    // (fail-closed: returns '' until then, and never sees recipient data).
    if (function_exists('sm_ai_caption')) {
        $caption = sm_ai_caption($p);
    }

    // 3) Name-derived feature hook + function context (theme first).
    if ($caption === '') {
        $feat = sm_name_features($name);
        // The name is the most specific source: "Alignment Cam Kit" says
        // what the part does even when its shelf category is generic.
        $use  = sm_category_function($name);
        if ($use === '') {
            $use = sm_category_function($cat);
        }
        if ($use === '') {
            $use = sm_theme_phrase(sm_product_theme($p));
        }
        if ($use === '') {
            $use = sm_category_usecase($cat);
        }
        if ($use === '') {
            // No usable category: ground the use context in the product's
            // own attributes (its name), e.g. "tube" -> intake builds.
            $use = sm_category_usecase($name);
        }
        $accessory = (bool) preg_match('/replacement|hardware\\s+kit|rebuild\\s+kit/i', $name);
        $lead = $accessory
            ? trim($brand . ($feat !== '' ? ' ' . $feat : ''))
            : trim($brand . ($feat !== '' ? ' ' . $feat : ($cat !== '' ? ' ' . $cat : '')));
        if ($lead !== '' && $use !== '') {
            $caption = ucfirst($lead) . ' - ' . $use;
        } elseif ($feat !== '') {
            $caption = ucfirst(trim($brand . ' ' . $feat));
        }
    }

    // 3) Factual fallback.
    if ($caption === '') {
        $caption = trim($brand . ($cat !== '' ? ' - ' . $cat : ''));
    }

    $caption = sm_caption_clip($caption, 110);
    $key = md5(strtolower($caption));
    if ($caption !== '' && isset($sm_used_captions[$key])) {
        // Same caption already shown for another product in this grid:
        // differentiate with the name's own distinguishing tail.
        $tail = '';
        $clean = trim((string) preg_replace('/\s*\([^)]*\)\s*/u', ' ', $name)); // drop (part numbers)
        $words = (array) preg_split('/\s+/', $clean);
        // Drop trailing SKU tokens ("1736", "W217602061") and a trailing
        // brand repeat - the distinguishing detail is the fitment up
        // front, never the part number (owner review 2026-10-26).
        while ($words && (preg_match('/^[A-Za-z]*\d{3,}[A-Za-z]*$/', (string) end($words)) || ($brand !== '' && strcasecmp((string) end($words), $brand) === 0))) {
            array_pop($words);
        }
        if ($words && $brand !== '' && strcasecmp((string) $words[0], $brand) === 0) {
            array_shift($words);
        }
        $tail = implode(' ', array_slice($words, 0, 3));
        $lead = trim($brand . ($tail !== '' ? ' ' . $tail : ''));
        $use2 = sm_theme_phrase(sm_product_theme($p));
        $alt  = $lead . ($use2 !== '' ? ' - ' . $use2 : '');
        $caption = ($alt !== '' && !isset($sm_used_captions[md5(strtolower($alt))])) ? $alt : '';
        $key = md5(strtolower($caption));
    }
    if ($caption !== '') {
        $sm_used_captions[$key] = true;
    }
    return $caption;
}

/** Reset per-grid caption dedup state (called before each grid is built). */
function sm_reset_caption_dedup(): void
{
    global $sm_used_captions;
    $sm_used_captions = [];
}

/**
 * Presentation-layer variety for product grids: keeps the recommendation
 * order exactly as scored, but avoids a grid that is all one category.
 * Never re-scores or re-ranks.
 */
function sm_diverse_products(array $products, int $count, int $max_per_cat = 2): array
{
    $clean = [];
    foreach ($products as $p) {
        if ($p instanceof WC_Product) {
            $clean[] = $p;
        }
    }
    if (count($clean) <= 1) {
        return $clean;
    }
    $picked   = [];
    $deferred = [];
    $seen     = [];
    foreach ($clean as $p) {
        // Every category term counts: products sharing ANY term (brand line,
        // parent range, type) with $max_per_cat already-picked items defer,
        // so a grid can never fill with one product family.
        $cats = array_map('intval', (array) wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'ids']));
        $capped = false;
        foreach ($cats as $c) {
            if (($seen[$c] ?? 0) >= $max_per_cat) {
                $capped = true;
                break;
            }
        }
        if ($capped) {
            $deferred[] = $p;
            continue;
        }
        foreach ($cats as $c) {
            $seen[$c] = ($seen[$c] ?? 0) + 1;
        }
        $picked[] = $p;
        if (count($picked) >= $count) {
            return $picked;
        }
    }
    foreach ($deferred as $p) {
        $picked[] = $p;
        if (count($picked) >= $count) {
            break;
        }
    }
    return $picked;
}

/** Normalized product payload used by template product blocks. */
function sm_product_payload(WC_Product $p, string $zone = 'US'): array
{
    return [
        'id'      => $p->get_id(),
        'name'    => $p->get_name(),
        'url'     => get_permalink($p->get_id()),
        'price'   => sm_product_price($p, $zone),
        'image'   => sm_product_image($p),
        'caption' => sm_product_caption($p),
        'theme'   => sm_product_theme($p),
    ];
}

/* ---------------------------------------------------------------------
 * Catalog rotation (user instruction 2026-10-26, message
 * phonemsg-01M5W26QDEPBD5TYWP2RNRWFT5 - the user's own iMessage: the
 * email series never ends, it touches all products, categories and
 * brands, then the loop restarts).
 *
 * Coverage authority is PRODUCT-level and fully deterministic: a cycle
 * ends only when every currently-eligible product has been featured, so
 * singleton themes and big categories are both fully walked before the
 * loop restarts. New arrivals join the current cycle automatically;
 * products that leave the catalog stop mattering. The optional AI plan
 * (sm_ai_rotation_plan) is advisory: it may re-rank among themes that
 * still have uncovered products and supply copy that passes the same
 * claim filters; any doubt fails closed to the deterministic walk.
 * ------------------------------------------------------------------ */

/** Brand name from the pa_brand taxonomy only (name-guessing is not a theme). */
function sm_rotation_product_brand(WC_Product $p): string
{
    if (taxonomy_exists('pa_brand')) {
        $brands = wc_get_product_terms($p->get_id(), 'pa_brand', ['fields' => 'names']);
        $b = trim((string) ($brands[0] ?? ''));
        if ($b !== '') {
            return $b;
        }
    }
    return '';
}

/** Rotation theme keys for one product: cat:<slug> (non-brand-led) + brand:<name>. */
function sm_rotation_product_theme_keys(WC_Product $p): array
{
    $brand = sm_rotation_product_brand($p);
    $keys  = [];
    foreach ((array) wp_get_post_terms($p->get_id(), 'product_cat') as $t) {
        if (!($t instanceof WP_Term)) {
            continue;
        }
        $name = html_entity_decode((string) $t->name);
        if ($brand !== '' && stripos($name, $brand) !== false) {
            continue; // brand-led category is the brand theme, not a separate shelf
        }
        $keys['cat:' . $t->slug] = true;
    }
    if ($brand !== '') {
        $keys['brand:' . $brand] = true;
    }
    return array_keys($keys);
}

/**
 * Theme labels + deterministic ordering (eligible-product count desc,
 * label asc), cached for an hour. Ordering only - coverage is tracked at
 * product level in the rotation cycle, never by theme label.
 */
function sm_rotation_themes(): array
{
    $cached = get_transient('sm_rotation_themes');
    if (is_array($cached) && $cached !== []) {
        return $cached;
    }
    if (!function_exists('wc_get_products')) {
        return [];
    }
    $counts    = [];
    $cat_names = [];
    foreach (sm_marketing_eligible(wc_get_products(['status' => 'publish', 'stock_status' => 'instock', 'limit' => 500])) as $p) {
        if (!($p instanceof WC_Product)) {
            continue;
        }
        $pkeys = sm_rotation_product_theme_keys($p);
        if ($pkeys === []) {
            $pkeys = ['cat:__other__'];
        }
        foreach ($pkeys as $k) {
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }
        foreach ((array) wp_get_post_terms($p->get_id(), 'product_cat') as $t) {
            if ($t instanceof WP_Term && !isset($cat_names[$t->slug])) {
                $cat_names[$t->slug] = html_entity_decode((string) $t->name);
            }
        }
    }
    $out = [];
    foreach ($counts as $k => $n) {
        $out[$k] = [
            'label' => $k === 'cat:__other__' ? 'More parts' : (str_starts_with($k, 'cat:') ? ($cat_names[substr($k, 4)] ?? substr($k, 4)) : substr($k, 6)),
            'count' => $n,
        ];
    }
    uasort($out, function ($a, $b) {
        return ($b['count'] <=> $a['count']) ?: strcasecmp((string) $a['label'], (string) $b['label']);
    });
    set_transient('sm_rotation_themes', $out, HOUR_IN_SECONDS);
    return $out;
}

/** Display label for a theme key, even when the cached universe missed it. */
function sm_rotation_theme_label(string $key, array $themes): string
{
    if ($key === 'cat:__other__') {
        return 'More parts';
    }
    if (isset($themes[$key]['label'])) {
        return (string) $themes[$key]['label'];
    }
    if (str_starts_with($key, 'cat:')) {
        $t = get_term_by('slug', substr($key, 4), 'product_cat');
        if ($t instanceof WP_Term) {
            return html_entity_decode((string) $t->name);
        }
    }
    return substr($key, (int) strpos($key, ':') + 1);
}

/**
 * Pure cycle step (unit-tested): the pool products not yet featured this
 * cycle. When coverage is complete the whole pool becomes eligible again
 * and restart=true - the loop restarts, exactly as the owner described.
 * Covered IDs that left the catalog are ignored.
 */
function sm_rotation_cycle_advance(array $pool_ids, array $covered_ids): array
{
    $cov = array_flip(array_map('intval', $covered_ids));
    $uncovered = [];
    foreach ($pool_ids as $id) {
        $id = (int) $id;
        if (!isset($cov[$id])) {
            $uncovered[] = $id;
        }
    }
    if ($uncovered === [] && $pool_ids !== []) {
        return ['uncovered' => array_map('intval', $pool_ids), 'restart' => true];
    }
    return ['uncovered' => $uncovered, 'restart' => false];
}

/** Cycle state row for a recipient. */
function sm_rotation_state_get(string $email): array
{
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT cycle, cycle_started_at FROM " . SM_DB::table('rotation_state') . " WHERE email = %s",
        strtolower(trim($email))
    ), ARRAY_A);
    if (is_array($row)) {
        return ['cycle' => (int) $row['cycle'], 'cycle_started_at' => (string) $row['cycle_started_at']];
    }
    return ['cycle' => 1, 'cycle_started_at' => ''];
}

function sm_rotation_state_put(string $email, int $cycle, string $started): void
{
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO " . SM_DB::table('rotation_state') . " (email, cycle, cycle_started_at, updated_at) VALUES (%s, %d, %s, %s) ON DUPLICATE KEY UPDATE cycle = VALUES(cycle), cycle_started_at = VALUES(cycle_started_at), updated_at = VALUES(updated_at)",
        strtolower(trim($email)),
        $cycle,
        $started,
        sm_now()
    ));
}

/** Product IDs featured in this recipient's current rotation cycle. */
function sm_rotation_covered_product_ids(string $email, string $cycle_started_at): array
{
    global $wpdb;
    if ($cycle_started_at === '') {
        return [];
    }
    $sp = SM_DB::table('send_products');
    $sd = SM_DB::table('sends');
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT sp.product_id FROM $sp sp JOIN $sd sd ON sd.id = sp.send_id WHERE sp.email = %s AND sd.template = 'rotation_1' AND sp.state = 'sent' AND sp.sent_at >= %s",
        strtolower(trim($email)),
        $cycle_started_at
    )));
}

/**
 * Pure spacing check (unit-tested): is a rotation email due for a
 * recipient whose last marketing email (any flow) was $last_sent_at?
 * Daily means at most one per day - and never stacked on another
 * marketing email sent within the same window.
 */
function sm_rotation_due(?string $last_sent_at, int $min_days, ?int $now = null): bool
{
    if ($last_sent_at === null || trim($last_sent_at) === '') {
        return true; // never emailed: due
    }
    $ts = strtotime($last_sent_at);
    if (!$ts) {
        return true;
    }
    $now = $now ?? sm_ts();
    return $now - $ts >= max(1, $min_days) * DAY_IN_SECONDS;
}

/** Request-scoped handoff of the rotation theme to the send builder. */
function sm_rotation_last_ctx(?array $set = null): array
{
    static $held = ['theme_label' => '', 'ai' => null];
    if ($set !== null) {
        $held = $set;
    }
    return $held;
}

/**
 * Pick the next rotation theme + grid for a recipient. Coverage is
 * product-level: themes are walked in deterministic order but a cycle
 * only completes when every eligible product has been featured - a
 * singleton theme still gets its email (grid shrinks to what the shelf
 * has), and a 40-product category takes as many emails as it needs.
 * No-repeat within a cycle is guaranteed by coverage; after the restart
 * the loop may feature products again, by design.
 */
/**
 * LOCAL engagement affinity for grid selection: ids of products from emails
 * this subscriber opened, product links they clicked, and items they
 * purchased, plus the categories/brands behind them. Used ONLY inside this
 * server to exclude already-purchased items and pre-order the candidate
 * pool by relevance - none of it is ever sent to the external AI provider.
 * Rotation sends already require marketing consent.
 */
function sm_rotation_engagement(string $email): array
{
    $out = ['opened_ids' => [], 'clicked_ids' => [], 'purchased_ids' => [], 'engaged_cats' => [], 'engaged_brands' => []];
    $email = strtolower(trim($email));
    if ($email === '') {
        return $out;
    }
    global $wpdb;
    $seen = function (array $ids): array {
        $ids = array_map('intval', $ids);
        return array_slice(array_values(array_unique(array_filter($ids))), 0, 20);
    };
    // Products inside emails they actually opened.
    $sp = SM_DB::table('send_products');
    $sd = SM_DB::table('sends');
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT sp.product_id FROM $sp sp JOIN $sd s ON s.id = sp.send_id
         WHERE sp.email = %s AND s.opened_at IS NOT NULL ORDER BY sp.id DESC LIMIT 40",
        $email
    ));
    $out['opened_ids'] = $seen((array) $rows);
    // Product links they clicked (click redirect records the destination URL).
    $lk   = SM_DB::table('links');
    $urls = $wpdb->get_col($wpdb->prepare(
        "SELECT l.url FROM $lk l JOIN $sd s ON s.id = l.send_id
         WHERE s.email = %s AND l.clicks > 0 ORDER BY l.id DESC LIMIT 20",
        $email
    ));
    $clicked = [];
    foreach ((array) $urls as $u) {
        $pid = url_to_postid((string) $u);
        if ($pid > 0) {
            $clicked[] = $pid;
        }
    }
    $out['clicked_ids'] = $seen($clicked);
    // Past purchases (WooCommerce orders under this email).
    if (function_exists('wc_get_orders')) {
        $bought = [];
        foreach ((array) wc_get_orders(['billing_email' => $email, 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC']) as $order) {
            foreach ($order->get_items() as $item) {
                $bought[] = $item->get_product_id();
            }
        }
        $out['purchased_ids'] = $seen($bought);
    }
    // Categories and brands behind anything they engaged with.
    $cats = [];
    $brands = [];
    foreach (array_merge($out['opened_ids'], $out['clicked_ids'], $out['purchased_ids']) as $pid) {
        foreach ((array) wp_get_post_terms($pid, 'product_cat', ['fields' => 'ids']) as $tid) {
            $cats[(int) $tid] = true;
        }
        $b = function_exists('sm_rotation_product_brand') ? sm_rotation_product_brand(wc_get_product($pid)) : '';
        if ($b !== '') {
            $brands[$b] = true;
        }
    }
    $out['engaged_cats']   = array_map('intval', array_keys($cats));
    $out['engaged_brands'] = array_map('strval', array_keys($brands));
    return $out;
}

function sm_rotation_pick(string $email, int $count, int $queue_id = 0): array
{
    $empty = ['theme_key' => '', 'theme_label' => '', 'ai' => null, 'products' => []];
    if (!function_exists('wc_get_products')) {
        return $empty;
    }
    $pool = sm_marketing_eligible(wc_get_products(['status' => 'publish', 'stock_status' => 'instock', 'limit' => 500]));
    if (!$pool) {
        return $empty;
    }
    $pool_ids = array_map(function ($p) {
        return (int) $p->get_id();
    }, $pool);
    $themes = sm_rotation_themes();
    $email  = strtolower(trim($email));
    $state  = $email !== '' ? sm_rotation_state_get($email) : ['cycle' => 1, 'cycle_started_at' => ''];
    $covered = ($email !== '' && $state['cycle_started_at'] !== '')
        ? sm_rotation_covered_product_ids($email, $state['cycle_started_at'])
        : [];
    $adv = sm_rotation_cycle_advance($pool_ids, $covered);
    if (!empty($adv['restart']) && $email !== '') {
        sm_rotation_state_put($email, $state['cycle'] + 1, sm_now());
    }
    $open_ids = array_flip($adv['uncovered']);
    $by_theme = [];
    foreach ($pool as $p) {
        if (!isset($open_ids[(int) $p->get_id()])) {
            continue;
        }
        $pkeys = sm_rotation_product_theme_keys($p);
        if ($pkeys === []) {
            $pkeys = ['cat:__other__']; // no category/brand mapping: still coverable
        }
        foreach ($pkeys as $k) {
            $by_theme[$k][] = $p;
        }
    }
    if (!$by_theme) {
        return $empty; // eligible pool has no theme mapping at all
    }
    $ordered = array_values(array_filter(array_keys($themes), function ($k) use ($by_theme) {
        return isset($by_theme[$k]);
    }));
    foreach (array_keys($by_theme) as $k) {
        if (!in_array($k, $ordered, true)) {
            $ordered[] = $k; // themes the cached universe missed keep their turn
        }
    }
    $lock = $email !== '' ? sm_grid_lock($email) : null;
    try {
        $k = $ordered[0];
        // AI chooses the grid from this theme's uncovered pool; the diverse
        // deterministic pick fills any slots the AI did not name and is the
        // full fallback when AI is unavailable. Copy is composed AFTER the
        // grid is final, so subject/headline/intro always match the actual
        // products shown (no copy/grid swap).
        $eng    = $email !== '' ? sm_rotation_engagement($email) : null;
        $choose = function (array $pool_k) use ($count, $eng) {
            // LOCAL personalization: never re-offer what they already bought
            // (while the pool has alternatives), and pre-order the pool so
            // products sharing categories/brands with their opened/clicked/
            // purchased items come first. Only the reordered public pool is
            // offered to the AI - no behavior history leaves the server.
            if (is_array($eng)) {
                if ($eng['purchased_ids'] && count($pool_k) > 1) {
                    $f = array_values(array_filter($pool_k, function ($p) use ($eng) {
                        return !in_array((int) $p->get_id(), $eng['purchased_ids'], true);
                    }));
                    if ($f) {
                        $pool_k = $f;
                    }
                }
                if ($eng['engaged_cats'] || $eng['engaged_brands']) {
                    $score = function ($p) use ($eng) {
                        $sc = 0;
                        foreach ((array) wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'ids']) as $tid) {
                            if (in_array((int) $tid, $eng['engaged_cats'], true)) {
                                $sc += 2;
                            }
                        }
                        $b = sm_rotation_product_brand($p);
                        if ($b !== '' && in_array($b, $eng['engaged_brands'], true)) {
                            $sc += 3;
                        }
                        return $sc;
                    };
                    $scored = array_map(function ($p) use ($score) {
                        return [$score($p), $p];
                    }, $pool_k);
                    usort($scored, function ($a, $b) {
                        return $b[0] <=> $a[0]; // PHP 8 stable: ties keep pool order
                    });
                    $pool_k = array_map(function ($r) {
                        return $r[1];
                    }, $scored);
                }
            }
            $products = [];
            $ids      = function_exists('sm_ai_rotation_select')
                ? sm_ai_rotation_select($pool_k, $count)
                : null;
            if (is_array($ids)) {
                $byid = [];
                foreach ($pool_k as $p) {
                    $byid[(int) $p->get_id()] = $p;
                }
                foreach ($ids as $id) {
                    if (isset($byid[$id])) {
                        $products[] = $byid[$id];
                    }
                }
            }
            if (count($products) < $count) {
                $have = array_map(function ($p) {
                    return (int) $p->get_id();
                }, $products);
                foreach (sm_diverse_products($pool_k, $count) as $p) {
                    if (count($products) >= $count) {
                        break;
                    }
                    if (!in_array((int) $p->get_id(), $have, true)) {
                        $products[] = $p;
                    }
                }
            }
            return $products;
        };
        $products = $choose($by_theme[$k]); // 1..count, singletons allowed
        $ai = function_exists('sm_ai_rotation_plan')
            ? sm_ai_rotation_plan($themes, $ordered, $k, $products)
            : null;
        if ($queue_id > 0 && $email !== '') {
            sm_reserve_products($queue_id, $email, array_map(function ($p) {
                return (int) $p->get_id();
            }, $products));
        }
        return [
            'theme_key'   => $k,
            'theme_label' => sm_rotation_theme_label($k, $themes),
            'ai'          => $ai,
            'products'    => $products,
        ];
    } finally {
        sm_grid_unlock($lock);
    }
}


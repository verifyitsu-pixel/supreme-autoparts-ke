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
        if (strcasecmp($c, $brand) !== 0) {
            $cat = $c;
            break;
        }
    }

    $caption = '';

    // 1) A supplier sentence only when it adds real function content.
    $desc = trim(html_entity_decode(wp_strip_all_tags((string) $p->get_short_description()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $desc = (string) preg_replace('/\s+/u', ' ', $desc);
    if ($desc !== '' && strcasecmp($desc, $name) !== 0) {
        $claimy      = '/(\b\d+\s?(?:hp|bhp|whp)\b|horsepower|torque\s+gain|boosts?\s+(?:power|performance)|\d+\s?%\s+(?:more|better|gain|increase)|guarantee|#1|number one|best[- ]selling|most popular|miracle)/i';
        $boilerplate = '/(design your very own|wide (?:range|selection)|high[- ]quality|we offer|our products are|shop (?:now|today)|order yours|manufactured using|made from|made of)/i';
        $function    = '/(designed for|fits?\s|replaces?|seals?|protects?|filters?|mounts?|adapts?|connects?|includes?|allows?)/i';
        $name_nums   = preg_split('/[^0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        foreach ((array) preg_split('/(?<=[.!?])\s+/u', $desc) as $s) {
            $s = trim($s);
            $len = mb_strlen($s);
            if ($len < 25 || $len > 120 || !preg_match($function, $s)) {
                continue;
            }
            if (preg_match($claimy, $s) || preg_match($boilerplate, $s)) {
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

    // 2) Name-derived feature hook + category use context.
    if ($caption === '') {
        $feat = sm_name_features($name);
        $use  = sm_category_usecase($cat);
        if ($use === '') {
            // No usable category: ground the use context in the product's
            // own attributes (its name), e.g. "tube" -> intake builds.
            $use = sm_category_usecase($name);
        }
        $lead = trim($brand . ($feat !== '' ? ' ' . $feat : ($cat !== '' ? ' ' . $cat : '')));
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
        // Same caption already shown for another product in this grid.
        $alt = trim($brand . ($cat !== '' ? ' ' . $cat : ''));
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
    ];
}

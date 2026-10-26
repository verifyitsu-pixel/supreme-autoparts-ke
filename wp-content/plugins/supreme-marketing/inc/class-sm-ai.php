<?php
/**
 * AI composition adapter - PREPARED, fail-closed, no credential required.
 *
 * When the user-approved free provider is connected (wp-config constants SM_AI_ENDPOINT/SM_AI_TOKEN,
 * environment, or options set programmatically - never an admin UI field,
 * never git, never chat - once the provider agent finishes), captions MAY be composed from CATALOG-ONLY facts (name, brand,
 * category, price - never recipient, order or behavior data). Every AI
 * output passes the same claim/boilerplate filters as the rule-based copy,
 * is cached per product for 24h, and ANY failure (no credential, network
 * error, timeout, filtered or empty output) falls back to the rule-based
 * caption. Until a credential exists this file makes zero network calls and
 * changes nothing. AI is an enhancer, never a dependency: it fails closed.
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Credential sources, in order: wp-config constants, environment, then the
 * option set programmatically by the provider agent. The token is never
 * shown in an admin settings field, never committed to git, never in chat.
 */
function sm_ai_endpoint(): string
{
    if (defined('SM_AI_ENDPOINT') && (string) SM_AI_ENDPOINT !== '') {
        return (string) SM_AI_ENDPOINT;
    }
    $env = getenv('SM_AI_ENDPOINT');
    return ($env !== false && (string) $env !== '') ? (string) $env : (string) get_option('sm_ai_endpoint', '');
}

function sm_ai_token(): string
{
    if (defined('SM_AI_TOKEN') && (string) SM_AI_TOKEN !== '') {
        return (string) SM_AI_TOKEN;
    }
    $env = getenv('SM_AI_TOKEN');
    return ($env !== false && (string) $env !== '') ? (string) $env : (string) get_option('sm_ai_token', '');
}

/** Shared safety floor for all AI output: no claims, no offers, no boilerplate. */
function sm_ai_claim_free(string $text): bool
{
    return !preg_match('/(\b\d+\s?(?:hp|bhp|whp)\b|horsepower|torque\s+gain|\d+\s?%\s+(?:more|better|gain|increase)|guarantee|#1|best[- ]selling|most popular|miracle|free shipping|discount|sale)/i', $text);
}

/** True only when a provider credential is configured. */
function sm_ai_ready(): bool
{
    return sm_ai_endpoint() !== '' && sm_ai_token() !== '';
}

/**
 * Catalog-only caption attempt. Returns '' unless AI succeeds and passes
 * the claim filters, so callers always keep their rule-based fallback.
 */
function sm_ai_caption(WC_Product $p): string
{
    if (!sm_ai_ready()) {
        return '';
    }
    $key = 'sm_ai_cap_' . $p->get_id();
    $hit = get_transient($key);
    if (is_string($hit)) {
        return $hit;
    }
    $facts = [
        'name'     => $p->get_name(),
        'category' => implode(', ', (array) wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names'])),
        'price'    => $p->get_price(),
    ];
    $resp = wp_remote_post(sm_ai_endpoint(), [
        'timeout' => 4,
        'headers' => ['Authorization' => 'Bearer ' . sm_ai_token(), 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode([
            'task'  => 'one-line factual product caption, max 12 words, what the part is for, no claims, no prices',
            'facts' => $facts,
        ]),
    ]);
    if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
        return '';
    }
    $body = json_decode((string) wp_remote_retrieve_body($resp), true);
    $text = trim((string) ($body['caption'] ?? ''));
    if ($text === '' || mb_strlen($text) > 120) {
        return '';
    }
    // Same safety floor as rule-based copy: no claims, no boilerplate.
    if (!sm_ai_claim_free($text)) {
        return '';
    }
    set_transient($key, $text, DAY_IN_SECONDS);
    return $text;
}

/**
 * Advisory rotation plan for the ongoing catalog walk (user instruction
 * 2026-10-26: the AI should loop in and help pick the theme and write the
 * email). Catalog-only facts and theme labels leave the server - never
 * recipient identity, orders or behavior beyond theme coverage names.
 *
 * The deterministic core (sm_rotation_pick) owns coverage, cadence and
 * no-repeat; this function's theme choice is accepted only when it names
 * an uncovered available theme whose shelf can fill a grid, and every
 * copy field passes sm_ai_claim_free + length caps. Cost guard: one call
 * per build, 4s timeout, result cached per theme per ISO week. ANY
 * failure returns null and the deterministic walk proceeds unchanged.
 *
 * Expected provider contract: POST {task, available_themes, already_covered,
 * products:[{id,name,category,price}]} -> {theme_key?, subject?, headline?,
 * intro?} (all fields optional; unknown fields ignored).
 */
function sm_ai_rotation_plan(array $themes, array $covered_keys, string $chosen_key, array $products): ?array
{
    if (!sm_ai_ready()) {
        return null;
    }
    $cache = 'sm_ai_plan_' . md5($chosen_key . '|' . gmdate('o-W'));
    $hit   = get_transient($cache);
    if (is_array($hit)) {
        return $hit;
    }
    $avail = [];
    foreach ($themes as $k => $t) {
        if (!in_array($k, $covered_keys, true)) {
            $avail[$k] = (string) ($t['label'] ?? $k);
        }
    }
    if ($avail === []) {
        $avail[$chosen_key] = (string) ($themes[$chosen_key]['label'] ?? $chosen_key);
    }
    $facts = [];
    foreach (array_slice($products, 0, 8) as $p) {
        if (!($p instanceof WC_Product)) {
            continue;
        }
        $facts[] = [
            'id'       => (int) $p->get_id(),
            'name'     => $p->get_name(),
            'category' => implode(', ', (array) wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names'])),
            'price'    => $p->get_price(),
        ];
    }
    $resp = wp_remote_post(sm_ai_endpoint(), [
        'timeout' => 4,
        'headers' => ['Authorization' => 'Bearer ' . sm_ai_token(), 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode([
            'task'             => 'weekly rotation email plan: pick the next theme_key from available_themes for one coherent email, then subject (<=70 chars), headline (<=50 chars), intro (<=200 chars). Facts only: the parts are in stock and priced as listed. No sales claims, no offers, no superlatives.',
            'available_themes' => $avail,
            'already_covered'  => array_values(array_map(function ($k) use ($themes) {
                return (string) ($themes[$k]['label'] ?? $k);
            }, $covered_keys)),
            'products'         => $facts,
        ]),
    ]);
    if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
        return null;
    }
    $body = json_decode((string) wp_remote_retrieve_body($resp), true);
    if (!is_array($body)) {
        return null;
    }
    $plan = [];
    $tk   = (string) ($body['theme_key'] ?? '');
    if ($tk !== '' && isset($avail[$tk])) {
        $plan['theme_key'] = $tk;
    }
    foreach (['subject' => 70, 'headline' => 50, 'intro' => 200] as $f => $max) {
        $v = trim((string) ($body[$f] ?? ''));
        if ($v === '' || mb_strlen($v) > $max || !sm_ai_claim_free($v)) {
            continue;
        }
        $plan[$f] = $v;
    }
    if ($plan === []) {
        return null;
    }
    set_transient($cache, $plan, WEEK_IN_SECONDS);
    return $plan;
}

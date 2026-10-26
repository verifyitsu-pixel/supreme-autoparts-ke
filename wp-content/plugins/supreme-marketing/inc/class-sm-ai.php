<?php
/**
 * AI composition adapter - PREPARED, fail-closed, no credential required.
 *
 * When the user-approved free provider is connected (options sm_ai_endpoint
 * + sm_ai_token, set via the vault-backed settings once the provider agent
 * finishes), captions MAY be composed from CATALOG-ONLY facts (name, brand,
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

/** True only when a provider credential is configured. */
function sm_ai_ready(): bool
{
    return (string) get_option('sm_ai_endpoint', '') !== ''
        && (string) get_option('sm_ai_token', '') !== '';
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
    $resp = wp_remote_post((string) get_option('sm_ai_endpoint'), [
        'timeout' => 4,
        'headers' => ['Authorization' => 'Bearer ' . (string) get_option('sm_ai_token'), 'Content-Type' => 'application/json'],
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
    if (preg_match('/(\b\d+\s?(?:hp|bhp|whp)\b|horsepower|torque\s+gain|\d+\s?%\s+(?:more|better|gain|increase)|guarantee|#1|best[- ]selling|most popular|miracle|free shipping|discount|sale)/i', $text)) {
        return '';
    }
    set_transient($key, $text, DAY_IN_SECONDS);
    return $text;
}

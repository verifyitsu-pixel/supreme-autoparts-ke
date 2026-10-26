<?php
/**
 * AI composition adapter - fail-closed, no credential required.
 *
 * Provider: the store's own free Cloudflare Workers AI account (one
 * account per business; the provider agent installs credentials as
 * Railway environment variables - never git, never chat, never an admin
 * UI field). Endpoint is the account's model run URL:
 * https://api.cloudflare.com/client/v4/accounts/<acct>/ai/run/<model>
 *
 * Wire contract (grounded against the real API, no proxy): POST
 * {messages, max_tokens, response_format:{type:"json_object"},
 * chat_template_kwargs:{enable_thinking:false}} and read
 * result.choices[0].message.content with finish_reason == "stop"
 * (a truncated/refused completion is discarded), falling back to
 * result.response only when choices are absent. Billed usage is read
 * from result.usage.neurons and counted against a conservative daily
 * budget; a 429 trips the budget for the rest of the day.
 *
 * Only CATALOG facts leave the server (product id/name/category/price
 * and theme labels) - never recipient, order or behavior data. Every AI
 * output passes the same claim/length filters as the rule-based copy and
 * is cached (plan: per chosen theme per ISO week; caption: per product
 * per 24h). ANY failure returns null/'' and the deterministic copy
 * proceeds. AI is an enhancer, never a dependency: it fails closed.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SM_AI_PROMPT_VERSION')) {
    // Bump when any AI instruction changes; part of every plan cache key.
    define('SM_AI_PROMPT_VERSION', 3);
}

if (!defined('SM_AI_DAILY_NEURON_BUDGET')) {
    // Conservative daily bound under the provider's free-plan hard cap
    // (10,000 neurons/day, no overage billing). The adapter stops calling
    // for the day at 2,000 so the account keeps headroom even if usage
    // accounting drifts; a 429 trips the budget immediately.
    define('SM_AI_DAILY_NEURON_BUDGET', 2000);
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
    return !preg_match('/(\b\d+\s?(?:hp|bhp|whp)\b|horsepower|torque\s+gain|\d+\s?%\s*(?:more|better|gain|increase)|guarantee|#1|best[- ]selling|most popular|miracle|free shipping|discount|sale)/i', $text);
}

/** True only when a provider credential is configured. */
function sm_ai_ready(): bool
{
    return sm_ai_endpoint() !== '' && sm_ai_token() !== '';
}

/** Daily neuron budget, fail-closed. */
function sm_ai_budget_ok(): bool
{
    $d = get_option('sm_ai_neurons', []);
    if (!is_array($d) || ($d['date'] ?? '') !== gmdate('Y-m-d')) {
        return true;
    }
    return (int) ($d['neurons'] ?? 0) < SM_AI_DAILY_NEURON_BUDGET;
}

function sm_ai_budget_add(int $n): void
{
    $today = gmdate('Y-m-d');
    $d     = get_option('sm_ai_neurons', []);
    if (!is_array($d) || ($d['date'] ?? '') !== $today) {
        $d = ['date' => $today, 'neurons' => 0];
    }
    $d['neurons'] = max(0, (int) $d['neurons'] + $n);
    update_option('sm_ai_neurons', $d, false);
}

/** Quota hit: stop calling for the rest of the day. */
function sm_ai_budget_trip(): void
{
    update_option('sm_ai_neurons', ['date' => gmdate('Y-m-d'), 'neurons' => SM_AI_DAILY_NEURON_BUDGET], false);
}

/** Last provider failure, for diagnostics (self-test detail, admin). */
function sm_ai_note_failure(string $why): void
{
    update_option('sm_ai_last_error', ['at' => gmdate('c'), 'why' => $why], false);
}

function sm_ai_last_error(): string
{
    $d = get_option('sm_ai_last_error', []);
    return is_array($d) ? (string) ($d['why'] ?? '') : '';
}

/**
 * Extract the decoded JSON object from a Workers AI chat completion.
 * Truncated or refused completions (finish_reason != "stop") are
 * rejected; result.response is only a fallback when choices are absent.
 */
function sm_ai_extract_json($body): ?array
{
    if (!is_array($body)) {
        return null;
    }
    $result = $body['result'] ?? null;
    if (!is_array($result)) {
        return null;
    }
    $content = '';
    if (isset($result['choices'][0]['message']['content'])) {
        if ((string) ($result['choices'][0]['finish_reason'] ?? 'stop') !== 'stop') {
            return null;
        }
        $content = (string) $result['choices'][0]['message']['content'];
    } elseif (isset($result['response'])) {
        $content = (string) $result['response'];
    }
    $content = trim($content);
    if ($content === '') {
        return null;
    }
    $j = json_decode($content, true);
    return is_array($j) ? $j : null;
}

/** Neurons billed to the account by a response (0 when not reported). */
function sm_ai_neurons_used($body): int
{
    if (!is_array($body)) {
        return 0;
    }
    return (int) ($body['result']['usage']['neurons'] ?? 0);
}

/**
 * One bounded provider call. Returns the decoded JSON object the model
 * produced, or null on ANY failure: missing credential, spent budget,
 * 429 (also trips the day's budget), network error, timeout, truncated
 * or unparseable completion.
 */
function sm_ai_call(string $task, array $facts, int $max_tokens = 300): ?array
{
    if (!sm_ai_ready() || !sm_ai_budget_ok()) {
        return null;
    }
    // Soft self-throttle, not a guarantee: reserve a conservative estimate
    // BEFORE the call so concurrent builds cannot both pass the check at
    // the bound, then correct to the reported usage afterwards. Failed
    // calls keep the reserve (the provider may have billed work anyway).
    // The provider's own free-plan hard cap remains the real stop.
    $reserve = 300;
    sm_ai_budget_add($reserve);
    // Provider latency from the store's region measures above 4s on cold
    // calls (observed cURL 28 at 4002ms); builds run in cron context, so a
    // 10s ceiling is safe and avoids burning the reserve on timeouts.
    $resp = wp_remote_post(sm_ai_endpoint(), [
        'timeout' => 10,
        'headers' => ['Authorization' => 'Bearer ' . sm_ai_token(), 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode([
            'messages'             => [
                ['role' => 'system', 'content' => $task],
                ['role' => 'user', 'content' => wp_json_encode($facts)],
            ],
            'max_tokens'           => $max_tokens,
            'response_format'      => ['type' => 'json_object'],
            'chat_template_kwargs' => ['enable_thinking' => false],
        ]),
    ]);
    if (is_wp_error($resp)) {
        sm_ai_note_failure('transport:' . $resp->get_error_message());
        return null;
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code === 429) {
        sm_ai_budget_trip();
        sm_ai_note_failure('http_429');
        return null;
    }
    $body = json_decode((string) wp_remote_retrieve_body($resp), true);
    // Reconcile the reserve whenever the provider reported usage - even on
    // a non-200 or an unusable completion, billed neurons must count.
    if (is_array($body) && sm_ai_neurons_used($body) > 0) {
        sm_ai_budget_add(sm_ai_neurons_used($body) - $reserve);
    }
    if ($code !== 200) {
        sm_ai_note_failure('http_' . $code);
        return null;
    }
    $out = sm_ai_extract_json($body);
    if ($out === null) {
        sm_ai_note_failure(is_array($body) ? 'unusable_completion' : 'bad_json');
    }
    return $out;
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
    $j = sm_ai_call(
        'You write one-line factual catalog captions for an auto parts store. Reply with JSON {"caption": "..."} only: max 12 words, what the part is for, no claims, no prices, no offers.',
        $facts,
        120
    );
    $text = trim((string) ($j['caption'] ?? ''));
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
 * AI grid selection for the rotation email: choose which products from the
 * uncovered candidate pool go into this email's grid. The deterministic
 * walk still owns coverage, cadence, no-repeat and eligibility - this only
 * ranks the CURRENT pool. Engagement signals are catalog nouns only
 * (product names the subscriber opened/clicked/purchased); no recipient
 * identity ever leaves the server. ANY failure returns null and the caller
 * falls back to the deterministic diverse pick.
 */
function sm_ai_rotation_select(array $products, int $count, array $engagement = []): ?array
{
    if (!sm_ai_ready() || $count < 1 || !$products) {
        return null;
    }
    $offered = [];
    $facts   = [];
    foreach (array_slice($products, 0, 60) as $p) {
        if (!($p instanceof WC_Product)) {
            continue;
        }
        $offered[] = (int) $p->get_id();
        $facts[]   = [
            'id'       => (int) $p->get_id(),
            'name'     => $p->get_name(),
            'category' => implode(', ', (array) wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names'])),
            'price'    => $p->get_price(),
        ];
    }
    if (count($offered) <= $count) {
        return null; // nothing meaningful to choose; caller shows them all
    }
    $ctx = ['products' => $facts, 'pick' => $count];
    $eng = [];
    foreach (['opened', 'clicked', 'purchased'] as $k) {
        $v = array_values(array_filter(array_map('strval', (array) ($engagement[$k] ?? []))));
        if ($v) {
            $eng[$k] = array_slice($v, 0, 8);
        }
    }
    if ($eng) {
        $ctx['customer_interest'] = $eng;
    }
    $cache = 'sm_ai_sel_v' . SM_AI_PROMPT_VERSION . '_' . md5(implode(',', $offered) . '|' . $count . '|' . md5(wp_json_encode($eng)));
    $hit   = get_transient($cache);
    if (is_array($hit)) {
        return $hit;
    }
    $j = sm_ai_call(
        'You choose the products for one marketing email grid for an auto parts store. Reply with JSON {"ids":[...]} only: pick exactly '
        . $count . ' product ids from the list that make one coherent, appealing email; vary product types and price points. When customer_interest is present, prefer products related to or a natural next step from those interests, and never pick items already purchased. Choose ids ONLY from the list. No claims, no offers, no discounts.',
        $ctx,
        150
    );
    if (!is_array($j)) {
        return null;
    }
    $ids  = [];
    $seen = [];
    foreach ((array) ($j['ids'] ?? []) as $raw) {
        $id = (int) $raw;
        if ($id > 0 && in_array($id, $offered, true) && !isset($seen[$id])) {
            $seen[$id] = true;
            $ids[]     = $id;
        }
        if (count($ids) >= $count) {
            break;
        }
    }
    if ($ids === []) {
        return null;
    }
    set_transient($cache, $ids, HOUR_IN_SECONDS);
    return $ids;
}

/**
 * Advisory rotation plan for the ongoing catalog walk (one email per
 * customer per day, user-picked cadence). Only theme labels and catalog
 * facts leave the server - never recipient identity, orders or behavior.
 *
 * The deterministic core (sm_rotation_pick) owns coverage, cadence and
 * no-repeat. This function may re-rank among the AVAILABLE theme keys
 * (themes that still have uncovered products this cycle) and supply copy;
 * every copy field passes sm_ai_claim_free + length caps. Cost guard: one
 * bounded call per build, counted against the daily neuron budget, result
 * cached per chosen theme per ISO week. ANY failure returns null and the
 * deterministic walk proceeds.
 */
function sm_ai_rotation_plan(array $themes, array $available_keys, string $chosen_key, array $products): ?array
{
    if (!sm_ai_ready()) {
        return null;
    }
    // Bind the exact input facts, not just ids: every fact fed to the
    // model (name, category, price) plus stock state must invalidate copy
    // composed from the old facts. Descriptions are never sent to the model.
    $facts_fp = [];
    foreach ($products as $p) {
        if ($p instanceof WC_Product) {
            $facts_fp[] = (int) $p->get_id() . ':' . md5($p->get_name() . '|' . (string) $p->get_price() . '|' . (string) $p->get_stock_status() . '|' . implode(',', (array) wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names'])));
        }
    }
    $cache = 'sm_ai_plan_v' . SM_AI_PROMPT_VERSION . '_' . md5($chosen_key . '|' . implode(',', $facts_fp));
    $hit   = get_transient($cache);
    if (is_array($hit)) {
        return $hit;
    }
    $avail = [];
    foreach ($available_keys as $k) {
        $avail[$k] = isset($themes[$k]['label']) ? (string) $themes[$k]['label'] : $k;
    }
    if ($avail === []) {
        return null;
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
    $j = sm_ai_call(
        'You plan the next email in an ongoing catalog walk for an auto parts store (one email per customer per day). Reply with JSON {"theme_key","subject","headline","intro"}: pick theme_key from the available_themes keys for one coherent email; subject <=70 chars, headline <=50 chars, intro <=200 chars. The products listed are the exact grid this email shows; the copy must fit them as a set. You may name a product or brand ONLY when it is in this list. Facts only: the parts are in stock and priced as listed. No sales claims, no offers, no superlatives.',
        ['available_themes' => $avail, 'products' => $facts],
        400
    );
    if (!is_array($j)) {
        return null;
    }
    $plan = [];
    $tk   = (string) ($j['theme_key'] ?? '');
    if ($tk !== '' && isset($avail[$tk])) {
        $plan['theme_key'] = $tk;
    }
    foreach (['subject' => 70, 'headline' => 50, 'intro' => 200] as $f => $max) {
        $v = trim((string) ($j[$f] ?? ''));
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

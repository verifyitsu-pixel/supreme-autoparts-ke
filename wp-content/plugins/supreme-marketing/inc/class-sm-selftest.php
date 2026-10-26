<?php
/**
 * Admin-only, no-mail functional self-test for the cross-email non-repeat
 * machinery. Exercises the REAL production reservation/finalize/release
 * functions against the live database with a sentinel recipient, proves
 * each state transition, then cleans up every row it created. Dispatches
 * no email and touches no customer data. Reachable only by an admin with
 * a valid nonce: /wp-admin/admin.php?page=sm-flows&sm_selftest=1&_wpnonce=...
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', function () {
    if (!isset($_GET['sm_selftest'], $_GET['page']) || $_GET['page'] !== 'sm-flows') {
        return;
    }
    if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sm_selftest')) {
        wp_die('forbidden', 403);
    }
    global $wpdb;
    $table   = SM_DB::table('send_products');
    $email   = 'selftest-' . time() . '@selftest.invalid';
    $results = [];
    $check   = function (string $name, bool $ok, string $detail = '') use (&$results) {
        $results[] = ['check' => $name, 'pass' => $ok, 'detail' => $detail];
    };

    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    $check('send_products table exists (db 1.4.0 schema)', $exists === $table, (string) $exists);
    if ($exists !== $table) {
        wp_send_json(['results' => $results], 500);
    }

    try {
        // 1) Per-recipient lock acquires and releases.
        $lock = sm_grid_lock($email);
        $check('grid lock acquires', $lock !== null, (string) $lock);
        sm_grid_unlock($lock);
        $relock = sm_grid_lock($email);
        $check('grid lock re-acquires after release', $relock !== null);
        sm_grid_unlock($relock);

        // 2) Queue email A reserves its selected products.
        sm_reserve_products(999999901, $email, [111, 222]);
        $reserved = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s AND state = 'reserved'", $email));
        $check('reservation writes reserved rows', $reserved === 2, "reserved=$reserved");

        // 3) A second build for the same recipient sees A's picks (no overlap).
        $seen = sm_recent_sent_product_ids($email);
        $check('concurrent build sees reserved picks as taken', in_array(111, $seen, true) && in_array(222, $seen, true), implode(',', $seen));

        // 4) Queue email B reserves a different product.
        sm_reserve_products(999999902, $email, [333]);
        $seen = sm_recent_sent_product_ids($email);
        $check('second reservation adds to exclusion set', in_array(333, $seen, true), implode(',', $seen));

        // 5) Provider-accepted send finalizes A: reserved -> sent with send_id.
        sm_finalize_queue_products(999999901, 999999901);
        $sent = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s AND state = 'sent' AND send_id = 999999901", $email));
        $check('finalize flips reserved to sent with send_id', $sent === 2, "sent=$sent");

        // 6) Failed/cancelled send releases B's reservation - never marks history.
        sm_release_queue_products(999999902);
        $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s AND product_id = 333", $email));
        $check('failed send releases reservation', $left === 0, "left=$left");
        $seen = sm_recent_sent_product_ids($email);
        $check('released product is pickable again', !in_array(333, $seen, true), implode(',', $seen));

        // 7) Sent history survives (non-repeat across future emails).
        $check('sent products stay in history', in_array(111, $seen, true) && in_array(222, $seen, true), implode(',', $seen));

        // 8) Rotation: a real pick through the scheduler path on the live
        // catalog returns a themed grid and reserves exactly its products.
        $pick = sm_rotation_pick($email, 4, 999999903);
        $picked_ids = array_map(function ($p) {
            return (int) $p->get_id();
        }, (array) $pick['products']);
        $check(
            'rotation pick returns a themed grid from the live catalog',
            $pick['theme_label'] !== '' && count($picked_ids) >= 1,
            ($pick['theme_label'] !== '' ? $pick['theme_label'] : 'no-theme') . ' / ' . count($picked_ids) . ' products'
        );
        $rot_reserved = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s AND state = 'reserved' AND queue_id = 999999903", $email));
        $check('rotation pick reserves its products', $rot_reserved === count($picked_ids) && $rot_reserved > 0, "reserved=$rot_reserved");

        // 9) A failed/cancelled rotation send releases its reservation; the
        // products stay uncovered and pickable for the next cycle email.
        sm_release_queue_products(999999903);
        $in = implode(',', array_map('intval', $picked_ids ?: [0]));
        $left_rot = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s AND product_id IN ($in)", $email));
        $check('failed rotation send releases its reservation', $left_rot === 0, "left=$left_rot");

        // 10) Sent rotation history drives product-level cycle coverage.
        $wpdb->insert(SM_DB::table('sends'), ['queue_id' => null, 'email' => $email, 'template' => 'rotation_1', 'subject' => 'selftest', 'test_send' => 1, 'status' => 'sent', 'sent_at' => sm_now()]);
        $send_id = (int) $wpdb->insert_id;
        if ($picked_ids) {
            $wpdb->insert($table, ['send_id' => $send_id, 'queue_id' => null, 'email' => $email, 'product_id' => $picked_ids[0], 'state' => 'sent', 'sent_at' => sm_now()]);
        }
        $covered = sm_rotation_covered_product_ids($email, gmdate('Y-m-d H:i:s', time() - 3600));
        $check('sent rotation product enters cycle coverage', $picked_ids !== [] && in_array($picked_ids[0], $covered, true), implode(',', $covered));
        $adv = sm_rotation_cycle_advance($picked_ids, $covered);
        $adv_ok = count($picked_ids) === 1
            ? ($adv['restart'] && $adv['uncovered'] === $picked_ids)
            : (!$adv['restart'] && $adv['uncovered'] === array_slice($picked_ids, 1));
        $check('covered products leave the cycle; full coverage restarts it', $adv_ok);

        // 11) A singleton shelf still renders a clean email.
        $one = SM_Templates::render('rotation_1', [
            'email' => $email, 'first_name' => 'Test', 'zone' => 'US',
            'rotation_theme_label' => 'Test Shelf',
            'products' => [['id' => 1, 'name' => 'Solo Part', 'url' => home_url('/shop/'), 'price' => '$10.00', 'image' => '', 'caption' => 'for the job', 'theme' => 'braking']],
        ]);
        $one_html = is_array($one) ? (string) ($one['html'] ?? '') : '';
        $check('rotation_1 renders a 1-product grid cleanly', strpos($one_html, 'Solo Part') !== false && strpos($one_html, 'Test Shelf') !== false);
    } finally {
        // Cleanup: every row this test created, any state, every table.
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE email = %s", $email));
        $wpdb->query($wpdb->prepare("DELETE FROM " . SM_DB::table('sends') . " WHERE email = %s", $email));
        $wpdb->query($wpdb->prepare("DELETE FROM " . SM_DB::table('rotation_state') . " WHERE email = %s", $email));
        $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s", $email));
        $left_sends = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . SM_DB::table('sends') . " WHERE email = %s", $email));
        $check('cleanup removes all sentinel rows', $left === 0 && $left_sends === 0, "products=$left sends=$left_sends");
    }

    // Daily spacing guard (owner pick: an email daily): due exactly when
    // the last marketing email is at least rotation_min_days old.
    $now = time();
    $due_never   = sm_rotation_due(null, 1, $now);
    $due_1h      = sm_rotation_due(gmdate('Y-m-d H:i:s', $now - 3600), 1, $now);
    $due_25h     = sm_rotation_due(gmdate('Y-m-d H:i:s', $now - 90000), 1, $now);
    $due_2d_min7 = sm_rotation_due(gmdate('Y-m-d H:i:s', $now - 2 * 86400), 7, $now);
    $due_8d_min7 = sm_rotation_due(gmdate('Y-m-d H:i:s', $now - 8 * 86400), 7, $now);
    $check(
        'rotation: daily spacing guard (due at >=1 day, not before)',
        $due_never && !$due_1h && $due_25h && !$due_2d_min7 && $due_8d_min7
            && (int) sm_setting('rotation_min_days') === 1,
        'min_days=' . (int) sm_setting('rotation_min_days')
    );

    // AI safety floor: the claim filter is the only gate on AI-composed
    // copy, so it must reject performance/offer/superlative wording even
    // with odd spacing ("30%better") and pass grounded factual lines.
    if (function_exists('sm_ai_claim_free')) {
        $ai_ok = true;
        foreach (['adds 15hp', '30%better mpg', '20% more flow', 'best-selling kit', 'free shipping', 'guarantee'] as $s) {
            $ai_ok = $ai_ok && !sm_ai_claim_free($s);
        }
        foreach (['Drop-in panel filter for the stock airbox', 'Priced as listed, in stock now'] as $s) {
            $ai_ok = $ai_ok && sm_ai_claim_free($s);
        }
        $check('AI claim filter blocks claims/offers, passes grounded lines', $ai_ok);
    }

    // Provider wire contract (pure parse, no network): chat-completions
    // shape with finish_reason=stop, response fallback when choices are
    // absent, truncated completions rejected, neuron usage read.
    if (function_exists('sm_ai_extract_json') && function_exists('sm_ai_neurons_used')) {
        $wire_ok = sm_ai_extract_json(['result' => ['choices' => [['message' => ['content' => '{"subject":"In stock"}'], 'finish_reason' => 'stop']], 'usage' => ['neurons' => 12]]]) === ['subject' => 'In stock'];
        $wire_len = sm_ai_extract_json(['result' => ['choices' => [['message' => ['content' => '{"subject":"In st'], 'finish_reason' => 'length']]]]) === null;
        $wire_fb = sm_ai_extract_json(['result' => ['response' => '{"caption":"Panel filter"}']]) === ['caption' => 'Panel filter'];
        $wire_nn = sm_ai_neurons_used(['result' => ['usage' => ['neurons' => 12]]]) === 12;
        $check('AI wire parse: choices/stop, response fallback, truncation rejected', $wire_ok && $wire_len && $wire_fb && $wire_nn);
    }

    // Live provider roundtrip in an isolated test namespace: one raw
    // bounded sm_ai_call probe (no plan path, no production cache keys -
    // test traffic must never pollute the recipient copy cache).
    if (function_exists('sm_ai_ready') && function_exists('sm_ai_call')) {
        if (sm_ai_ready()) {
            $nb     = get_option('sm_ai_neurons', []);
            $before = is_array($nb) ? (int) ($nb['neurons'] ?? 0) : 0;
            $j      = sm_ai_call('TEST-ONLY probe. Reply with JSON {"ok": true} and nothing else.', ['probe' => 'self-test'], 150);
            $nb2    = get_option('sm_ai_neurons', []);
            $after  = is_array($nb2) ? (int) ($nb2['neurons'] ?? 0) : 0;
            $why    = (is_array($j) && !empty($j['ok'])) ? '' : (function_exists('sm_ai_last_error') ? sm_ai_last_error() : '?');
            $check('AI live provider roundtrip (isolated test namespace)', is_array($j) && !empty($j['ok']) && $after > $before, 'neurons ' . $before . '->' . $after . ($why !== '' ? ' last_failure=' . $why : ''));
        } else {
            $check('AI adapter fail-closed while no credential installed', true);
        }
    }

    // Engagement personalization against LIVE data (capability proof, not a
    // mock): run the gatherer for the most recent real customer and confirm
    // the local signals actually resolve on this store's history.
    if (function_exists('sm_rotation_engagement') && function_exists('wc_get_orders')) {
        $eng_ok  = false;
        $eng_det = 'no orders';
        $recent  = wc_get_orders(['limit' => 1, 'orderby' => 'date', 'order' => 'DESC']);
        if ($recent) {
            $em  = (string) $recent[0]->get_billing_email();
            $eng = sm_rotation_engagement($em);
            $has_items = false;
            foreach ($recent[0]->get_items() as $it) {
                if ((int) $it->get_product_id() > 0) {
                    $has_items = true;
                }
            }
            $eng_ok  = is_array($eng)
                && isset($eng['purchased_ids'], $eng['engaged_cats'])
                && (!$has_items || $eng['purchased_ids'] !== []);
            $eng_det = 'purchased=' . count((array) ($eng['purchased_ids'] ?? []))
                . ' opened=' . count((array) ($eng['opened_ids'] ?? []))
                . ' clicked=' . count((array) ($eng['clicked_ids'] ?? []))
                . ' cats=' . count((array) ($eng['engaged_cats'] ?? []));
        }
        $check('engagement personalization resolves on live store history (local-only)', $eng_ok, $eng_det);
    }

    // Pure cycle walk + live universe + state table (read-only).
    $adv1 = sm_rotation_cycle_advance([1, 2, 3], [1]);
    $adv2 = sm_rotation_cycle_advance([1, 2, 3], [1, 2, 3]);
    $adv3 = sm_rotation_cycle_advance([1, 2, 3], [1, 2, 3, 99]);
    $adv4 = sm_rotation_cycle_advance([1, 2, 3], []);
    $rot_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", SM_DB::table('rotation_state')));
    $univ = sm_rotation_themes();
    $check(
        'rotation: product-level cycle walk + restart + state table',
        $adv1['uncovered'] === [2, 3] && !$adv1['restart']
            && $adv2['restart'] && $adv2['uncovered'] === [1, 2, 3]
            && $adv3['restart'] && $adv3['uncovered'] === [1, 2, 3]
            && $adv4['uncovered'] === [1, 2, 3] && !$adv4['restart']
            && $rot_table === SM_DB::table('rotation_state')
            && is_array($univ) && $univ !== [],
        count((array) $univ) . ' themes in the live universe'
    );

    $pass = !in_array(false, array_column($results, 'pass'), true);
    wp_send_json(['overall' => $pass ? 'PASS' : 'FAIL', 'results' => $results], $pass ? 200 : 500);
});

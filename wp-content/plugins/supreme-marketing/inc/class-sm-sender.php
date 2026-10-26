<?php
/**
 * Sender: turns a queue row into a delivered email.
 *
 * - Master switch + test mode enforced here (test mode reroutes everything to test_recipients).
 * - Audience enforcement: marketing templates need an active subscriber; service templates
 *   need an address that has not unsubscribed.
 * - Send-time cancellation: abandonment flows die if an order landed since scheduling;
 *   reorder reminders die if the product was reordered.
 * - Click/open tracking wrapped in; List-Unsubscribe + one-click headers on every email.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Sender
{
    public static function init(): void
    {
        // nothing persistent; processing is driven by SM_Queue cron
    }

    public static function send_queued(array $row): void
    {
        global $wpdb;
        $q = SM_DB::table('queue');
        $s = SM_DB::table('sends');

        $email = strtolower((string) $row['email']);
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $sub = SM_Subscribers::get_by_email($email);
        $is_marketing = in_array($row['template'], SM_Templates::marketing_templates(), true);

        // Audience gate.
        if ($is_marketing && !SM_Subscribers::is_marketable($sub)) {
            self::mark($row['id'], 'cancelled', 'not_opted_in');
            return;
        }
        if (!$is_marketing && !SM_Subscribers::is_contactable($sub)) {
            self::mark($row['id'], 'cancelled', 'unsubscribed');
            return;
        }

        // Send-time cancellation rules.
        if (self::should_cancel($row, $payload, $email)) {
            return; // should_cancel marks the row itself
        }

        // Build template context.
        $ctx = array_merge([
            'email'      => $email,
            'first_name' => (string) ($sub['first_name'] ?? ($payload['first_name'] ?? '')),
        ], $payload);
        unset($ctx['_refresh_products']);
        $fresh_product_ids = (array) ($ctx['_fresh_product_ids'] ?? []);
        unset($ctx['_fresh_product_ids']);

        if (!empty($payload['_refresh_products']) || empty($ctx['products'])) {
            $ctx['products'] = self::fresh_products($row['template'], $sub, (int) $row['id']);
            $fresh_ids = [];
            foreach ((array) $ctx['products'] as $pl) {
                $pid = (int) (is_array($pl) ? ($pl['id'] ?? 0) : 0);
                if ($pid > 0) {
                    $fresh_ids[] = $pid;
                }
            }
            $ctx['_fresh_product_ids'] = $fresh_ids;
        }

        // Region variant + personal welcome code resolved at send time.
        $ctx['zone'] = sm_subscriber_zone($sub);
        if (in_array($row['template'], ['welcome_1', 'welcome_5'], true)) {
            $wc = SM_Welcome::active_code_for_email($email);
            if ($wc) {
                $ctx['welcome_code'] = $wc['code'];
                $ctx['welcome_expires'] = $wc['expires'] !== '' ? gmdate('j M Y', strtotime($wc['expires'])) : '';
            }
        }

        $rendered = SM_Templates::render((string) $row['template'], $ctx);
        if ($rendered === null) {
            sm_release_queue_products((int) $row['id']);
            self::mark($row['id'], 'failed', 'unknown_template');
            return;
        }

        // Recipients: test mode reroutes to the test inbox(es).
        $test = sm_test_mode();
        $recipients = [$email];
        $subject = $rendered['subject'];
        if ($test) {
            $recipients = sm_test_recipients();
            if (!$recipients) {
                sm_release_queue_products((int) $row['id']);
                self::mark($row['id'], 'failed', 'no_test_recipients');
                return;
            }
        }

        // Create the send record first so tracking URLs can reference it.
        $wpdb->insert($s, [
            'queue_id'      => (int) $row['id'],
            'campaign_id'   => $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null,
            'subscriber_id' => $sub ? (int) $sub['id'] : null,
            'email'         => $email,
            'template'      => (string) $row['template'],
            'subject'       => $rendered['subject'],
            'test_send'     => $test ? 1 : 0,
            'status'        => 'sending',
            'sent_at'       => sm_now(),
        ]);
        $send_id = (int) $wpdb->insert_id;

        $html = self::instrument($rendered['html'], $send_id);
        $unsub = sm_unsubscribe_url($email);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . sm_from_name() . ' <' . sm_from_email() . '>',
            'List-Unsubscribe: <' . $unsub . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ];
        if ($is_marketing) {
            $headers[] = 'Precedence: bulk';
        }

        $ok = false;
        $last = '';
        try {
            $sent = wp_mail($recipients, $subject, $html, $headers);
            $ok = (bool) $sent;
            if (!$ok) {
                $last = 'wp_mail_false';
            }
        } catch (Throwable $e) {
            $last = substr($e->getMessage(), 0, 240);
        }

        if ($ok) {
            $wpdb->update($s, ['status' => 'sent'], ['id' => $send_id]);
            // Provider accepted the mail: reserved products become permanent
            // history for this recipient. Rows only exist for showcase grids
            // (flow fresh picks + campaign grids) - cart/browse personal items
            // were never reserved, so they stay out of history.
            sm_finalize_queue_products((int) $row['id'], $send_id);
            self::mark($row['id'], 'sent', '', sm_now());
            do_action('sm_sent', $row, $send_id);
        } else {
            sm_release_queue_products((int) $row['id']);
            $attempts = (int) $row['attempts'] + 1;
            $wpdb->update($s, ['status' => 'failed'], ['id' => $send_id]);
            if ($attempts < 3) {
                $wpdb->update($q, [
                    'attempts'   => $attempts,
                    'last_error' => $last,
                    'send_at'    => sm_dt(time() + 30 * MINUTE_IN_SECONDS),
                ], ['id' => (int) $row['id']]);
            } else {
                self::mark($row['id'], 'failed', $last);
            }
        }
    }

    /** Cancellation checks that depend on live store state. Returns true when handled+cancelled. */
    private static function should_cancel(array $row, array $payload, string $email): bool
    {
        $flow = (string) ($row['flow'] ?? '');
        if (in_array($flow, ['abandoned_cart', 'abandoned_checkout', 'browse_abandonment'], true)) {
            $since = (string) ($payload['no_order_since'] ?? $row['created_at']);
            if (self::ordered_since($email, $since)) {
                self::mark($row['id'], 'cancelled', 'order_placed');
                return true;
            }
        }
        if ($row['template'] === 'reorder_1' && !empty($payload['product_id'])) {
            $since = (string) ($payload['no_order_since'] ?? $row['created_at']);
            if (self::ordered_product_since($email, (int) $payload['product_id'], $since)) {
                self::mark($row['id'], 'cancelled', 'already_reordered');
                return true;
            }
        }
        return false;
    }

    public static function ordered_since(string $email, string $since): bool
    {
        if (!function_exists('wc_get_orders')) {
            return false;
        }
        $orders = wc_get_orders([
            'billing_email' => $email,
            'status'        => ['wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending'],
            'date_created'  => '>' . strtotime($since),
            'limit'         => 1,
            'return'        => 'ids',
        ]);
        return !empty($orders);
    }

    public static function ordered_product_since(string $email, int $product_id, string $since): bool
    {
        if (!function_exists('wc_get_orders')) {
            return false;
        }
        $orders = wc_get_orders([
            'billing_email' => $email,
            'status'        => ['wc-processing', 'wc-completed', 'wc-on-hold'],
            'date_created'  => '>' . strtotime($since),
            'limit'         => 20,
            'return'        => 'objects',
        ]);
        foreach ((array) $orders as $o) {
            foreach ($o->get_items() as $item) {
                if ((int) $item->get_product_id() === $product_id) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Payload array for a list of WC_Product, priced for the zone. */
    public static function payload_for(array $products, string $zone): array
    {
        sm_reset_caption_dedup(); // per-grid caption uniqueness
        $out = [];
        foreach ($products as $p) {
            if ($p instanceof WC_Product) {
                $out[] = sm_product_payload($p, $zone);
            }
        }
        return $out;
    }

    /** Fresh product picks for templates that re-personalize at send time. */
    private static function fresh_products(string $template, ?array $sub, int $queue_id = 0): array
    {
        $zone = sm_subscriber_zone($sub);
        $count = (int) sm_setting('recommend_count');
        $fetch = $count + 60; // over-fetch: eligibility filter + variety pass + cross-email non-repeat need room
        $products = [];
        if ($sub && in_array($template, ['winback_1', 'winback_2'], true)) {
            $products = SM_Recommend::for_subscriber($sub, $fetch);
        } elseif ($sub && in_array($template, ['welcome_4', 'welcome_5'], true)) {
            $products = SM_Recommend::best_sellers($fetch);
        }
        if (!$products) {
            $products = SM_Recommend::best_sellers($fetch);
        }
        $products = sm_marketing_eligible($products); // owner-banned items never featured
        // Cross-email non-repeat: only products never featured in (or already
        // reserved for) mail this recipient received. Selection and
        // reservation run under a per-recipient lock so a concurrent email
        // build cannot pick the same products. Never silently repeats: when
        // the fresh pool runs short the grid shrinks, and when it is empty
        // the template renders its no-grid fallback copy instead.
        $email = $sub ? (string) ($sub['email'] ?? '') : '';
        $lock = sm_grid_lock($email);
        try {
            if ($email !== '') {
                $recent = sm_recent_sent_product_ids($email);
                if ($recent) {
                    $products = array_values(array_filter($products, function ($p) use ($recent) {
                        return !in_array((int) $p->get_id(), $recent, true);
                    }));
                }
            }
            $products = sm_diverse_products($products, $count);
            if ($queue_id > 0 && $email !== '' && $products) {
                sm_reserve_products($queue_id, $email, array_map(function ($p) {
                    return (int) $p->get_id();
                }, $products));
            }
        } finally {
            sm_grid_unlock($lock);
        }
        return self::payload_for($products, $zone);
    }

    /** Wrap click-through links + append the open pixel. */
    private static function instrument(string $html, int $send_id): string
    {
        global $wpdb;
        $tracked = preg_replace_callback(
            '/href="(https?:\/\/[^"]+)"/i',
            static function ($m) use ($send_id, $wpdb) {
                $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                if (strpos($url, '/sm/unsubscribe/') !== false || strpos($url, '/sm/preferences/') !== false || strpos($url, '/sm/confirm/') !== false) {
                    return $m[0]; // consent links stay direct
                }
                $wpdb->insert(SM_DB::table('links'), [
                    'send_id'    => $send_id,
                    'url'        => $url,
                    'created_at' => sm_now(),
                ]);
                $lid = (int) $wpdb->insert_id;
                if ($lid <= 0) {
                    return $m[0];
                }
                $sig = sm_token($lid . '|' . $send_id, 'click');
                return 'href="' . esc_url(home_url('/sm/c/' . $lid . '/' . $send_id . '/' . $sig . '/')) . '"';
            },
            $html
        );
        $sig = sm_token((string) $send_id, 'open');
        $pixel = '<img src="' . esc_url(home_url('/sm/o/' . $send_id . '/' . $sig . '.gif')) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;" />';
        return str_replace('</body>', $pixel . '</body>', (string) $tracked);
    }

    private static function mark(int|string $queue_id, string $status, string $error = '', ?string $sent_at = null): void
    {
        global $wpdb;
        $queue_id = (int) $queue_id;
        $data = ['status' => $status, 'last_error' => $error];
        if ($sent_at !== null) {
            $data['sent_at'] = $sent_at;
        }
        $wpdb->update(SM_DB::table('queue'), $data, ['id' => $queue_id]);
    }
}

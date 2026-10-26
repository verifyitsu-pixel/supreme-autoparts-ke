<?php
/**
 * Campaigns: one-off sends (newsletter, product/category adverts, offers with
 * promo codes, bundles, personalized cross-sell) to a segment.
 * Always two-step: create as draft, approve to enqueue. Offer campaigns get a
 * real WooCommerce coupon generated at approval time (one per campaign/segment).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Campaigns
{
    /** type => template mapping. */
    public static function types(): array
    {
        return [
            'newsletter'  => ['template' => 'newsletter_1',  'label' => 'Newsletter (same email to the whole segment)'],
            'new_product' => ['template' => 'new_product_1', 'label' => 'Product advert (picked products)'],
            'category'    => ['template' => 'category_1',    'label' => 'Category spotlight (products from one category)'],
            'offer'       => ['template' => 'offer_1',       'label' => 'Offer / promo (generates a discount code)'],
            'bundle'      => ['template' => 'new_product_1', 'label' => 'Bundle / collection (picked products together)'],
            'cross_sell'  => ['template' => 'cross_sell_1',  'label' => 'Personalized picks (per-recipient recommendations)'],
        ];
    }

    public static function init(): void
    {
        // admin actions live in SM_Admin
    }

    public static function create(array $data): int
    {
        global $wpdb;
        $now = sm_now();
        $type = sanitize_key((string) ($data['type'] ?? 'newsletter'));
        $template = sanitize_key((string) ($data['template'] ?? ''));
        if ($template === '' || !in_array($template, SM_Templates::keys(), true)) {
            $template = self::types()[$type]['template'] ?? 'newsletter_1';
        }
        $wpdb->insert(SM_DB::table('campaigns'), [
            'name'         => sanitize_text_field((string) ($data['name'] ?? 'Untitled campaign')),
            'type'         => $type,
            'template'     => $template,
            'subject'      => sanitize_text_field((string) ($data['subject'] ?? '')),
            'heading'      => sanitize_text_field((string) ($data['heading'] ?? '')),
            'intro'        => wp_kses_post((string) ($data['intro'] ?? '')),
            'segment_key'  => sanitize_text_field((string) ($data['segment_key'] ?? 'all_active')),
            'product_ids'  => wp_json_encode(array_map('intval', (array) ($data['product_ids'] ?? []))),
            'category'     => sanitize_text_field((string) ($data['category'] ?? '')),
            'coupon_code'  => strtoupper(sanitize_text_field((string) ($data['coupon_code'] ?? ''))),
            'coupon_type'  => in_array(($data['coupon_type'] ?? ''), ['percent', 'fixed_cart'], true) ? $data['coupon_type'] : 'percent',
            'coupon_amount'=> isset($data['coupon_amount']) && $data['coupon_amount'] !== '' ? (float) $data['coupon_amount'] : null,
            'coupon_days'  => max(1, (int) ($data['coupon_days'] ?? 14)),
            'coupon_free_shipping' => !empty($data['coupon_free_shipping']) ? 1 : 0,
            'status'       => in_array(($data['status'] ?? 'draft'), ['draft', 'approved'], true) ? $data['status'] : 'draft',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'created_by'   => get_current_user_id() ?: null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SM_DB::table('campaigns') . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Ensure the campaign's WooCommerce coupon exists; create it on approval.
     * Returns ['code'=>, 'label'=>, 'expires'=>, 'free_shipping'=>bool] or null when not an offer.
     */
    public static function ensure_coupon(array $c): ?array
    {
        if ($c['type'] !== 'offer' || !class_exists('WC_Coupon')) {
            return null;
        }
        $amount = (float) ($c['coupon_amount'] ?? 0);
        if ($amount <= 0) {
            return null;
        }
        $code = (string) $c['coupon_code'];
        if ($code === '') {
            $code = 'SUP' . strtoupper(wp_generate_password(6, false, false));
        }
        $type = (string) ($c['coupon_type'] ?: 'percent');
        $days = max(1, (int) $c['coupon_days']);
        $free_ship = !empty($c['coupon_free_shipping']);

        $id = (int) wc_get_coupon_id_by_code($code);
        if ($id <= 0) {
            $coupon = new WC_Coupon();
            $coupon->set_code($code);
            $coupon->set_description('Marketing campaign #' . (int) $c['id'] . ': ' . (string) $c['name']);
            $coupon->set_discount_type($type);
            $coupon->set_amount($amount);
            $coupon->set_individual_use(true);
            $coupon->set_usage_limit(0);
            $coupon->set_usage_limit_per_user(1);
            $coupon->set_free_shipping($free_ship);
            $coupon->set_date_expires(time() + $days * DAY_IN_SECONDS);
            // Offers never discount the car-service booking-fee deposit.
            $fee = (int) get_option('sa_svc_fee_product', 0);
            if ($fee > 0) {
                $coupon->set_excluded_product_ids([$fee]);
            }
            $coupon->save();
            update_post_meta((int) $coupon->get_id(), '_sm_campaign_coupon', (int) $c['id']);
        }

        $label = $type === 'percent'
            ? rtrim(rtrim(number_format($amount, 2), '0'), '.') . '% off'
            : sm_money($amount, 'US') . ' off';
        if ($free_ship) {
            $label .= ' + free shipping';
        }
        $expires = gmdate('j M Y', time() + $days * DAY_IN_SECONDS);
        return ['code' => $code, 'label' => $label, 'expires' => $expires, 'free_shipping' => $free_ship];
    }

    /** Products for a category spotlight: best sellers inside the chosen category. */
    private static function category_products(string $slug, int $count): array
    {
        if ($slug === '' || !function_exists('wc_get_products')) {
            return [];
        }
        return (array) wc_get_products([
            'status'       => 'publish',
            'stock_status' => 'instock',
            'category'     => [$slug],
            'limit'        => $count,
            'orderby'      => 'popularity',
            'order'        => 'DESC',
        ]);
    }

    /**
     * Campaign grid selection: category-variety pass over the algorithm's
     * scored list, skipping products this recipient was already emailed in
     * the last 45 days; on catalog exhaustion the full scored list fills in.
     * Score order is preserved inside each segment - algorithm untouched.
     */
    private static function fresh_grid(array $products, string $email): array
    {
        $count = (int) sm_setting('recommend_count');
        $products = sm_marketing_eligible($products); // owner-banned items never featured
        // Full-history exclusion, no silent repeats: short fresh pool shrinks
        // the grid, empty pool means no grid at all.
        $recent = sm_recent_sent_product_ids($email);
        if ($recent) {
            $products = array_values(array_filter($products, function ($p) use ($recent) {
                return !($p instanceof WC_Product) || !in_array((int) $p->get_id(), $recent, true);
            }));
        }
        return sm_diverse_products($products, $count);
    }

    /** Approve and enqueue to the segment. Returns the number of queued emails. */
    public static function approve_and_enqueue(int $id, int $user_id): int
    {
        global $wpdb;
        $c = self::get($id);
        if (!$c || in_array($c['status'], ['sending', 'done'], true)) {
            return 0;
        }
        $coupon = self::ensure_coupon($c);
        if ($c['type'] === 'offer' && $coupon === null) {
            return -1; // offer without a discount amount: fix the draft first
        }
        $wpdb->update(SM_DB::table('campaigns'), [
            'status'      => 'approved',
            'coupon_code' => $coupon ? $coupon['code'] : $c['coupon_code'],
            'approved_by' => $user_id,
            'approved_at' => sm_now(),
            'updated_at'  => sm_now(),
        ], ['id' => $id]);

        $send_at = $c['scheduled_at'] && strtotime((string) $c['scheduled_at']) > sm_ts()
            ? (string) $c['scheduled_at']
            : sm_now();

        // Category context for spotlights and category-scoped offers.
        $cat_ctx = null;
        $cat_slug = (string) ($c['category'] ?? '');
        if ($cat_slug !== '') {
            $term = get_term_by('slug', $cat_slug, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $cat_ctx = ['name' => $term->name, 'url' => get_term_link($term)];
            }
        }

        $queued = 0;
        $recipients = SM_Segments::resolve((string) $c['segment_key']);
        $product_ids = array_filter(array_map('intval', (array) json_decode((string) ($c['product_ids'] ?? '[]'), true)));
        $personalize = $c['template'] === 'cross_sell_1';

        foreach ($recipients as $sub) {
            // Serialize selection per recipient: a flow email sending at the
            // same moment sees these reservations and never picks the same
            // products for this person.
            $grid_lock = sm_grid_lock((string) ($sub['email'] ?? ''));
            $zone = sm_subscriber_zone($sub);
            $products = [];
            if ($personalize) {
                $products = SM_Sender::payload_for(self::fresh_grid(SM_Recommend::for_subscriber($sub, (int) sm_setting('recommend_count') + 60), (string) ($sub['email'] ?? '')), $zone);
            } elseif ($c['type'] === 'category') {
                $products = SM_Sender::payload_for(self::category_products($cat_slug, (int) sm_setting('recommend_count')), $zone);
            } else {
                foreach ($product_ids as $pid) {
                    $p = wc_get_product($pid);
                    if ($p) {
                        $products[] = sm_product_payload($p, $zone);
                    }
                }
                if (!$products && $cat_slug !== '') {
                    $products = SM_Sender::payload_for(self::category_products($cat_slug, (int) sm_setting('recommend_count')), $zone);
                }
                if (!$products) {
                    $products = SM_Sender::payload_for(self::fresh_grid(SM_Recommend::best_sellers((int) sm_setting('recommend_count') + 60), (string) ($sub['email'] ?? '')), $zone);
                }
            }
            if (($personalize || $c['type'] === 'category') && !$products) {
                sm_grid_unlock($grid_lock);
                continue; // nothing relevant to send this person
            }
            $ok = SM_Queue::add([
                'subscriber_id' => (int) $sub['id'],
                'email'         => $sub['email'],
                'flow'          => 'campaign',
                'step'          => $c['template'],
                'template'      => $c['template'],
                'campaign_id'   => $id,
                'payload'       => [
                    'first_name' => $sub['first_name'] ?? '',
                    'zone'       => $zone,
                    'products'   => $products,
                    'category'   => $cat_ctx,
                    'coupon'     => $coupon,
                    'campaign'   => [
                        'subject' => $c['subject'],
                        'heading' => $c['heading'],
                        'intro'   => wp_strip_all_tags((string) $c['intro']),
                    ],
                ],
                'send_at'       => $send_at,
                'dedupe_key'    => 'campaign|' . $id . '|' . $sub['email'],
            ]);
            if ($ok) {
                $qid = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM ' . SM_DB::table('queue') . ' WHERE dedupe_key = %s',
                    'campaign|' . $id . '|' . $sub['email']
                ));
                $grid_ids = [];
                foreach ((array) $products as $pl) {
                    $pid = (int) (is_array($pl) ? ($pl['id'] ?? 0) : 0);
                    if ($pid > 0) {
                        $grid_ids[] = $pid;
                    }
                }
                if ($qid > 0 && $grid_ids) {
                    sm_reserve_products($qid, (string) $sub['email'], $grid_ids);
                }
                $queued++;
            }
            sm_grid_unlock($grid_lock);
        }
        $wpdb->update(SM_DB::table('campaigns'), [
            'status'     => 'sending',
            'stats'      => wp_json_encode(['queued' => $queued, 'queued_at' => sm_now()]),
            'updated_at' => sm_now(),
        ], ['id' => $id]);
        return $queued;
    }

    /** Mark done when every queue row for the campaign resolved. */
    public static function maybe_complete(int $id): void
    {
        global $wpdb;
        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . SM_DB::table('queue') . " WHERE campaign_id = %d AND status = 'pending'",
            $id
        ));
        if ($pending === 0) {
            $sent = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . SM_DB::table('sends') . " WHERE campaign_id = %d AND status = 'sent'",
                $id
            ));
            $wpdb->update(SM_DB::table('campaigns'), [
                'status'     => 'done',
                'stats'      => wp_json_encode(['sent' => $sent, 'completed_at' => sm_now()]),
                'updated_at' => sm_now(),
            ], ['id' => $id, 'status' => 'sending']);
        }
    }

    public static function all(int $limit = 50): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SM_DB::table('campaigns') . ' ORDER BY id DESC LIMIT %d',
            $limit
        ), ARRAY_A);
    }
}

<?php
/**
 * Flow definitions: automated sequences with steps, delays and audiences.
 *
 * audience 'marketing' = only active (opted-in) subscribers.
 * audience 'service'   = soft opt-in: people with a live cart/checkout/order who
 *                        have not unsubscribed (legitimate-interest transactional mail).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Flows
{
    public static function definitions(): array
    {
        $h = HOUR_IN_SECONDS;
        $d = DAY_IN_SECONDS;
        return [
            'welcome' => [
                'label'    => 'Welcome series',
                'audience' => 'marketing',
                'trigger'  => 'Someone joins the email list on the site or opts in at checkout.',
                'steps'    => [
                    ['key' => 'welcome_1', 'delay' => 0,       'note' => 'Immediately: welcome, what we do, first-order offer'],
                    ['key' => 'welcome_2', 'delay' => 1 * $d,  'note' => 'Day 1: finding the right part (part numbers, brands, fitment)'],
                    ['key' => 'welcome_3', 'delay' => 3 * $d,  'note' => 'Day 3: KE - garage servicing & roadside; US - genuine vs aftermarket'],
                    ['key' => 'welcome_4', 'delay' => 5 * $d,  'note' => 'Day 5: store tour - categories, My Garage, tracking'],
                    ['key' => 'welcome_5', 'delay' => 7 * $d,  'note' => 'Day 7: first-order path with best sellers'],
                ],
            ],
            'abandoned_cart' => [
                'label'    => 'Abandoned cart',
                'audience' => 'service',
                'trigger'  => 'Cart has items and an email on file, no order placed.',
                'steps'    => [
                    ['key' => 'cart_1', 'delay' => 1 * $h,  'note' => 'After 1 hour: your cart is waiting'],
                    ['key' => 'cart_2', 'delay' => 24 * $h, 'note' => 'After 24 hours: fitment help + trust points + cart'],
                    ['key' => 'cart_3', 'delay' => 72 * $h, 'note' => 'After 72 hours: final reminder'],
                ],
            ],
            'abandoned_checkout' => [
                'label'    => 'Abandoned checkout',
                'audience' => 'service',
                'trigger'  => 'Email entered at checkout, payment not completed.',
                'steps'    => [
                    ['key' => 'checkout_1', 'delay' => 2 * $h,  'note' => 'After 2 hours: finish checkout'],
                    ['key' => 'checkout_2', 'delay' => 24 * $h, 'note' => 'After 24 hours: offer help'],
                ],
            ],
            'browse_abandonment' => [
                'label'    => 'Browse abandonment',
                'audience' => 'marketing',
                'trigger'  => 'Opted-in customer views products but does not add to cart (max one email per week).',
                'steps'    => [
                    ['key' => 'browse_1', 'delay' => 20 * $h, 'note' => 'Next day: the parts they viewed + fitment help'],
                ],
            ],
            'post_purchase' => [
                'label'    => 'Post-purchase',
                'audience' => 'service',
                'trigger'  => 'Order is paid/complete.',
                'steps'    => [
                    ['key' => 'post_1', 'delay' => 2 * $d,  'note' => 'Day 2: delivery/fitting notes (KE: free fitting at the garage)'],
                    ['key' => 'post_2', 'delay' => 7 * $d,  'note' => 'Day 7: everything OK? support check-in'],
                    ['key' => 'post_3', 'delay' => 14 * $d, 'note' => 'Day 14: review request'],
                ],
            ],
            'reorder_reminder' => [
                'label'    => 'Reorder reminder',
                'audience' => 'marketing',
                'trigger'  => 'Set days before a previously bought consumable (oil, filters, wipers, brake pads) would typically need replacing.',
                'steps'    => [
                    ['key' => 'reorder_1', 'delay' => 0, 'note' => 'Per product: reorder_days - 5 after the order'],
                ],
            ],
            'service_reminder' => [
                'label'    => 'Service reminder (Kenya)',
                'audience' => 'marketing',
                'trigger'  => 'A garage booking is about service_reminder_days old (default 150): time for the next service.',
                'steps'    => [
                    ['key' => 'service_1', 'delay' => 0, 'note' => 'Once per booking: book your next service, their car named'],
                ],
            ],
            'winback' => [
                'label'    => 'Win-back',
                'audience' => 'marketing',
                'trigger'  => 'Opted-in customer past the expected reorder window with no new order.',
                'steps'    => [
                    ['key' => 'winback_1', 'delay' => 0, 'note' => 'Day 60 without order: what is new'],
                    ['key' => 'winback_2', 'delay' => 30 * DAY_IN_SECONDS, 'note' => 'Day 90: stay-on-list check'],
                ],
            ],
            'restock_alert' => [
                'label'    => 'Restock alert',
                'audience' => 'marketing',
                'trigger'  => 'A product comes back in stock; sent to opted-in past buyers and recent viewers.',
                'steps'    => [
                    ['key' => 'restock_1', 'delay' => 0, 'note' => 'Immediately on restock'],
                ],
            ],
        ];
    }

    public static function enabled(string $flow): bool
    {
        $flags = get_option('sm_flows_enabled', []);
        if (!is_array($flags)) {
            return true;
        }
        return ($flags[$flow] ?? 'yes') === 'yes';
    }

    /** Delay override from the admin UI (seconds), else the definition default. */
    public static function step_delay(string $flow, string $step_key, int $default): int
    {
        $overrides = get_option('sm_flow_delays', []);
        if (is_array($overrides) && isset($overrides[$flow][$step_key])) {
            return max(0, (int) $overrides[$flow][$step_key]);
        }
        return $default;
    }

    /**
     * Enqueue a flow's steps for a subscriber.
     * $dedupe_scope makes the run unique (e.g. cart hash, order id, product id).
     * $ctx: template context stored in each queue row's payload.
     */
    public static function start(array $sub, string $flow, string $dedupe_scope = '', array $ctx = [], array $only_steps = []): int
    {
        $defs = self::definitions();
        if (!isset($defs[$flow]) || !self::enabled($flow)) {
            return 0;
        }
        $queued = 0;
        $base = [
            'email'      => $sub['email'],
            'first_name' => $sub['first_name'] ?? '',
        ];
        foreach ($defs[$flow]['steps'] as $i => $step) {
            if ($only_steps && !in_array($step['key'], $only_steps, true)) {
                continue;
            }
            $delay = self::step_delay($flow, $step['key'], (int) $step['delay']);
            $dedupe = $flow . '|' . $step['key'] . '|' . $sub['email'] . '|' . $dedupe_scope;
            $step_ctx = array_merge($base, $ctx);
            // Later steps that show products should re-personalize at send time.
            $step_ctx['_refresh_products'] = in_array($step['key'], ['welcome_4', 'welcome_5', 'winback_1', 'winback_2', 'browse_1'], true);
            $ok = SM_Queue::add([
                'subscriber_id' => (int) ($sub['id'] ?? 0) ?: null,
                'email'         => $sub['email'],
                'flow'          => $flow,
                'step'          => $step['key'],
                'template'      => $step['key'],
                'payload'       => $step_ctx,
                'send_at'       => sm_dt(time() + $delay),
                'dedupe_key'    => $dedupe,
            ]);
            if ($ok) {
                $queued++;
            }
        }
        return $queued;
    }
}

<?php
/** Service reminders: next service due by date and/or mileage, emailed daily. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function sa_g_reminder_add(int $vid, string $service, string $due_date = '', int $due_km = 0, int $ro = 0): int
{
    global $wpdb;
    if (!$vid || $service === '' || ($due_date === '' && !$due_km)) {
        return 0;
    }
    $wpdb->insert(sa_g_t('reminders'), ['vehicle_id' => $vid, 'ro_id' => $ro, 'service' => sanitize_text_field($service), 'due_date' => $due_date !== '' ? $due_date : null, 'due_km' => max(0, $due_km), 'status' => 'open', 'created' => sa_g_now()]);
    return (int) $wpdb->insert_id;
}

add_action('init', static function (): void {
    if (!wp_next_scheduled('sa_g_reminders_daily')) {
        wp_schedule_event(strtotime('tomorrow 06:00:00') - 3 * HOUR_IN_SECONDS, 'daily', 'sa_g_reminders_daily');
    }
});

/** Due = date within 7 days, or last known mileage within 500 km of the target. */
function sa_g_reminders_run(bool $dry = false): array
{
    global $wpdb;
    $R = sa_g_t('reminders');
    $V = sa_g_t('vehicles');
    $soon = wp_date('Y-m-d', time() + 7 * DAY_IN_SECONDS);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT r.*, v.mileage, v.make, v.model, v.year, v.plate, v.customer_id FROM $R r JOIN $V v ON v.id=r.vehicle_id WHERE r.status='open' AND ((r.due_date IS NOT NULL AND r.due_date<=%s) OR (r.due_km>0 AND v.mileage>=r.due_km-500))", $soon), ARRAY_A) ?: [];
    $sent = [];
    foreach ($rows as $r) {
        $c = sa_g_customer((int) $r['customer_id']);
        if (!$c || !is_email($c['email'])) {
            continue;
        }
        if ($dry) {
            $sent[] = (int) $r['id'];
            continue;
        }
        $car = trim(($r['year'] ?: '') . ' ' . $r['make'] . ' ' . $r['model']);
        $due = array_filter([$r['due_date'] ? 'by ' . mysql2date('j M Y', $r['due_date']) : '', (int) $r['due_km'] ? 'at ' . number_format((int) $r['due_km']) . ' km' : '']);
        $html = sa_mail_layout('Your ' . $car . ' is due for service', '<p>Hi ' . esc_html(strtok((string) $c['name'], ' ') ?: 'there') . ',</p><p>Your <strong>' . esc_html($car) . '</strong>' . (!str_starts_with((string) $r['plate'], 'NOPLATE') ? ' (' . esc_html($r['plate']) . ')' : '') . ' is due for <strong>' . esc_html($r['service']) . '</strong> ' . esc_html(implode(' or ', $due)) . '.</p><p>Book a slot online in a minute. Drop the car at Midax Plaza, Kangundo Rd, Njiru, or ask us to collect it.</p>', 'Book now', add_query_arg(['make' => $r['make'], 'model' => $r['model'], 'year' => $r['year'] ?: ''], home_url('/book-service/')), 'Service reminder');
        if (wp_mail($c['email'], 'Service reminder: ' . $r['service'] . ' for your ' . $car, $html, ['Content-Type: text/html; charset=UTF-8'])) {
            $wpdb->update($R, ['status' => 'sent', 'sent_at' => sa_g_now()], ['id' => (int) $r['id']]);
            $sent[] = (int) $r['id'];
        }
    }
    return $sent;
}
add_action('sa_g_reminders_daily', static function (): void { sa_g_reminders_run(); });

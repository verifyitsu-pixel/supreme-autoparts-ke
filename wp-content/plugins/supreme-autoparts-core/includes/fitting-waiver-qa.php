<?php
/** TEMPORARY QA endpoint for the fitting waiver (removed after testing). Secret-hash gated. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/fw-qa', ['methods' => 'POST', 'permission_callback' => static fn($r) => hash_equals('6bf89cd0f4f444c1daf819768d3987a193aeab83ece5648fc2a85d6a66ed07fa', hash('sha256', (string) $r->get_param('t'))),
        'callback' => static function ($r) {
            $do = (string) $r->get_param('do');
            if ($do === 'create') {
                $pid = (int) $r->get_param('product');
                $o = wc_create_order();
                $o->add_product(wc_get_product($pid), 1);
                $o->set_billing_first_name('QA'); $o->set_billing_last_name('Fitting Test');
                $o->set_billing_email('qa-fitting@supremeautoparts.co.ke'); $o->set_billing_phone('+254 700 000 111');
                $o->update_meta_data('_sa_qa_fw', 1);
                $o->calculate_totals();
                $o->set_status((string) $r->get_param('status') ?: 'processing', 'QA test order for fitting waiver — safe to delete.');
                $o->save();
                return ['order' => $o->get_id(), 'number' => $o->get_order_number(), 'key' => $o->get_order_key(), 'status' => $o->get_status()];
            }
            $o = wc_get_order((int) $r->get_param('order'));
            if (!$o || !$o->get_meta('_sa_qa_fw')) { return new WP_Error('no', 'not a QA order', ['status' => 400]); }
            if ($do === 'status') { $o->set_status((string) $r->get_param('status')); $o->save(); }
            if ($do === 'booking') { $b = (int) $r->get_param('b'); return ['b' => sa_svc_booking_data($b), 'waiver' => sa_fw_waiver($b), 'invoice' => sa_bk_invoice_html($b)]; }
            if ($do === 'override') { $b = (int) $r->get_param('b'); $_POST['fw_override'] = (string) $r->get_param('ov'); wp_set_current_user(1); sa_fw_admin_save($b); return ['b' => sa_svc_booking_data($b), 'waiver' => sa_fw_waiver($b), 'admin' => sa_fw_admin_box($b, sa_svc_booking_data($b))]; }
            if ($do === 'cleanup') { foreach ((array) $o->get_meta('_sa_fw_bookings') as $b) { wp_delete_post((int) $b, true); } foreach ((array) $r->get_param('extra') as $b) { if (get_post_type((int) $b) === 'sa_booking') wp_delete_post((int) $b, true); } $o->delete(true); return ['deleted' => true]; }
            return ['order' => $o->get_id(), 'status' => $o->get_status(), 'map' => $o->get_meta('_sa_fw_bookings')];
        }]);
});

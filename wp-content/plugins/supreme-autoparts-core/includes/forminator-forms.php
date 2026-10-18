<?php
/**
 * Forminator site forms as code: careers application, booking request, roadside request.
 * Form definitions live HERE (git = source of truth). A version-gated, idempotent seed
 * creates/updates the Forminator DB forms on deploy via the Forminator API — wp-admin
 * is never touched by hand. Form IDs are stored in the sa_forminator_forms option.
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const SA_FF_SEED_VER = '2';
const SA_FF_NOTIFY   = 'calvin@supremeautoparts.co.ke';

/* One wrapper row per field (stacked, full-width). */
function sa_ff_wrap(string $tag, array $field): array
{
    return ['wrapper_id' => 'wrapper-sa-' . $tag, 'fields' => [$field]];
}

function sa_ff_field(string $id, string $type, array $props): array
{
    return array_merge(['element_id' => $id, 'type' => $type, 'cols' => '12', 'conditions' => []], $props);
}

function sa_ff_options(array $labels): array
{
    $out = [];
    $i = 0;
    foreach ($labels as $value => $label) {
        $i++;
        $out[] = ['label' => $label, 'value' => (string) $value, 'limit' => '', 'key' => 'sak' . $i . substr(md5((string) $value), 0, 6)];
    }
    return $out;
}

function sa_ff_defs(): array
{
    $years = [];
    for ($y = 2027; $y >= 2010; $y--) {
        $years[$y] = (string) $y;
    }
    return [
        'careers' => [
            'name' => 'Careers Application',
            'wrappers' => [
                sa_ff_wrap('c1', sa_ff_field('name-1', 'text', ['field_label' => 'Your name', 'required' => 'true', 'placeholder' => 'Your full name', 'required_message' => 'Please tell us your name.'])),
                sa_ff_wrap('c2', sa_ff_field('email-1', 'email', ['field_label' => 'Email', 'required' => 'true', 'placeholder' => 'you@email.com', 'required_message' => 'Please give your email address.'])),
                sa_ff_wrap('c3', sa_ff_field('phone-1', 'phone', ['field_label' => 'Phone', 'required' => 'true', 'placeholder' => 'e.g. 0712 345 678', 'validation' => 'none', 'required_message' => 'Please give your phone number.'])),
                sa_ff_wrap('c4', sa_ff_field('select-1', 'select', [
                    'field_label' => 'Role', 'required' => 'true', 'placeholder' => 'Choose…', 'required_message' => 'Please pick a role.',
                    'options' => sa_ff_options([
                        'delivery-rider' => 'Delivery Rider',
                        'mazda-specialist' => 'Mazda Specialist',
                        'custom-mods-technician' => 'Custom Mods Technician',
                        'online-orders-coordinator' => 'Online Orders & Receipts Coordinator',
                        'general' => 'General application',
                    ]),
                ])),
                sa_ff_wrap('c5', sa_ff_field('textarea-1', 'textarea', ['field_label' => 'Tell us about your experience', 'required' => 'true', 'rows' => '5', 'placeholder' => 'What you have done, where, and why this role fits you', 'required_message' => 'Please tell us about your experience.'])),
                sa_ff_wrap('c6', sa_ff_field('upload-1', 'upload', ['field_label' => 'CV (PDF, DOC or DOCX)', 'required' => 'true', 'file-type' => 'single', 'filetypes' => ['pdf', 'doc', 'docx'], 'file-limit' => 1, 'upload-limit' => 10, 'filesize' => 'MB', 'description' => 'Up to 10 MB.', 'required_message' => 'Please attach your CV.'])),
            ],
            'settings' => [
                'thankyou-message' => 'Thank you - we have your application. A copy is in your inbox and we will be in touch.',
                'submitData' => ['custom-submit-text' => 'Submit Application'],
                'use-user-email' => 'true',
                'user-email-title' => 'Your Supreme Autoparts application: {select-1}',
                'user-email-editor' => '<p>Hi {name-1},</p><p>Thank you for applying for <strong>{select-1}</strong> at Supreme Autoparts. We have received your application and CV, and we will be in touch.</p><p>Supreme Autoparts, Midax Plaza, Kangundo Rd, Njiru, Nairobi</p>',
                'user-email-recipients' => ['{email-1}'],
            ],
            'notifications' => [
                ['slug' => 'notification-9001-0001', 'label' => 'Calvin', 'email-recipients' => 'default', 'recipients' => SA_FF_NOTIFY, 'email-subject' => 'New careers application: {name-1}', 'email-editor' => 'New application via the careers form:<br/><br/>{all_fields}<br/><br/>Sent from {site_url}.', 'email-attachment' => 'true', 'type' => 'default'],
                ['slug' => 'notification-9001-0002', 'label' => 'Applicant copy', 'email-recipients' => 'default', 'recipients' => '{email-1}', 'email-subject' => 'Your Supreme Autoparts application: {select-1}', 'email-editor' => '<p>Hi {name-1},</p><p>Thank you for applying for <strong>{select-1}</strong> at Supreme Autoparts. We have received your application and CV, and we will be in touch.</p><p>Supreme Autoparts, Midax Plaza, Kangundo Rd, Njiru, Nairobi</p>', 'email-attachment' => 'false', 'type' => 'default'],
            ],
        ],
        'booking' => [
            'name' => 'Booking Request',
            'wrappers' => [
                sa_ff_wrap('b1', sa_ff_field('name-1', 'text', ['field_label' => 'Your name', 'required' => 'true', 'placeholder' => 'Your full name'])),
                sa_ff_wrap('b2', sa_ff_field('email-1', 'email', ['field_label' => 'Email', 'required' => 'true', 'placeholder' => 'you@email.com'])),
                sa_ff_wrap('b3', sa_ff_field('phone-1', 'phone', ['field_label' => 'Phone', 'required' => 'true', 'placeholder' => 'e.g. 0712 345 678', 'validation' => 'none'])),
                sa_ff_wrap('b4', sa_ff_field('year-1', 'select', ['field_label' => 'Vehicle year', 'required' => 'true', 'placeholder' => 'Choose…', 'options' => sa_ff_options($years)])),
                sa_ff_wrap('b5', sa_ff_field('make-1', 'text', ['field_label' => 'Make', 'required' => 'true', 'placeholder' => 'e.g. Toyota'])),
                sa_ff_wrap('b6', sa_ff_field('model-1', 'text', ['field_label' => 'Model', 'required' => 'true', 'placeholder' => 'e.g. Fielder'])),
                sa_ff_wrap('b7', sa_ff_field('plate-1', 'text', ['field_label' => 'Plate / reg.', 'placeholder' => 'KDA 123A'])),
                sa_ff_wrap('b8', sa_ff_field('select-1', 'select', [
                    'field_label' => 'Service', 'required' => 'true', 'placeholder' => 'Choose…',
                    'options' => sa_ff_options(['general-service' => 'General service', 'part-fitting' => 'Part fitting', 'diagnostics' => 'Diagnostics', 'roadside-follow-up' => 'Roadside follow-up', 'other' => 'Other']),
                ])),
                sa_ff_wrap('b9', sa_ff_field('order-1', 'text', ['field_label' => 'Order number (if fitting a part bought from us)', 'placeholder' => 'e.g. 26555'])),
                sa_ff_wrap('b10', sa_ff_field('date-1', 'date', ['field_label' => 'Preferred date', 'required' => 'true', 'field_type' => 'picker', 'date_format' => 'dd/mm/yy', 'placeholder' => 'Choose date'])),
                sa_ff_wrap('b11', sa_ff_field('select-2', 'select', [
                    'field_label' => 'Preferred pickup time', 'required' => 'true', 'placeholder' => 'Choose…',
                    'options' => sa_ff_options(['1-hour-before' => '1 hour before my slot', '2-hours-before' => '2 hours before my slot', 'early-morning' => 'Early morning (7:00–8:00)', 'day-before-evening' => 'Day before (evening)']),
                ])),
                sa_ff_wrap('b12', sa_ff_field('street-1', 'text', ['field_label' => 'Street / road', 'required' => 'true', 'placeholder' => 'Kangundo Rd'])),
                sa_ff_wrap('b13', sa_ff_field('building-1', 'text', ['field_label' => 'Building / estate'])),
                sa_ff_wrap('b14', sa_ff_field('area-1', 'text', ['field_label' => 'Area / neighbourhood', 'required' => 'true', 'placeholder' => 'e.g. Njiru'])),
                sa_ff_wrap('b15', sa_ff_field('city-1', 'text', ['field_label' => 'City / town', 'required' => 'true', 'placeholder' => 'Nairobi'])),
                sa_ff_wrap('b16', sa_ff_field('notes-1', 'textarea', ['field_label' => 'Notes (optional)', 'rows' => '4', 'placeholder' => 'Anything else we should know'])),
            ],
            'settings' => [
                'thankyou-message' => 'Thank you - we have your booking request. We will confirm your slot by phone or email.',
                'submitData' => ['custom-submit-text' => 'Request Booking'],
                'use-user-email' => 'true',
                'user-email-title' => 'We received your booking request',
                'user-email-editor' => '<p>Hi {name-1},</p><p>Thank you - we have your booking request for <strong>{select-1}</strong> ({year-1} {make-1} {model-1}). We will confirm your slot by phone or email.</p><p>Supreme Autoparts, Midax Plaza, Kangundo Rd, Njiru, Nairobi</p>',
                'user-email-recipients' => ['{email-1}'],
            ],
            'notifications' => [
                ['slug' => 'notification-9002-0001', 'label' => 'Calvin', 'email-recipients' => 'default', 'recipients' => SA_FF_NOTIFY, 'email-subject' => 'New booking request: {name-1}', 'email-editor' => 'New booking request via the website:<br/><br/>{all_fields}<br/><br/>Sent from {site_url}.', 'email-attachment' => 'true', 'type' => 'default'],
                ['slug' => 'notification-9002-0002', 'label' => 'Customer copy', 'email-recipients' => 'default', 'recipients' => '{email-1}', 'email-subject' => 'We received your booking request', 'email-editor' => '<p>Hi {name-1},</p><p>Thank you - we have your booking request for <strong>{select-1}</strong>. We will confirm your slot by phone or email.</p><p>Supreme Autoparts, Midax Plaza, Kangundo Rd, Njiru, Nairobi</p>', 'email-attachment' => 'false', 'type' => 'default'],
            ],
        ],
        'roadside' => [
            'name' => 'Roadside Request',
            'wrappers' => [
                sa_ff_wrap('r1', sa_ff_field('name-1', 'text', ['field_label' => 'Your name', 'required' => 'true', 'placeholder' => 'Your full name'])),
                sa_ff_wrap('r2', sa_ff_field('phone-1', 'phone', ['field_label' => 'Phone', 'required' => 'true', 'placeholder' => '07xx xxx xxx', 'validation' => 'none'])),
                sa_ff_wrap('r3', sa_ff_field('email-1', 'email', ['field_label' => 'Email', 'required' => 'true', 'placeholder' => 'you@email.com'])),
                sa_ff_wrap('r4', sa_ff_field('make-1', 'text', ['field_label' => 'Vehicle make', 'required' => 'true', 'placeholder' => 'Toyota'])),
                sa_ff_wrap('r5', sa_ff_field('model-1', 'text', ['field_label' => 'Vehicle model', 'required' => 'true', 'placeholder' => 'Fielder'])),
                sa_ff_wrap('r6', sa_ff_field('plate-1', 'text', ['field_label' => 'Number plate', 'required' => 'true', 'placeholder' => 'KDA 123A'])),
                sa_ff_wrap('r7', sa_ff_field('location-1', 'text', ['field_label' => 'Current location', 'required' => 'true', 'placeholder' => 'e.g. Thika Road, near Garden City exit'])),
                sa_ff_wrap('r8', sa_ff_field('select-1', 'select', [
                    'field_label' => 'Problem type', 'required' => 'true', 'placeholder' => 'Choose…',
                    'options' => sa_ff_options(['breakdown' => 'Breakdown', 'flat-tyre' => 'Flat Tyre', 'battery-jump-start' => 'Battery Jump Start', 'towing-needed' => 'Towing Needed', 'lockout' => 'Lockout', 'fuel-delivery' => 'Fuel Delivery', 'other' => 'Other']),
                ])),
                sa_ff_wrap('r9', sa_ff_field('description-1', 'textarea', ['field_label' => 'Describe the problem', 'required' => 'true', 'rows' => '4', 'placeholder' => 'What happened? Any warning lights? Is the car safely off the road?'])),
                sa_ff_wrap('r10', sa_ff_field('radio-1', 'radio', [
                    'field_label' => 'Preferred contact method', 'required' => 'true', 'layout' => 'vertical',
                    'options' => sa_ff_options(['call' => 'Call', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS']),
                ])),
            ],
            'settings' => [
                'thankyou-message' => 'Request received. We will contact you right away - keep your phone close. For urgent help call +254 714 498 451.',
                'submitData' => ['custom-submit-text' => 'Submit Request'],
            ],
            'notifications' => [
                ['slug' => 'notification-9003-0001', 'label' => 'Calvin', 'email-recipients' => 'default', 'recipients' => SA_FF_NOTIFY, 'email-subject' => 'New roadside request: {name-1}', 'email-editor' => 'New roadside request via the website:<br/><br/>{all_fields}<br/><br/>Sent from {site_url}.', 'email-attachment' => 'true', 'type' => 'default'],
            ],
        ],
    ];
}

function sa_ff_settings(string $formName, array $extra): array
{
    return array_merge([
        'formName' => $formName,
        'version' => defined('FORMINATOR_VERSION') ? FORMINATOR_VERSION : '1.44.2',
        'form-border-style' => 'solid',
        'form-padding' => '',
        'form-border' => '',
        'fields-style' => 'open',
        'validation' => 'on_submit',
        'akismet-protection' => false,
        'form-style' => 'default',
        'enable-ajax' => 'true',
        'autoclose' => 'false',
        'submission-indicator' => 'show',
        'indicator-label' => 'Submitting...',
        'store_submissions' => '1',
        'submission-behaviour' => 'behaviour-thankyou',
        'description-position' => 'above',
    ], $extra);
}

/* Forminator's settings sanitizer strips HTML from the autoresponder body; keep ours. */
add_filter('forminator_builder_data_settings_before_saving', static function (array $settings, array $original): array {
    if (isset($original['user-email-editor'])) {
        $settings['user-email-editor'] = wp_kses_post($original['user-email-editor']);
    }
    if (isset($original['use-user-email'])) {
        $settings['use-user-email'] = sanitize_text_field((string) $original['use-user-email']);
    }
    if (isset($original['user-email-title'])) {
        $settings['user-email-title'] = sanitize_text_field((string) $original['user-email-title']);
    }
    if (isset($original['user-email-recipients'])) {
        $settings['user-email-recipients'] = array_map('sanitize_text_field', (array) $original['user-email-recipients']);
    }
    return $settings;
}, 10, 2);

/* Version-gated, idempotent seed. Runs on init only when Forminator is loaded; retries
 * on later requests until it succeeds, so a deploy where the plugin activates late is fine. */
add_action('init', static function (): void {
    if (!class_exists('Forminator_API')) {
        // Self-heal: plugin files are baked into the image. If the entrypoint's
        // activation did not stick (activation state lives in the DB, not git),
        // activate from code here so git stays the source of truth.
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (file_exists(WP_PLUGIN_DIR . '/forminator/forminator.php') && !is_plugin_active('forminator/forminator.php')) {
            activate_plugin('forminator/forminator.php');
        }
    }
    if (get_option('sa_forminator_seed_ver') === SA_FF_SEED_VER || !class_exists('Forminator_API')) {
        return;
    }
    $ids = (array) get_option('sa_forminator_forms', []);
    $ok = true;
    foreach (sa_ff_defs() as $key => $def) {
        $wrappers = $def['wrappers'];
        $settings = sa_ff_settings($def['name'], $def['settings']);
        $id = (int) ($ids[$key] ?? 0);
        if ($id && get_post_status($id)) {
            $r = Forminator_API::update_form($id, $wrappers, $settings, 'publish', $def['notifications']);
        } else {
            $r = Forminator_API::add_form($def['name'], $wrappers, $settings, 'publish');
            if (!is_wp_error($r)) {
                $id = (int) $r;
                $r = Forminator_API::update_form($id, $wrappers, $settings, 'publish', $def['notifications']);
            }
        }
        if (is_wp_error($r)) {
            $ok = false;
            error_log('sa_forminator seed failed for ' . $key . ': ' . $r->get_error_message());
        } else {
            $ids[$key] = (int) $r;
        }
    }
    update_option('sa_forminator_forms', $ids, false);
    if ($ok) {
        update_option('sa_forminator_seed_ver', SA_FF_SEED_VER, false);
        flush_rewrite_rules(false);
    }
}, 26);

function sa_ff_id(string $key): int
{
    $ids = (array) get_option('sa_forminator_forms', []);
    return (int) ($ids[$key] ?? 0);
}

function sa_ff_render(string $key): string
{
    $id = sa_ff_id($key);
    if (!$id || !shortcode_exists('forminator_form')) {
        return '';
    }
    return do_shortcode('[forminator_form id="' . $id . '"]');
}

/* Site-style overrides for Forminator's default skin (rounded inputs, dark/amber button). */
function sa_ff_css(): string
{
    return '<style id="sa-ff-css">.sa-ff .forminator-ui .forminator-label{font-size:13px;font-weight:600;color:#333333}.sa-ff .forminator-ui .forminator-input,.sa-ff .forminator-ui .forminator-textarea{border:1px solid #d9d7d2;border-radius:10px;padding:10px 12px;font-size:15px}.sa-ff .forminator-ui .forminator-input:focus,.sa-ff .forminator-ui .forminator-textarea:focus{border-color:#F5A623;box-shadow:0 0 0 2px rgba(245,166,35,.25);outline:none}.sa-ff .forminator-ui .select2-container--default .select2-selection--single,.sa-ff .forminator-ui .select2-container--default .select2-selection--multiple{border:1px solid #d9d7d2;border-radius:10px;min-height:44px}.sa-ff .forminator-ui.forminator-custom-form .forminator-button{background:#F5A623 !important;color:#111111 !important;border:0 !important;border-radius:10px !important;font-weight:600;padding:12px 22px;font-size:15px}.sa-ff .forminator-ui.forminator-custom-form .forminator-button:hover{filter:brightness(.95);background:#F5A623 !important;color:#111111 !important}.sa-ff .forminator-ui.forminator-custom-form .forminator-button:disabled{opacity:.7}.sa-ff .forminator-ui .forminator-error-message{color:#b42318}.sa-ff .forminator-ui .forminator-response-message.forminator-success{background:#ecfdf3;border:1px solid #a7f3c4;color:#065f46;border-radius:12px;padding:14px 16px}.sa-ff .forminator-ui .forminator-field-file-upload{border:1px dashed #d9d7d2;border-radius:10px}</style>';
}

/* Unlinked preview route so each form can be tested live (real submission) before its page flips. */
add_action('init', static function (): void {
    add_rewrite_rule('^sa-form-preview/?$', 'index.php?sa_ff_preview=1', 'top');
});
add_filter('query_vars', static function (array $v): array {
    $v[] = 'sa_ff_preview';
    return $v;
});
add_action('template_redirect', static function (): void {
    if (!get_query_var('sa_ff_preview')) {
        return;
    }
    $form = sanitize_key((string) ($_GET['form'] ?? 'careers'));
    if (!isset(sa_ff_defs()[$form])) {
        $form = 'careers';
    }
    status_header(200);
    add_filter('pre_get_document_title', static fn() => 'Form preview | ' . get_bloginfo('name'), 99);
    add_action('wp_head', static function (): void {
        echo sa_ff_css(); // phpcs:ignore
    });
    get_header();
    echo '<main id="main" class="sa-svc sa-sf-page"><div class="sa-container sa-sf-narrow">';
    echo '<h1>' . esc_html(sa_ff_defs()[$form]['name']) . '</h1>';
    $html = sa_ff_render($form);
    if ($html === '') {
        echo '<p>Form is not ready yet (Forminator inactive or seed pending).</p>';
        if (isset($_GET['debug'])) {
            echo '<pre style="font-size:12px;background:#f4f4f1;padding:10px;border-radius:8px;white-space:pre-wrap">';
            echo 'forminator_files=' . (file_exists(WP_PLUGIN_DIR . '/forminator/forminator.php') ? 'yes' : 'no') . "\n";
            echo 'forminator_active=' . (class_exists('Forminator_API') ? 'yes' : 'no') . "\n";
            echo 'shortcode=' . (shortcode_exists('forminator_form') ? 'yes' : 'no') . "\n";
            echo 'ids=' . esc_html((string) wp_json_encode(get_option('sa_forminator_forms'))) . "\n";
            echo 'seed_ver=' . esc_html((string) get_option('sa_forminator_seed_ver')) . "\n";
            echo '</pre>';
        }
    } else {
        echo '<div class="sa-ff">' . $html . '</div>'; // phpcs:ignore
    }
    echo '</div></main>';
    get_footer();
    exit;
});

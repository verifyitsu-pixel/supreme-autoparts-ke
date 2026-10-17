<?php
/**
 * Plugin Name: Supreme Autoparts - Agent Intake
 * Description: Receives Ashley (Jotform AI agent) form submissions via webhook, plus a scheduled Jotform submissions-API polling fallback, and tracks them in WP admin as Customer Requests.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Setup (one time):
 *   1. Add to the environment (Railway): SA_AGENT_INTAKE_TOKEN=<long-random-string>
 *      (the entrypoint maps it into wp-config; also accepted directly from getenv).
 *   2. Add to the environment (Railway): SA_JOTFORM_API_KEY=<jotform-api-key>
 *      Powers the polling fallback below. Never commit the key to the repo.
 *   3. In Jotform, on each agent-connected form: Settings -> Integrations -> WebHooks,
 *      endpoint URL: https://www.supremeautoparts.co.ke/wp-json/supreme/v1/agent/intake
 *      (the token is sent as the X-SA-Agent-Token header if the integration supports
 *      headers; otherwise append ?token=<same-string> - both are accepted).
 *
 * Delivery model: the Jotform webhook is the fast path. Because Jotform's server-side
 * webhook delivery to this endpoint proved unreliable (Oct 18 2026: registered and
 * verified via API, but submissions never arrived), the plugin ALSO polls the Jotform
 * submissions API every 5 minutes via WP-Cron and creates the same Customer Requests,
 * deduped by Jotform submission ID. Webhook or poll, a submission lands exactly once.
 */
final class SA_Agent_Intake
{
    const CPT          = 'sa_customer_request';
    const TAX          = 'sa_request_status';
    const META_PREFIX  = '_sa_';
    const STATUSES     = ['new' => 'New', 'in-progress' => 'In Progress', 'done' => 'Done'];
    const POLL_HOOK    = 'sa_agent_intake_poll';
    const POLL_SCHEDULE= 'sa_every_five_minutes';
    const POLL_OPTION  = 'sa_agent_intake_last_poll';
    const JOTFORM_API  = 'https://api.jotform.com';

    /**
     * Jotform form ID => request type. Scope decision (parent delegation, Oct 18 2026):
     * only human-escalation submissions sync to WP admin. Roadside and
     * part-request forms deliberately do NOT post here; they notify by email.
     * Unknown form IDs are rejected below.
     */
    const FORM_TYPES = [
        '262896304974067' => 'escalation', // Human Escalation Request
    ];

    /** Incoming field aliases (lowercased, Jotform rawRequest or JSON) => canonical meta key. */
    const FIELD_ALIASES = [
        'customer_name' => ['name', 'fullname', 'customername', 'yourname', 'full_name'],
        'phone'         => ['phone', 'phonenumber', 'phone_number', 'mobile', 'whatsapp'],
        'email'         => ['email', 'emailaddress', 'e-mail'],
        'vehicle'       => ['vehicle', 'carmodel', 'vehiclemake_model', 'make_model', 'makemodel'],
        'plate'         => ['plate', 'platenumber', 'numberplate', 'registration', 'regno'],
        'location'      => ['location', 'yourlocation', 'breakdownlocation', 'area'],
        'problem'       => ['problem', 'issue', 'problemtype', 'service_needed'],
        'description'   => ['description', 'details', 'message', 'describe'],
        'contact_method'=> ['contactmethod', 'preferredcontact', 'contact_method'],
        'part'          => ['part', 'partname', 'partneeded', 'part_name'],
        'quantity'      => ['quantity', 'qty', 'howmany'],
    ];

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('manage_' . self::CPT . '_posts_columns', [__CLASS__, 'admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [__CLASS__, 'admin_column_content'], 10, 2);
        add_filter('rest_pre_dispatch', [__CLASS__, 'capture_raw_body'], 5, 3);

        // Polling fallback (Jotform submissions API).
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action(self::POLL_HOOK, [__CLASS__, 'poll_jotform']);
        add_action('init', [__CLASS__, 'ensure_schedule'], 30);
        add_action('admin_post_sa_agent_intake_poll', [__CLASS__, 'manual_poll']);
        add_action('admin_notices', [__CLASS__, 'poll_notice']);
    }

    /* ---------- Admin: CPT, taxonomy, columns ---------- */

    public static function register_cpt(): void
    {
        register_post_type(self::CPT, [
            'labels'       => ['name' => 'Customer Requests', 'singular_name' => 'Customer Request'],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-car',
            'menu_position'=> 26,
            'supports'     => ['title'],
            'capability_type' => 'post',
        ]);

        register_taxonomy(self::TAX, self::CPT, [
            'labels'            => ['name' => 'Request Status', 'singular_name' => 'Status'],
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'hierarchical'      => false,
        ]);

        foreach (self::STATUSES as $slug => $label) {
            if (!term_exists($slug, self::TAX)) {
                wp_insert_term($label, self::TAX, ['slug' => $slug]);
            }
        }
    }

    public static function admin_columns(array $cols): array
    {
        return [
            'cb'          => $cols['cb'] ?? '<input type="checkbox" />',
            'title'       => 'Request',
            'sa_type'     => 'Type',
            'sa_customer' => 'Customer',
            'sa_phone'    => 'Phone',
            'taxonomy-' . self::TAX => 'Status',
            'date'        => 'Received',
        ];
    }

    public static function admin_column_content(string $col, int $post_id): void
    {
        if ($col === 'sa_type') {
            echo esc_html((string) get_post_meta($post_id, self::META_PREFIX . 'request_type', true));
        } elseif ($col === 'sa_customer') {
            echo esc_html((string) get_post_meta($post_id, self::META_PREFIX . 'customer_name', true));
        } elseif ($col === 'sa_phone') {
            echo esc_html((string) get_post_meta($post_id, self::META_PREFIX . 'phone', true));
        }
    }

    /* ---------- REST endpoint ---------- */

    /** Stash the raw body so the callback can parse Jotform's rawRequest reliably. */
    public static function capture_raw_body($result, $server, $request)
    {
        if ($request->get_route() === '/supreme/v1/agent/intake') {
            $GLOBALS['sa_agent_intake_raw_body'] = (string) $request->get_body();
        }
        return $result;
    }

    public static function register_routes(): void
    {
        register_rest_route('supreme/v1', '/agent/intake', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_intake'],
            'permission_callback' => [__CLASS__, 'check_token'],
        ]);
    }

    public static function check_token(WP_REST_Request $request)
    {
        $token = defined('SA_AGENT_INTAKE_TOKEN') ? SA_AGENT_INTAKE_TOKEN : '';
        if ($token === '') {
            return new WP_Error('sa_intake_unconfigured', 'Intake token is not configured on the server.', ['status' => 503]);
        }
        $given = (string) $request->get_header('x-sa-agent-token');
        if ($given === '') {
            $given = (string) $request->get_param('token');
        }
        if (!hash_equals($token, $given)) {
            return new WP_Error('sa_intake_forbidden', 'Invalid token.', ['status' => 401]);
        }
        return true;
    }

    public static function handle_intake(WP_REST_Request $request)
    {
        $data = self::extract_payload($request);

        $form_id       = (string) ($data['form_id'] ?? '');
        $submission_id = (string) ($data['submission_id'] ?? '');
        $fields        = is_array($data['fields'] ?? null) ? $data['fields'] : [];

        return self::create_request($form_id, $submission_id, $fields);
    }

    /**
     * Shared write path for the webhook (handle_intake) and the polling fallback.
     * Returns ['ok'=>true, 'request_id'=>int, 'created'=>bool] or WP_Error.
     */
    private static function create_request(string $form_id, string $submission_id, array $fields)
    {
        // Only approved escalation forms may post: unknown form IDs are rejected outright.
        if (!isset(self::FORM_TYPES[$form_id])) {
            return new WP_Error('sa_intake_unknown_form', 'This form is not registered for intake.', ['status' => 403]);
        }
        $request_type = self::FORM_TYPES[$form_id];
        if ($fields === []) {
            return new WP_Error('sa_intake_bad_request', 'Missing fields.', ['status' => 400]);
        }

        $mapped = self::map_fields($fields);
        if (($mapped['customer_name'] ?? '') === '' && ($mapped['phone'] ?? '') === '') {
            return new WP_Error('sa_intake_bad_request', 'A customer name or phone is required.', ['status' => 400]);
        }

        // Idempotency: one WP request per Jotform submission.
        if ($submission_id !== '') {
            $existing = get_posts([
                'post_type'   => self::CPT,
                'post_status' => 'any',
                'meta_key'    => self::META_PREFIX . 'submission_id',
                'meta_value'  => $submission_id,
                'numberposts' => 1,
                'fields'      => 'ids',
            ]);
            if ($existing) {
                return ['ok' => true, 'request_id' => (int) $existing[0], 'created' => false];
            }
        }

        $title_bits = array_filter([
            ucfirst($request_type),
            $mapped['customer_name'] ?? '',
            $mapped['phone'] ?? '',
        ]);
        $post_id = wp_insert_post([
            'post_type'   => self::CPT,
            'post_status' => 'publish',
            'post_title'  => implode(' - ', $title_bits),
        ], true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        wp_set_object_terms($post_id, 'new', self::TAX);
        update_post_meta($post_id, self::META_PREFIX . 'request_type', $request_type);
        update_post_meta($post_id, self::META_PREFIX . 'form_id', $form_id);
        update_post_meta($post_id, self::META_PREFIX . 'submission_id', $submission_id);
        foreach ($mapped as $key => $value) {
            update_post_meta($post_id, self::META_PREFIX . $key, $value);
        }

        return ['ok' => true, 'request_id' => (int) $post_id, 'created' => true];
    }

    /** Accept either a clean JSON body or Jotform's WebHooks form-encoded payload. */
    private static function extract_payload(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();
        if (is_array($json) && isset($json['fields']) && is_array($json['fields'])) {
            return $json;
        }

        $params = $request->get_params();
        $out = [
            'form_id'       => $params['formID'] ?? $params['form_id'] ?? '',
            'submission_id' => $params['submissionID'] ?? $params['submission_id'] ?? '',
            'fields'        => [],
        ];

        $raw = $params['rawRequest'] ?? ($GLOBALS['sa_agent_intake_raw_body'] ?? '');
        if (is_string($raw) && $raw !== '') {
            parse_str($raw, $parsed);
            $out['fields'] = self::flatten($parsed);
        }
        return $out;
    }

    /* ---------- Polling fallback (Jotform submissions API) ---------- */

    public static function cron_schedules(array $schedules): array
    {
        if (!isset($schedules[self::POLL_SCHEDULE])) {
            $schedules[self::POLL_SCHEDULE] = [
                'interval' => 300,
                'display'  => 'Every 5 Minutes (Supreme Agent Intake)',
            ];
        }
        return $schedules;
    }

    /** Lazy scheduling: the plugin is already active, and git deploys do not re-run activation hooks. */
    public static function ensure_schedule(): void
    {
        if (!wp_next_scheduled(self::POLL_HOOK)) {
            wp_schedule_event(time() + 120, self::POLL_SCHEDULE, self::POLL_HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::POLL_HOOK);
    }

    private static function jotform_api_key(): string
    {
        if (defined('SA_JOTFORM_API_KEY') && SA_JOTFORM_API_KEY !== '') {
            return (string) SA_JOTFORM_API_KEY;
        }
        $env = getenv('SA_JOTFORM_API_KEY');
        return is_string($env) ? $env : '';
    }

    /** WP-Cron callback: pull recent submissions for each registered form and create missing requests. */
    public static function poll_jotform(): void
    {
        $api_key = self::jotform_api_key();
        if ($api_key === '') {
            update_option(self::POLL_OPTION, [
                'time' => current_time('mysql'), 'seen' => 0, 'created' => 0,
                'error' => 'SA_JOTFORM_API_KEY is not set; polling skipped.',
            ], false);
            return;
        }

        $seen = 0;
        $created = 0;
        $errors = [];

        foreach (self::FORM_TYPES as $form_id => $type) {
            $url = self::JOTFORM_API . '/form/' . rawurlencode((string) $form_id) . '/submissions?' . http_build_query([
                'apiKey'  => $api_key,
                'limit'   => 100,
                'orderby' => 'created_at',
            ]);
            $resp = wp_remote_get($url, ['timeout' => 20, 'headers' => ['Accept' => 'application/json']]);
            if (is_wp_error($resp)) {
                $errors[] = $form_id . ': ' . $resp->get_error_message();
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($resp);
            $body = json_decode((string) wp_remote_retrieve_body($resp), true);
            if ($code !== 200 || !is_array($body) || !isset($body['content']) || !is_array($body['content'])) {
                $errors[] = $form_id . ': HTTP ' . $code;
                continue;
            }
            foreach ($body['content'] as $sub) {
                if (!is_array($sub)) {
                    continue;
                }
                if (($sub['status'] ?? 'ACTIVE') !== 'ACTIVE') {
                    continue;
                }
                $seen++;
                $submission_id = (string) ($sub['id'] ?? '');
                $fields = self::flatten_answers($sub['answers'] ?? []);
                $result = self::create_request((string) $form_id, $submission_id, $fields);
                if (is_wp_error($result)) {
                    $errors[] = $form_id . '/' . $submission_id . ': ' . $result->get_error_message();
                } elseif (!empty($result['created'])) {
                    $created++;
                }
            }
        }

        update_option(self::POLL_OPTION, [
            'time'    => current_time('mysql'),
            'seen'    => $seen,
            'created' => $created,
            'error'   => $errors ? implode(' | ', $errors) : '',
        ], false);
    }

    /** Manual trigger for verification: /wp-admin/admin-post.php?action=sa_agent_intake_poll */
    public static function manual_poll(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        self::poll_jotform();
        wp_safe_redirect(admin_url('edit.php?post_type=' . self::CPT . '&sa-polled=1'));
        exit;
    }

    /** Show the last poll result on the Customer Requests screen after a manual run. */
    public static function poll_notice(): void
    {
        if (!isset($_GET['sa-polled']) || !current_user_can('manage_options')) {
            return;
        }
        $last = get_option(self::POLL_OPTION);
        if (!is_array($last)) {
            return;
        }
        $msg = sprintf(
            'Jotform poll at %s: %d submission(s) seen, %d new request(s) created.%s',
            esc_html((string) ($last['time'] ?? '')),
            (int) ($last['seen'] ?? 0),
            (int) ($last['created'] ?? 0),
            !empty($last['error']) ? ' Error: ' . esc_html((string) $last['error']) : ''
        );
        echo '<div class="notice notice-info is-dismissible"><p>' . $msg . '</p></div>';
    }

    /** Turn Jotform API answers ({qid: {name, answer}}) into flat "name" / "name.subkey" keys. */
    private static function flatten_answers($answers): array
    {
        $flat = [];
        if (!is_array($answers)) {
            return $flat;
        }
        foreach ($answers as $qid => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = strtolower(trim((string) ($entry['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $answer = $entry['answer'] ?? '';
            if (is_array($answer)) {
                foreach ($answer as $k => $v) {
                    if (is_scalar($v) && trim((string) $v) !== '') {
                        $flat[$name . '.' . strtolower((string) $k)] = trim((string) $v);
                    }
                }
            } elseif (is_scalar($answer) && trim((string) $answer) !== '') {
                $flat[$name] = trim((string) $answer);
            }
        }
        return $flat;
    }

    /** Turn Jotform bracketed keys (q3_fullName[first]) into flat "fullname.first" keys. */
    private static function flatten(array $in, string $prefix = ''): array
    {
        $out = [];
        foreach ($in as $key => $value) {
            $clean = strtolower(preg_replace('/^q\d+_/', '', (string) $key));
            $clean = str_replace([']', '['], ['', '.'], $clean); // fullname[first] -> fullname.first
            $path  = $prefix === '' ? $clean : $prefix . '.' . $clean;
            if (is_array($value)) {
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = trim((string) $value);
            }
        }
        return $out;
    }

    private static function map_fields(array $flat): array
    {
        $mapped  = [];
        $matches = [];
        foreach (self::FIELD_ALIASES as $canonical => $aliases) {
            foreach (array_merge([$canonical], $aliases) as $alias) {
                foreach ($flat as $key => $value) {
                    $base = preg_replace('/\.\w+$/', '', (string) $key); // fullname.first -> fullname
                    if ($base === $alias && $value !== '') {
                        $matches[$canonical][$key] = $value; // keyed: later dupes overwrite, order kept
                    }
                }
            }
        }
        foreach ($matches as $canonical => $vals) {
            if ($canonical === 'customer_name') {
                // Compound name fields (fullname.first + fullname.last) join into one name.
                $mapped[$canonical] = implode(' ', array_unique(array_values($vals)));
            } else {
                // Otherwise prefer the longest value (most complete).
                usort($vals, fn($a, $b) => strlen($b) <=> strlen($a));
                $mapped[$canonical] = $vals[0];
            }
        }
        return $mapped;
    }
}

register_deactivation_hook(__FILE__, ['SA_Agent_Intake', 'deactivate']);
SA_Agent_Intake::init();

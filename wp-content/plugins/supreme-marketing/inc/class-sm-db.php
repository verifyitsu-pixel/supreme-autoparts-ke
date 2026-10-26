<?php
/**
 * Schema for the marketing plugin. Table prefix is the site's $wpdb->prefix.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_DB
{
    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sm_' . $name;
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql = [];

        $sql[] = 'CREATE TABLE ' . self::table('subscribers') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'unconfirmed',
            source VARCHAR(40) NOT NULL DEFAULT '',
            region VARCHAR(2) NOT NULL DEFAULT '',
            confirm_sent_at DATETIME NULL,
            consented_at DATETIME NULL,
            consent_source VARCHAR(60) NOT NULL DEFAULT '',
            consent_ip VARCHAR(45) NOT NULL DEFAULT '',
            paused_until DATETIME NULL,
            orders_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_spent DECIMAL(12,2) NOT NULL DEFAULT 0,
            last_order_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY status (status),
            KEY user_id (user_id),
            KEY last_order_at (last_order_at)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . self::table('queue') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscriber_id BIGINT UNSIGNED NULL,
            email VARCHAR(190) NOT NULL,
            flow VARCHAR(40) NOT NULL DEFAULT '',
            step VARCHAR(40) NOT NULL DEFAULT '',
            template VARCHAR(60) NOT NULL,
            campaign_id BIGINT UNSIGNED NULL,
            payload LONGTEXT NULL,
            send_at DATETIME NOT NULL,
            status VARCHAR(15) NOT NULL DEFAULT 'pending',
            requires_approval TINYINT(1) NOT NULL DEFAULT 0,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(250) NOT NULL DEFAULT '',
            dedupe_key VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY status_send_at (status, send_at),
            KEY email (email),
            KEY campaign_id (campaign_id)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . self::table('sends') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            queue_id BIGINT UNSIGNED NULL,
            campaign_id BIGINT UNSIGNED NULL,
            subscriber_id BIGINT UNSIGNED NULL,
            email VARCHAR(190) NOT NULL,
            template VARCHAR(60) NOT NULL,
            subject VARCHAR(250) NOT NULL DEFAULT '',
            test_send TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(15) NOT NULL DEFAULT 'sent',
            opened_at DATETIME NULL,
            open_count INT UNSIGNED NOT NULL DEFAULT 0,
            clicked_at DATETIME NULL,
            click_count INT UNSIGNED NOT NULL DEFAULT 0,
            sent_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY email (email),
            KEY template (template),
            KEY campaign_id (campaign_id),
            KEY sent_at (sent_at)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . self::table('send_products') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            send_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            sent_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY send_product (send_id, product_id),
            KEY email_sent (email, sent_at)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . self::table('campaigns') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'newsletter',
            template VARCHAR(60) NOT NULL,
            subject VARCHAR(250) NOT NULL DEFAULT '',
            heading VARCHAR(250) NOT NULL DEFAULT '',
            intro LONGTEXT NULL,
            segment_key VARCHAR(60) NOT NULL DEFAULT 'all_active',
            product_ids TEXT NULL,
            category VARCHAR(190) NOT NULL DEFAULT '',
            coupon_code VARCHAR(60) NOT NULL DEFAULT '',
            coupon_type VARCHAR(20) NOT NULL DEFAULT 'percent',
            coupon_amount DECIMAL(10,2) NULL,
            coupon_days INT UNSIGNED NOT NULL DEFAULT 14,
            coupon_free_shipping TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(15) NOT NULL DEFAULT 'draft',
            scheduled_at DATETIME NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            stats TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY status (status)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . self::table('events') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL DEFAULT '',
            subscriber_id BIGINT UNSIGNED NULL,
            type VARCHAR(40) NOT NULL,
            ref_id BIGINT UNSIGNED NULL,
            payload TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY type_created (type, created_at),
            KEY email (email),
            KEY ref_id (ref_id)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . self::table('links') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            send_id BIGINT UNSIGNED NOT NULL,
            url TEXT NOT NULL,
            clicks INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY send_id (send_id)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . self::table('welcome_codes') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            email VARCHAR(190) NOT NULL,
            email_norm VARCHAR(190) NOT NULL,
            code VARCHAR(40) NOT NULL,
            status VARCHAR(15) NOT NULL DEFAULT 'active',
            order_id BIGINT UNSIGNED NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            device_id VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            redeemed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY email_norm (email_norm),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . self::table('abuse_log') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind VARCHAR(40) NOT NULL,
            detail VARCHAR(250) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            email_norm VARCHAR(190) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            device_id VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY kind (kind),
            KEY created_at (created_at)
        ) $charset;";

        $sql[] = 'CREATE TABLE ' . self::table('order_norms') . " (
            email_norm VARCHAR(190) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (email_norm)
        ) $charset;";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
        update_option('sm_db_version', SUPM_DB_VERSION, false);
    }

    public static function maybe_upgrade(): void
    {
        if (get_option('sm_db_version') !== SUPM_DB_VERSION) {
            self::install();
        }
    }
}

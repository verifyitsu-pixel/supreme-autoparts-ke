#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# Map Railway MySQL plugin → WordPress DB env
# ---------------------------------------------------------------------------
if [[ -z "${WORDPRESS_DB_HOST:-}" && -n "${MYSQLHOST:-}" ]]; then
  export WORDPRESS_DB_HOST="${MYSQLHOST}:${MYSQLPORT:-3306}"
fi
[[ -z "${WORDPRESS_DB_USER:-}" && -n "${MYSQLUSER:-}" ]] && export WORDPRESS_DB_USER="$MYSQLUSER"
[[ -z "${WORDPRESS_DB_PASSWORD:-}" && -n "${MYSQLPASSWORD:-}" ]] && export WORDPRESS_DB_PASSWORD="$MYSQLPASSWORD"
[[ -z "${WORDPRESS_DB_NAME:-}" && -n "${MYSQLDATABASE:-}" ]] && export WORDPRESS_DB_NAME="$MYSQLDATABASE"
# Runtime guard: ensure Apache starts with only prefork MPM.
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf 2>/dev/null || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

export WORDPRESS_DB_HOST="${WORDPRESS_DB_HOST:-db:3306}"
export WORDPRESS_DB_USER="${WORDPRESS_DB_USER:-wordpress}"
export WORDPRESS_DB_PASSWORD="${WORDPRESS_DB_PASSWORD:-wordpress}"
export WORDPRESS_DB_NAME="${WORDPRESS_DB_NAME:-wordpress}"
export WP_HOME="${WP_HOME:-http://localhost:8080}"
export WP_SITEURL="${WP_SITEURL:-${WP_HOME}}"
export WOO_CURRENCY="${WOO_CURRENCY:-USD}"
export TZ="${TZ:-Africa/Nairobi}"
export WORDPRESS_ADMIN_USER="${WORDPRESS_ADMIN_USER:-admin}"
export WORDPRESS_ADMIN_PASSWORD="${WORDPRESS_ADMIN_PASSWORD:-adminpass}"
export WORDPRESS_ADMIN_EMAIL="${WORDPRESS_ADMIN_EMAIL:-calvin@supremeautoparts.co.ke}"
export WORDPRESS_TITLE="${WORDPRESS_TITLE:-Supreme Autoparts}"

export WORDPRESS_CONFIG_EXTRA="${WORDPRESS_CONFIG_EXTRA:-}
if (!defined('WP_HOME')) define('WP_HOME', getenv('WP_HOME') ?: 'http://localhost:8080');
if (!defined('WP_SITEURL')) define('WP_SITEURL', getenv('WP_SITEURL') ?: WP_HOME);
if (!defined('FS_METHOD')) define('FS_METHOD', 'direct');
"

sync_custom_content() {
  mkdir -p /var/www/html/wp-content/themes /var/www/html/wp-content/plugins /var/www/html/wp-content/mu-plugins
  if [[ -d /usr/src/wordpress/wp-content/themes/supreme-autoparts ]]; then
    rm -rf /var/www/html/wp-content/themes/supreme-autoparts
    cp -a /usr/src/wordpress/wp-content/themes/supreme-autoparts /var/www/html/wp-content/themes/
  fi
  if [[ -d /usr/src/wordpress/wp-content/plugins/supreme-autoparts-core ]]; then
    rm -rf /var/www/html/wp-content/plugins/supreme-autoparts-core
    cp -a /usr/src/wordpress/wp-content/plugins/supreme-autoparts-core /var/www/html/wp-content/plugins/
  fi
  if [[ -d /usr/src/wordpress/wp-content/plugins/whop-payments ]]; then
    rm -rf /var/www/html/wp-content/plugins/whop-payments
    cp -a /usr/src/wordpress/wp-content/plugins/whop-payments /var/www/html/wp-content/plugins/
  fi
  if [[ -d /usr/src/wordpress/wp-content/plugins/sa-brevo-mail ]]; then
    rm -rf /var/www/html/wp-content/plugins/sa-brevo-mail
    cp -a /usr/src/wordpress/wp-content/plugins/sa-brevo-mail /var/www/html/wp-content/plugins/
  fi
  if [[ -f /usr/src/wordpress/wp-content/mu-plugins/supreme-loader.php ]]; then
    cp -f /usr/src/wordpress/wp-content/mu-plugins/supreme-loader.php /var/www/html/wp-content/mu-plugins/ || true
  fi
  [[ -f /usr/src/wordpress/healthz.php ]] && cp -f /usr/src/wordpress/healthz.php /var/www/html/healthz.php
  if [[ -d /usr/src/supreme-data ]]; then
    mkdir -p /var/www/html/wp-content/plugins/supreme-autoparts-core/data
    cp -a /usr/src/supreme-data/. /var/www/html/wp-content/plugins/supreme-autoparts-core/data/ || true
  fi
  if [[ -d /usr/src/supreme-scripts ]]; then
    mkdir -p /var/www/html/wp-content/plugins/supreme-autoparts-core/scripts
    cp -a /usr/src/supreme-scripts/. /var/www/html/wp-content/plugins/supreme-autoparts-core/scripts/ || true
  fi
  chown -R www-data:www-data \
    /var/www/html/wp-content/themes/supreme-autoparts \
    /var/www/html/wp-content/plugins/supreme-autoparts-core \
    /var/www/html/wp-content/plugins/whop-payments \
    /var/www/html/wp-content/plugins/sa-brevo-mail 2>/dev/null || true
}

wait_for_db() {
  local host port
  host="${WORDPRESS_DB_HOST%%:*}"
  port="${WORDPRESS_DB_HOST##*:}"
  [[ "$host" == "$port" ]] && port=3306
  echo "[supreme] Waiting for MySQL at ${host}:${port}..."
  for _ in $(seq 1 90); do
    if mysqladmin ping -h"$host" -P"$port" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" --silent 2>/dev/null; then
      echo "[supreme] MySQL ready."
      return 0
    fi
    sleep 2
  done
  echo "[supreme] WARNING: MySQL not reachable after wait." >&2
  return 1
}

wp_as() {
  wp --allow-root --path=/var/www/html "$@"
}

bootstrap_wordpress() {
  # Wait until core files + wp-config exist (official entrypoint creates them)
  for _ in $(seq 1 60); do
    [[ -f /var/www/html/wp-settings.php && -f /var/www/html/wp-config.php ]] && break
    sleep 2
  done
  sync_custom_content
  wait_for_db || true

  if ! wp_as core is-installed 2>/dev/null; then
    echo "[supreme] Running wp core install..."
    wp_as core install \
      --url="$WP_HOME" \
      --title="$WORDPRESS_TITLE" \
      --admin_user="$WORDPRESS_ADMIN_USER" \
      --admin_password="$WORDPRESS_ADMIN_PASSWORD" \
      --admin_email="$WORDPRESS_ADMIN_EMAIL" \
      --skip-email || true
  else
    echo "[supreme] WordPress already installed — syncing URLs and admin email."
    wp_as option update home "$WP_HOME" || true
    wp_as option update siteurl "$WP_SITEURL" || true
    wp_as option update admin_email "$WORDPRESS_ADMIN_EMAIL" || true
    wp_as user update "$WORDPRESS_ADMIN_USER" --user_email="$WORDPRESS_ADMIN_EMAIL" 2>/dev/null || true
  fi

  wp_as option update timezone_string "Africa/Nairobi" || true
  wp_as rewrite structure '/%postname%/' --hard || true

  if ! wp_as plugin is-installed woocommerce 2>/dev/null; then
    echo "[supreme] Installing WooCommerce..."
    wp_as plugin install woocommerce --activate || true
  else
    wp_as plugin activate woocommerce || true
  fi

  wp_as plugin activate supreme-autoparts-core || true
  wp_as plugin activate whop-payments || true
  wp_as plugin activate sa-brevo-mail || true
  wp_as theme activate supreme-autoparts || true

  wp_as option update woocommerce_currency "$WOO_CURRENCY" || true
  wp_as option update woocommerce_default_country "KE" || true
  wp_as option update woocommerce_currency_pos "left" || true
  wp_as option update woocommerce_price_thousand_sep "," || true
  wp_as option update woocommerce_price_decimal_sep "." || true
  wp_as option update woocommerce_price_num_decimals "2" || true
  wp_as option update woocommerce_store_city "Nairobi" || true
  wp_as option update woocommerce_enable_guest_checkout "yes" || true

  if [[ "${SUPREME_SEED_ON_BOOT:-1}" == "1" ]]; then
    wp_as supreme seed-categories 2>/dev/null || true
    wp_as supreme seed-pages 2>/dev/null || true
  fi

  # Force customer-service email + www URLs (apex may be unbound on Railway).
  wp_as option update admin_email "${WORDPRESS_ADMIN_EMAIL}" || true
  wp_as option update woocommerce_email_from_address "${WORDPRESS_ADMIN_EMAIL}" || true
  wp_as option update woocommerce_email_from_name "Supreme Autoparts" || true
  # Force www — apex is unbound on Railway (Application not found).
  WWW_HOME="https://www.supremeautoparts.co.ke"
  wp_as option update home "$WWW_HOME" || true
  wp_as option update siteurl "$WWW_HOME" || true
  wp_as option update woocommerce_enable_myaccount_registration yes || true
  wp_as option update woocommerce_enable_signup_and_login_from_checkout yes || true
  wp_as option update users_can_register 1 || true
  wp_as option update woocommerce_enable_checkout_login_reminder yes || true
  # Email: Brevo plugin reads BREVO_API_KEY / BREVO_SMTP_* from env.
  echo "[supreme] Email From: Supreme Autoparts <${WORDPRESS_ADMIN_EMAIL}> — set BREVO_API_KEY for transactional delivery"

  # Ensure product_cat terms exist before rewrite flush / import.
  wp_as supreme seed-categories 2>/dev/null || true

  # Catalog recovery: wipe stuck boot-import flags when products are gone or forced.
  # SUPREME_FORCE_IMPORT=1 → clear sa_boot_import_* and re-import even if catalog non-empty.
  PRODUCT_COUNT="$(wp_as post list --post_type=product --post_status=publish --format=count 2>/dev/null || echo 0)"
  PRODUCT_COUNT="$(echo "$PRODUCT_COUNT" | tr -cd "0-9")"
  PRODUCT_COUNT="${PRODUCT_COUNT:-0}"
  echo "[supreme] Published product count: ${PRODUCT_COUNT}"

  if [[ "${SUPREME_FORCE_IMPORT:-0}" == "1" ]]; then
    echo "[supreme] SUPREME_FORCE_IMPORT=1 — clearing sa_boot_import_* flags"
    wp_as option delete sa_boot_import_done >/dev/null 2>&1 || true
    wp_as option delete sa_boot_import_batch50 >/dev/null 2>&1 || true
    wp_as option delete sa_boot_import_running >/dev/null 2>&1 || true
    wp_as option delete sa_boot_import_running_at >/dev/null 2>&1 || true
  elif [[ "$PRODUCT_COUNT" -eq 0 ]]; then
    echo "[supreme] Catalog empty — resetting sa_boot_import_* so boot re-import runs"
    wp_as option delete sa_boot_import_done >/dev/null 2>&1 || true
    wp_as option delete sa_boot_import_batch50 >/dev/null 2>&1 || true
    wp_as option delete sa_boot_import_running >/dev/null 2>&1 || true
    wp_as option delete sa_boot_import_running_at >/dev/null 2>&1 || true
  fi

  # Optional catalog import from baked scrape chunk (Shopify CDN photos only).
  # Prefer batch-with-images-400 when present, else batch-with-images-50.
  # Triggers when: SUPREME_FORCE_IMPORT=1, SUPREME_IMPORT_ON_BOOT=1, catalog empty, or first boot.
  BOOT_IMPORT_DONE="$(wp_as option get sa_boot_import_done 2>/dev/null || true)"
  if [[ -z "$BOOT_IMPORT_DONE" ]]; then
    BOOT_IMPORT_DONE="$(wp_as option get sa_boot_import_batch50 2>/dev/null || true)"
  fi
  NEED_IMPORT=0
  if [[ "${SUPREME_FORCE_IMPORT:-0}" == "1" ]]; then NEED_IMPORT=1; fi
  if [[ "${SUPREME_IMPORT_ON_BOOT:-0}" == "1" ]]; then NEED_IMPORT=1; fi
  if [[ "$PRODUCT_COUNT" -eq 0 ]]; then NEED_IMPORT=1; fi
  if [[ -z "${BOOT_IMPORT_DONE}" ]]; then NEED_IMPORT=1; fi

  if [[ "$NEED_IMPORT" == "1" ]]; then
    IMPORT_FILE="${SUPREME_IMPORT_FILE:-}"
    if [[ -z "$IMPORT_FILE" ]]; then
      for c in \
        /var/www/html/wp-content/plugins/supreme-autoparts-core/data/scrape/chunks/batch-with-images-400.ndjson \
        /usr/src/supreme-data/scrape/chunks/batch-with-images-400.ndjson \
        /var/www/html/data/scrape/chunks/batch-with-images-400.ndjson \
        /var/www/html/wp-content/plugins/supreme-autoparts-core/data/scrape/chunks/batch-with-images-50.ndjson \
        /usr/src/supreme-data/scrape/chunks/batch-with-images-50.ndjson \
        /var/www/html/data/scrape/chunks/batch-with-images-50.ndjson
      do
        if [[ -r "$c" ]]; then IMPORT_FILE="$c"; break; fi
      done
    fi
    # Default limit: 400 when using 400-batch, else 50.
    if [[ -z "${SUPREME_IMPORT_LIMIT:-}" ]]; then
      if [[ "$IMPORT_FILE" == *batch-with-images-400* ]]; then
        IMPORT_LIMIT=400
      else
        IMPORT_LIMIT=50
      fi
    else
      IMPORT_LIMIT="${SUPREME_IMPORT_LIMIT}"
    fi
    SKIP_IMG_FLAG=()
    # Default: store CDN meta + sideload. Set SUPREME_IMPORT_SKIP_IMAGES=1 for CDN-meta-only (faster boot).
    if [[ "${SUPREME_IMPORT_SKIP_IMAGES:-1}" == "1" ]]; then
      SKIP_IMG_FLAG=(--skip-images)
    fi
    if [[ -n "$IMPORT_FILE" && -r "$IMPORT_FILE" ]]; then
      echo "[supreme] Boot import: file=$IMPORT_FILE limit=$IMPORT_LIMIT require-images force=${SUPREME_FORCE_IMPORT:-0}"
      wp_as option update sa_boot_import_running 1 >/dev/null 2>&1 || true
      # Run in background so healthchecks stay green while images sideload.
      (
        mkdir -p /var/www/html/wp-content/uploads
        wp_as option update sa_boot_import_running_at "$(date +%s)" >/dev/null 2>&1 || true
        wp_as supreme seed-categories >> /var/www/html/wp-content/uploads/sa-boot-import.log 2>&1 || true
        wp_as supreme import-ndjson --file="$IMPORT_FILE" --limit="$IMPORT_LIMIT" --require-images "${SKIP_IMG_FLAG[@]}" \
          >> /var/www/html/wp-content/uploads/sa-boot-import.log 2>&1 \
          || echo "[supreme] Boot import finished with errors (see sa-boot-import.log)."
        wp_as rewrite flush --hard >> /var/www/html/wp-content/uploads/sa-boot-import.log 2>&1 || true
        AFTER_COUNT="$(wp_as post list --post_type=product --post_status=publish --format=count 2>/dev/null || echo 0)"
        AFTER_COUNT="$(echo "$AFTER_COUNT" | tr -cd "0-9")"
        AFTER_COUNT="${AFTER_COUNT:-0}"
        echo "[supreme] Boot import after-count=${AFTER_COUNT}" >> /var/www/html/wp-content/uploads/sa-boot-import.log
        if [[ "$AFTER_COUNT" -gt 0 ]]; then
          wp_as option update sa_boot_import_done 1 >/dev/null 2>&1 || true
          wp_as option update sa_boot_import_batch50 1 >/dev/null 2>&1 || true
        else
          echo "[supreme] Boot import produced 0 products — leaving flags clear for retry." >> /var/www/html/wp-content/uploads/sa-boot-import.log
          wp_as option delete sa_boot_import_done >/dev/null 2>&1 || true
          wp_as option delete sa_boot_import_batch50 >/dev/null 2>&1 || true
        fi
        wp_as option delete sa_boot_import_running >/dev/null 2>&1 || true
        wp_as option delete sa_boot_import_running_at >/dev/null 2>&1 || true
        echo "[supreme] Boot import finished at $(date -Iseconds)" >> /var/www/html/wp-content/uploads/sa-boot-import.log
      ) &
    else
      echo "[supreme] Boot import requested but batch NDJSON not found." >&2
    fi
  else
    echo "[supreme] Boot import skipped (catalog has ${PRODUCT_COUNT} products; flags present)."
  fi

  # Flush product / product_cat rewrite rules after seed (and again after import in bg).
  wp_as rewrite flush --hard || true
  echo "[supreme] Bootstrap complete."
}

# Ensure healthz + custom code are in the image source tree for volume seeding
mkdir -p /usr/src/wordpress/wp-content/mu-plugins
if [[ -f /var/www/html/../.. ]]; then :; fi
# healthz already copied into image at /usr/src/wordpress/healthz.php via Dockerfile

# Background bootstrap after Apache/official entrypoint brings files online
(
  sleep 8
  bootstrap_wordpress || echo "[supreme] Bootstrap finished with warnings."
) &

exec docker-entrypoint.sh "$@"

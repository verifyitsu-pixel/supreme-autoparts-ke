#!/usr/bin/env bash
# Apply custom_logo, site_icon, and WooCommerce email header logo on the live WP.
set -euo pipefail
cd /var/www/html
THEME_ASSETS="wp-content/themes/supreme-autoparts/assets"
WP=(wp --allow-root --path=/var/www/html)

logo_dark="${THEME_ASSETS}/logo.png"
logo_light="${THEME_ASSETS}/logo-light.png"
icon="${THEME_ASSETS}/icon.png"

for f in "$logo_dark" "$logo_light" "$icon"; do
  if [[ ! -f "$f" ]]; then
    echo "MISSING: $f" >&2
    exit 1
  fi
done

import_file() {
  local file="$1" title="$2"
  local id
  id=$("${WP[@]}" post list --post_type=attachment --title="$title" --field=ID --posts_per_page=1 2>/dev/null | head -1 || true)
  if [[ -n "${id:-}" && "$id" =~ ^[0-9]+$ ]]; then
    echo "$id"
    return 0
  fi
  "${WP[@]}" media import "$file" --title="$title" --porcelain
}

LOGO_ID=$(import_file "$logo_dark" "Supreme Autoparts Logo Dark")
LIGHT_ID=$(import_file "$logo_light" "Supreme Autoparts Logo Light")
ICON_ID=$(import_file "$icon" "Supreme Autoparts Icon")

echo "LOGO_ID=$LOGO_ID LIGHT_ID=$LIGHT_ID ICON_ID=$ICON_ID"

"${WP[@]}" theme mod set custom_logo "$LOGO_ID"
"${WP[@]}" option update site_icon "$ICON_ID"

LIGHT_URL=$("${WP[@]}" eval "echo wp_get_attachment_url((int) $LIGHT_ID);")
if [[ -z "${LIGHT_URL:-}" ]]; then
  LIGHT_URL="https://www.supremeautoparts.co.ke/wp-content/themes/supreme-autoparts/assets/logo-light.png"
fi
LIGHT_URL="${LIGHT_URL/http:\/\//https:\/\/}"

"${WP[@]}" option update woocommerce_email_header_image "$LIGHT_URL"
"${WP[@]}" option update sa_email_logo_url "$LIGHT_URL"

"${WP[@]}" cache flush 2>/dev/null || true
"${WP[@]}" rewrite flush 2>/dev/null || true

echo "CUSTOM_LOGO=$("${WP[@]}" theme mod get custom_logo)"
echo "SITE_ICON=$("${WP[@]}" option get site_icon)"
echo "EMAIL_LOGO_URL=$LIGHT_URL"
echo "BRANDING_APPLY_OK"

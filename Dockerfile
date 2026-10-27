# Supreme Autoparts — WordPress + WooCommerce (Railway / Docker)
FROM wordpress:php8.3-apache

# Ensure only the prefork MPM is loaded (Apache otherwise may load multiple MPMs)
RUN a2dismod mpm_event mpm_worker mpm_itk 2>/dev/null || true \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork

# WP-CLI
RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Tools used by entrypoint / import
RUN apt-get update && apt-get install -y --no-install-recommends \
        less mariadb-client unzip curl \
    && rm -rf /var/lib/apt/lists/*

# Bake WooCommerce into the image (pin ~11.1.0) so every deploy has it on disk
# immediately — avoids race where volume/redeploy drops plugins while DB still
# thinks Woo is installed.
ARG WOOCOMMERCE_VERSION=11.1.0
RUN mkdir -p /usr/src/wordpress/wp-content/plugins \
    && curl -fsSL -o /tmp/woocommerce.zip \
        "https://downloads.wordpress.org/plugin/woocommerce.${WOOCOMMERCE_VERSION}.zip" \
    && unzip -q /tmp/woocommerce.zip -d /usr/src/wordpress/wp-content/plugins \
    && rm -f /tmp/woocommerce.zip \
    && test -f /usr/src/wordpress/wp-content/plugins/woocommerce/woocommerce.php \
    && chown -R www-data:www-data /usr/src/wordpress/wp-content/plugins/woocommerce

# Official WooCommerce PayPal Payments (WordPress.org), baked like WooCommerce
ARG PAYPAL_PAYMENTS_VERSION=4.1.3
RUN curl -fsSL -o /tmp/ppcp.zip \
        "https://downloads.wordpress.org/plugin/woocommerce-paypal-payments.${PAYPAL_PAYMENTS_VERSION}.zip" \
    && unzip -q /tmp/ppcp.zip -d /usr/src/wordpress/wp-content/plugins \
    && rm -f /tmp/ppcp.zip \
    && test -f /usr/src/wordpress/wp-content/plugins/woocommerce-paypal-payments/woocommerce-paypal-payments.php

# Official Jotform AI Chatbot (WordPress.org), baked so redeploys never wipe it (agent "Ashley")
ARG JOTFORM_AI_CHATBOT_VERSION=3.8.2
RUN curl -fsSL -o /tmp/jaic.zip \
        "https://downloads.wordpress.org/plugin/jotform-ai-chatbot.${JOTFORM_AI_CHATBOT_VERSION}.zip" \
    && unzip -q /tmp/jaic.zip -d /usr/src/wordpress/wp-content/plugins \
    && rm -f /tmp/jaic.zip \
    && test -f /usr/src/wordpress/wp-content/plugins/jotform-ai-chatbot/jotform-ai-chatbot.php

# Forminator (WordPress.org), baked like WooCommerce - site forms (careers/booking/roadside)
ARG FORMINATOR_VERSION=1.44.2
RUN curl -fsSL -o /tmp/forminator.zip \
        "https://downloads.wordpress.org/plugin/forminator.${FORMINATOR_VERSION}.zip" \
    && unzip -q /tmp/forminator.zip -d /usr/src/wordpress/wp-content/plugins \
    && rm -f /tmp/forminator.zip \
    && test -f /usr/src/wordpress/wp-content/plugins/forminator/forminator.php \
    && chown -R www-data:www-data /usr/src/wordpress/wp-content/plugins/forminator


# Matomo Analytics for WordPress (self-hosted analytics - data stays in the site's own DB)
ARG MATOMO_VERSION=5.13.1
RUN curl -fsSL -o /tmp/matomo.zip \
        "https://downloads.wordpress.org/plugin/matomo.${MATOMO_VERSION}.zip" \
    && unzip -q /tmp/matomo.zip -d /usr/src/wordpress/wp-content/plugins \
    && rm -f /tmp/matomo.zip \
    && test -f /usr/src/wordpress/wp-content/plugins/matomo/matomo.php \
    && chown -R www-data:www-data /usr/src/wordpress/wp-content/plugins/matomo

# dompdf (PDF receipts / invoices attached to customer emails). Release zip bundles its deps + DejaVu fonts.
ARG DOMPDF_VERSION=3.1.6
RUN mkdir -p /usr/src/sa-lib \
    && curl -fsSL -o /tmp/dompdf.zip "https://github.com/dompdf/dompdf/releases/download/v${DOMPDF_VERSION}/dompdf-${DOMPDF_VERSION}.zip" \
    && unzip -q /tmp/dompdf.zip -d /usr/src/sa-lib \
    && rm -f /tmp/dompdf.zip \
    && test -f /usr/src/sa-lib/dompdf/autoload.inc.php \
    && chown -R www-data:www-data /usr/src/sa-lib

# Custom theme + plugins (baked into image for Git auto-deploy)
COPY wp-content/themes/supreme-autoparts /usr/src/wordpress/wp-content/themes/supreme-autoparts
COPY wp-content/plugins/supreme-autoparts-core /usr/src/wordpress/wp-content/plugins/supreme-autoparts-core
COPY wp-content/plugins/whop-payments /usr/src/wordpress/wp-content/plugins/whop-payments
COPY wp-content/plugins/sa-brevo-mail /usr/src/wordpress/wp-content/plugins/sa-brevo-mail
COPY wp-content/plugins/sa-geo-currency /usr/src/wordpress/wp-content/plugins/sa-geo-currency
COPY wp-content/plugins/pesapal-woocommerce /usr/src/wordpress/wp-content/plugins/pesapal-woocommerce
COPY wp-content/plugins/transactworld-payment-integration /usr/src/wordpress/wp-content/plugins/transactworld-payment-integration
COPY wp-content/plugins/supreme-agent-intake /usr/src/wordpress/wp-content/plugins/supreme-agent-intake
COPY wp-content/plugins/supreme-marketing /usr/src/wordpress/wp-content/plugins/supreme-marketing
COPY wp-content/advanced-cache.php /usr/src/wordpress/wp-content/advanced-cache.php
COPY wp-content/mu-plugins /usr/src/wordpress/wp-content/mu-plugins
COPY data /usr/src/supreme-data
COPY scripts /usr/src/supreme-scripts
COPY healthz.php /usr/src/wordpress/healthz.php
COPY llms.txt /usr/src/wordpress/llms.txt
COPY llms-full.txt /usr/src/wordpress/llms-full.txt
COPY 602ba228c9d1427d2c329ca9d9c82c4a.txt /usr/src/wordpress/602ba228c9d1427d2c329ca9d9c82c4a.txt
COPY wordpress-entrypoint.sh /usr/local/bin/wordpress-entrypoint.sh
# OPcache: the base wordpress image ships the extension but never enables it.
# Biggest cold-render lever: PHP otherwise re-compiles WP+Woo (~10k files) on every request.
RUN docker-php-ext-install opcache \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=60000'; \
        echo 'opcache.revalidate_freq=2'; \
        echo 'opcache.validate_timestamps=1'; \
    } > /usr/local/etc/php/conf.d/zz-sa-opcache.ini

RUN printf "upload_max_filesize=32M\npost_max_size=48M\nmax_input_vars=5000\n" > /usr/local/etc/php/conf.d/zz-sa-uploads.ini

RUN chmod +x /usr/local/bin/wordpress-entrypoint.sh \
    && chown -R www-data:www-data \
        /usr/src/wordpress/wp-content/themes/supreme-autoparts \
        /usr/src/wordpress/wp-content/plugins/woocommerce \
        /usr/src/wordpress/wp-content/plugins/supreme-autoparts-core \
        /usr/src/wordpress/wp-content/plugins/whop-payments \
        /usr/src/wordpress/wp-content/plugins/sa-brevo-mail \
        /usr/src/wordpress/wp-content/plugins/sa-geo-currency \
        /usr/src/wordpress/wp-content/plugins/supreme-marketing

# Apache: allow .htaccess for permalinks
COPY apache-security.conf /etc/apache2/conf-available/store-security.conf
RUN a2enmod rewrite headers expires \
    && a2enconf store-security \
    && apache2ctl configtest

ENTRYPOINT ["wordpress-entrypoint.sh"]
CMD ["apache2-foreground"]

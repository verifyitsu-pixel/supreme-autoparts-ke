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

# Custom theme + plugins (baked into image for Git auto-deploy)
COPY wp-content/themes/supreme-autoparts /usr/src/wordpress/wp-content/themes/supreme-autoparts
COPY wp-content/plugins/supreme-autoparts-core /usr/src/wordpress/wp-content/plugins/supreme-autoparts-core
COPY wp-content/plugins/whop-payments /usr/src/wordpress/wp-content/plugins/whop-payments
COPY wp-content/plugins/sa-brevo-mail /usr/src/wordpress/wp-content/plugins/sa-brevo-mail
COPY wp-content/plugins/sa-geo-currency /usr/src/wordpress/wp-content/plugins/sa-geo-currency
COPY wp-content/mu-plugins /usr/src/wordpress/wp-content/mu-plugins
COPY data /usr/src/supreme-data
COPY scripts /usr/src/supreme-scripts
COPY healthz.php /usr/src/wordpress/healthz.php
COPY wordpress-entrypoint.sh /usr/local/bin/wordpress-entrypoint.sh

RUN chmod +x /usr/local/bin/wordpress-entrypoint.sh \
    && chown -R www-data:www-data \
        /usr/src/wordpress/wp-content/themes/supreme-autoparts \
        /usr/src/wordpress/wp-content/plugins/supreme-autoparts-core \
        /usr/src/wordpress/wp-content/plugins/whop-payments \
        /usr/src/wordpress/wp-content/plugins/sa-brevo-mail \
        /usr/src/wordpress/wp-content/plugins/sa-geo-currency

# Apache: allow .htaccess for permalinks
RUN a2enmod rewrite headers expires

ENTRYPOINT ["wordpress-entrypoint.sh"]
CMD ["apache2-foreground"]

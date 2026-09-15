# Supreme Autoparts — WordPress + WooCommerce (Railway / Docker)
FROM wordpress:php8.3-apache

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
COPY wp-content/mu-plugins /usr/src/wordpress/wp-content/mu-plugins
COPY data /usr/src/supreme-data
COPY scripts /usr/src/supreme-scripts
COPY healthz.php /usr/src/wordpress/healthz.php
COPY wordpress-entrypoint.sh /usr/local/bin/wordpress-entrypoint.sh

RUN chmod +x /usr/local/bin/wordpress-entrypoint.sh \
    && chown -R www-data:www-data \
        /usr/src/wordpress/wp-content/themes/supreme-autoparts \
        /usr/src/wordpress/wp-content/plugins/supreme-autoparts-core

# Apache: allow .htaccess for permalinks
RUN a2enmod rewrite headers expires

ENTRYPOINT ["wordpress-entrypoint.sh"]
CMD ["apache2-foreground"]

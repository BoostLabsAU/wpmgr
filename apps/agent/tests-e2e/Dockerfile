FROM wordpress:6.8-php8.3-apache

# Install phpredis via PECL and enable it.
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install WP-CLI.
RUN curl -sS -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# WP-CLI needs to run as www-data inside containers.
RUN echo "#!/bin/sh\n/usr/local/bin/wp --allow-root \"\$@\"" > /usr/local/bin/wpcli \
    && chmod +x /usr/local/bin/wpcli

# assert.php is the staged CLI tool; copy it into the image.
COPY assert.php /usr/local/bin/wpmgr-assert.php
RUN chmod +x /usr/local/bin/wpmgr-assert.php

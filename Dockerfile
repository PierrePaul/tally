FROM php:8.4.27-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/web/
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && a2enmod rewrite expires

# System libraries: the mysql client is for drush, the rest for the PHP extensions.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        default-mysql-client unzip git \
        libjpeg-dev libwebp-dev libpng-dev libfreetype6-dev libzip-dev libicu-dev zlib1g-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j "$(nproc)" bcmath exif gd intl opcache pdo_mysql zip; \
    rm -rf /var/lib/apt/lists/*

# Drupal needs gd, pdo_mysql; Tally also needs intl (French dates and money),
# sodium (encrypted Stripe keys, built in) and gd (dompdf). PHP logs to stderr.
RUN { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=4000'; \
        echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini; \
    { \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_log = /dev/stderr'; \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 16M'; \
        echo 'post_max_size = 32M'; \
        echo 'max_execution_time = 100'; \
    } > /usr/local/etc/php/conf.d/zzz-tally.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
COPY . /var/www/html/
RUN composer install --no-dev --no-interaction --optimize-autoloader \
 && cp deploy/settings.php web/sites/default/settings.php \
 && ln -s /var/www/html/vendor/bin/drush /usr/local/bin/drush \
 && mkdir -p /private web/sites/default/files \
 && chown -R www-data:www-data /private web/sites/default/files

EXPOSE 80
CMD ["apache2-foreground"]

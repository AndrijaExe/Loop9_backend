FROM composer:2 AS build

WORKDIR /app

COPY . .

# The committed .env contains fail-closed, non-secret defaults. Runtime secrets
# still come from the host/orchestrator environment and override these values.
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev unzip \
    && docker-php-ext-install intl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Enable Symfony front-controller rewrites from public/.htaccess.
# Cap JSON API bodies at 64 KiB to bound memory before PHP parses the payload.
RUN printf '%s\n' \
    '<Directory /var/www/html/public>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '    LimitRequestBody 65536' \
    '</Directory>' \
    > /etc/apache2/conf-available/symfony.conf \
    && a2enconf symfony

# Production OPcache: compile once, do not revalidate timestamps in the image.
RUN printf '%s\n' \
    'opcache.enable=1' \
    'opcache.memory_consumption=128' \
    'opcache.interned_strings_buffer=16' \
    'opcache.max_accelerated_files=10000' \
    'opcache.validate_timestamps=0' \
    'opcache.revalidate_freq=0' \
    > /usr/local/etc/php/conf.d/opcache-prod.ini

WORKDIR /var/www/html

COPY --from=build /app /var/www/html

RUN mkdir -p var/cache var/log var/share \
    && chown -R www-data:www-data var

EXPOSE 80

CMD ["apache2-foreground"]

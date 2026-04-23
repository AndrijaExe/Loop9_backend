FROM composer:2 AS build

WORKDIR /app

COPY . .

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
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY --from=build /app /var/www/html

RUN mkdir -p var/cache var/log var/share \
    && chown -R www-data:www-data var

EXPOSE 80

CMD ["apache2-foreground"]

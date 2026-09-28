# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
ARG COMPOSER_NO_DEV=0
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist \
        $( [ "$COMPOSER_NO_DEV" = "1" ] && echo "--no-dev" ) \
        --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-redis
COPY . .
RUN composer dump-autoload --optimize --no-scripts

# ---------------------------------------------------------------------------
# Runtime: one image for the API (php-fpm), queue workers, scheduler and
# migrations - they differ only by command.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql redis pcntl opcache bcmath

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html

RUN rm -f bootstrap/cache/*.php \
    && mkdir -p storage/app/imports storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi

EXPOSE 9000
CMD ["php-fpm"]

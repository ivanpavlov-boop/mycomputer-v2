FROM php:8.4-fpm-alpine AS app

WORKDIR /var/www/html

RUN apk add --no-cache \
    bash \
    icu-dev \
    libzip-dev \
    mysql-client \
    oniguruma-dev \
    tzdata \
    unzip \
    && docker-php-ext-install \
    bcmath \
    intl \
    mbstring \
    opcache \
    pcntl \
    pdo_mysql \
    zip

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]

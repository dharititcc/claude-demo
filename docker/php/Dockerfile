# syntax=docker/dockerfile:1
FROM php:8.3-fpm-alpine

# ─── System deps ───────────────────────────────
RUN apk add --no-cache \
        bash git curl unzip icu-dev oniguruma-dev libzip-dev \
        libpng-dev libjpeg-turbo-dev freetype-dev \
        $PHPIZE_DEPS

# ─── PHP extensions ────────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring bcmath intl zip gd pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis

# ─── Composer ──────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies first (better layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist || true

COPY . .
RUN composer install --no-interaction --optimize-autoloader --no-dev || true \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]

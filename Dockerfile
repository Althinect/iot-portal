# syntax=docker/dockerfile:1.7

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.4

FROM ${PHP_IMAGE} AS php-base

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN install-php-extensions \
    bcmath \
    gd \
    intl \
    opcache \
    pcntl \
    pdo_mysql \
    pdo_pgsql \
    redis \
    sockets \
    zip

FROM composer:2 AS composer-bin

FROM node:22-alpine AS frontend

WORKDIR /app

ARG VITE_APP_NAME="LMU IoT Portal"
ARG VITE_REVERB_APP_KEY="lmu-iot-portal-key"
ARG VITE_REVERB_HOST=""
ARG VITE_REVERB_PORT="443"
ARG VITE_REVERB_SCHEME="https"

ENV VITE_APP_NAME="${VITE_APP_NAME}" \
    VITE_REVERB_APP_KEY="${VITE_REVERB_APP_KEY}" \
    VITE_REVERB_HOST="${VITE_REVERB_HOST}" \
    VITE_REVERB_PORT="${VITE_REVERB_PORT}" \
    VITE_REVERB_SCHEME="${VITE_REVERB_SCHEME}"

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./

RUN npm run build

FROM php-base AS vendor

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-progress \
    --no-interaction \
    --no-scripts \
    --no-autoloader

COPY . .

RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && php artisan package:discover --ansi \
    && php artisan filament:assets \
    && mkdir -p \
        bootstrap/cache \
        public \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/octane/xdg/config \
        storage/octane/xdg/data \
    && printf '%s\n' \
        '<?php' \
        '' \
        '$_SERVER["APP_BASE_PATH"] = $_ENV["APP_BASE_PATH"] ?? $_SERVER["APP_BASE_PATH"] ?? __DIR__."/..";' \
        '$_SERVER["APP_PUBLIC_PATH"] = $_ENV["APP_PUBLIC_PATH"] ?? $_SERVER["APP_PUBLIC_PATH"] ?? __DIR__;' \
        '' \
        'require __DIR__."/../vendor/laravel/octane/bin/frankenphp-worker.php";' \
        > public/frankenphp-worker.php \
    && php artisan storage:link --force

FROM php-base AS production

ENV APP_BASE_PATH=/app \
    APP_PUBLIC_PATH=/app/public \
    OCTANE_SERVER=frankenphp \
    OCTANE_HOST=0.0.0.0 \
    OCTANE_PORT=8000 \
    OCTANE_ADMIN_HOST=127.0.0.1 \
    OCTANE_ADMIN_PORT=2019 \
    OCTANE_MAX_REQUESTS=500 \
    XDG_CONFIG_HOME=/app/storage/octane/xdg/config \
    XDG_DATA_HOME=/app/storage/octane/xdg/data

COPY --from=vendor --chown=www-data:www-data /app /app
COPY --from=frontend --chown=www-data:www-data /app/public/build /app/public/build

RUN chmod +x /app/scripts/start-production-octane.sh \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /app/public/build

USER www-data

EXPOSE 8000

CMD ["./scripts/start-production-octane.sh"]

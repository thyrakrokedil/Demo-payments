# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --ignore-platform-req=ext-pdo_sqlite \
    --ignore-platform-req=ext-curl

FROM php:8.3-cli-alpine

# pdo_sqlite and curl ship with the official image; fail the build early if one is missing.
RUN php -m | grep -qi pdo_sqlite && php -m | grep -qi curl

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY bin ./bin
COPY config ./config
COPY database ./database
COPY public ./public
COPY src ./src
COPY composer.json ./

RUN mkdir -p /var/data

ENV DB_PATH=/var/data/mockpay.sqlite \
    DB_MIGRATE_ON_BOOT=true \
    PHP_CLI_SERVER_WORKERS=4 \
    AUTH_USERNAME=username \
    AUTH_PASSWORD=password \
    APP_DEBUG=false \
    LOG_LEVEL=info \
    PUBLIC_URL=http://localhost:8888 \
    CALLBACK_TIMEOUT_SECONDS=5

EXPOSE 8888

HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8888/health") === false ? 1 : 0);'

CMD ["php", "-S", "0.0.0.0:8888", "-t", "public", "public/index.php"]

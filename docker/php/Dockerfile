# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

# bcmath and intl: needed by the backward compatibility checker in tools/bc-check.
# pdo_mysql and pdo_pgsql: integration tests against MySQL and PostgreSQL (make test-db).
RUN apk add --no-cache git unzip icu-libs libpq icu-dev postgresql-dev linux-headers $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && docker-php-ext-install bcmath intl pdo_mysql pdo_pgsql \
    && apk del $PHPIZE_DEPS linux-headers icu-dev postgresql-dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-dev.ini

ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} app && adduser -D -u ${UID} -G app app

ENV COMPOSER_HOME=/home/app/.composer
USER app
WORKDIR /app

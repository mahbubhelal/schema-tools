FROM php:8.5.12-cli-alpine3.23

RUN apk update && apk add --no-cache \
    $PHPIZE_DEPS zip unzip

RUN pecl install pcov && docker-php-ext-enable pcov

RUN echo "memory_limit=512M" > "$PHP_INI_DIR/conf.d/memory-limit.ini"

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/app

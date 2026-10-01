FROM php:8.4-fpm

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libsqlite3-dev \
        libicu-dev \
        unzip \
        git \
    && docker-php-ext-install pdo_mysql pdo_sqlite sockets bcmath zip intl pcntl \
    && docker-php-ext-enable opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# remap www-data to the host UID/GID so bind-mounted files stay writable on Linux
RUN groupmod -o -g $GID www-data \
    && usermod -o -u $UID -g $GID www-data

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/html

COPY php.ini /usr/local/etc/php/conf.d/app.ini
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /tmp/composer \
    && chown -R www-data:www-data /var/www /tmp/composer

USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

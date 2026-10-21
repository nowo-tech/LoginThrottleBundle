# FrankenPHP (Dunglas) - PHP 8.5 Alpine (Symfony 8) (REQ-DEMO-010)
# https://frankenphp.dev/docs/docker/
FROM dunglas/frankenphp:1-php8.5-alpine

RUN apk add --no-cache git unzip bash
RUN install-php-extensions intl zip pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Mounted `.:/app` / vendor nested repos need Git 2.35+ safe.directory
RUN git config --global --add safe.directory '*'

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
ENV SERVER_NAME=:80

COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile.dev

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]

FROM golang:1.24-bookworm AS geoip-builder

# Pin the upstream repo release tag here.
# This command accepts repo tags, and the project publishes dated GitHub
# releases that are easier to track than Go pseudo-versions.
# Available tags:
# - https://github.com/v2fly/geoip/releases
ARG GEOIP_VERSION=202604050243

RUN GOBIN=/out go install github.com/v2fly/geoip@${GEOIP_VERSION}

FROM composer:2 AS composer
FROM oven/bun:1.3.5 AS frontend-builder

WORKDIR /app/frontend

COPY ./frontend/package.json ./frontend/bun.lock /app/frontend/

RUN bun install --frozen-lockfile

COPY ./frontend /app/frontend

RUN bun run build

FROM php:8.2-cli

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    dnsutils \
    ipcalc \
    libzip-dev \
    ntpsec \
    whois \
    zip \
    zlib1g-dev \
  && docker-php-ext-configure pcntl --enable-pcntl \
  && docker-php-ext-install pcntl zip \
  && pecl install -o -f ev \
  && docker-php-ext-enable ev \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=geoip-builder /out/geoip /app/geoip/geoip

# php.ini
COPY .docker/php/docker-php.ini /usr/local/etc/php/conf.d/docker-php.ini
COPY .docker/php/docker-php-disable-assertions.ini /usr/local/etc/php/conf.d/docker-php-disable-assertions.ini
COPY .docker/php/docker-php-enable-jit.ini /usr/local/etc/php/conf.d/docker-php-enable-jit.ini

WORKDIR /app

COPY ./composer.json /app/

RUN composer install --no-interaction

COPY ./src ./config ./storage /app/
COPY --from=frontend-builder /app/public /app/public
COPY ./index.php /app/

EXPOSE 8080

CMD [ "php", "./index.php" ]

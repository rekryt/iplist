# ---------------------------------------------------------------------------
# Стадия сборки внешних бинарников.
#
# Зачем отдельная стадия: go.mod у v2fly/geoip требует go >= 1.25, а
# `apt-get install golang` в Debian bookworm (база php:8.2-cli) даёт Go 1.19,
# который даже не умеет докачивать нужный toolchain (это появилось в Go 1.21).
# Из-за этого сборка внутри финального образа падала на `go build`.
# Заодно Go-тулчейн больше не попадает в рантайм-образ.
# ---------------------------------------------------------------------------
FROM golang:1.25-bookworm AS tools

# Пиннинг тега: без него `git clone` тянет HEAD и сборка невоспроизводима
ARG GEOIP_REF=202607171233
RUN git clone --depth 1 --branch ${GEOIP_REF} https://github.com/v2fly/geoip.git /src/geoip \
  && cd /src/geoip \
  && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/geoip . \
  && test -x /out/geoip

# ---------------------------------------------------------------------------
# Рантайм-образ
# ---------------------------------------------------------------------------
FROM php:8.2-cli

RUN apt-get update

# dependencies
RUN apt-get install -y ntpsec whois dnsutils ipcalc git

# composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer \
  && chmod 755 /usr/bin/composer

# pcntl
RUN docker-php-ext-configure pcntl --enable-pcntl \
  && docker-php-ext-install pcntl

# pecl/ev
RUN pecl install -o -f ev \
  && docker-php-ext-enable ev

# zip
RUN apt-get install -y libzip-dev zlib1g-dev zip \
  && docker-php-ext-install zip

# geoip (собран на стадии tools)
COPY --from=tools /out/geoip /app/geoip/geoip

# sing-box — официальный релизный tarball, без сборки из исходников.
# Берём именно -glibc сборку: обычная (без суффикса) динамически линкуется с
# libcronet.so, которая лежит рядом в архиве, и требует ставить ещё и её;
# -glibc самодостаточна и содержит нужную команду `rule-set compile`.
# TARGETARCH подставляет BuildKit (amd64/arm64); дефолт нужен для сборки без него.
ARG SINGBOX_VERSION=1.13.15
ARG TARGETARCH=amd64
RUN set -eux; \
  name="sing-box-${SINGBOX_VERSION}-linux-${TARGETARCH}-glibc"; \
  curl -fsSL -o /tmp/sing-box.tar.gz \
    "https://github.com/SagerNet/sing-box/releases/download/v${SINGBOX_VERSION}/${name}.tar.gz"; \
  tar -xzf /tmp/sing-box.tar.gz -C /tmp; \
  install -m 0755 "/tmp/${name}/sing-box" /usr/local/bin/sing-box; \
  rm -rf /tmp/sing-box.tar.gz "/tmp/${name}"; \
  sing-box version

RUN rm -rf /var/lib/apt/lists/*

# php.ini
ADD .docker/php/docker-php.ini /usr/local/etc/php/conf.d/docker-php-enable-jit.ini
ADD .docker/php/docker-php-disable-assertions.ini /usr/local/etc/php/conf.d/docker-php-disable-assertions.ini
ADD .docker/php/docker-php-enable-jit.ini /usr/local/etc/php/conf.d/docker-php-enable-jit.ini

RUN apt-get clean

COPY ./src/ /app/src/
COPY ./config/ /app/config/
COPY ./storage/ /app/storage/
COPY ./public/ /app/public/
COPY ./composer.json /app/
COPY ./index.php /app/

WORKDIR /app

RUN composer install --no-interaction

EXPOSE 8080

CMD [ "php", "./index.php" ]

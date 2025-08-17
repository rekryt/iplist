FROM php:8.2-cli

RUN apt-get update

# dependencies
RUN apt-get install -y ntpsec whois dnsutils ipcalc golang git

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

RUN git clone https://github.com/v2fly/geoip.git \
  && cd geoip && go build .

EXPOSE 8080

CMD [ "php", "./index.php" ]

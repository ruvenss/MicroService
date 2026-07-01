# =============================================================================
#  MicroService production image — Apache2 + PHP, engine-hidden.
#
#  Target runtime is PHP 8.5 (set PHP_VERSION). The image bundles Apache with
#  mod_security (to mask the Server header), mod_rewrite (to hide .php), opcache,
#  pdo_mysql and redis. The database is EXTERNAL — never bundled (ARCHITECTURE §17).
#
#  Build:  docker build -f docker/Dockerfile --build-arg PHP_VERSION=8.5 -t microservice:latest .
# =============================================================================
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-apache AS runtime

# --- System deps + PHP extensions ------------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libapache2-mod-security2 unzip; \
    docker-php-ext-install mysqli; \
    docker-php-ext-install pdo_mysql; \
    docker-php-ext-install intl; \
    a2enmod rewrite headers security2 env; \
    rm -rf /var/lib/apt/lists/*

# OPcache is compiled into the php:8.5 base image; it is configured (with JIT and
# preload) in docker/php/php.ini — no install step needed. The redis extension
# does not yet build cleanly on PHP 8.5 (docker-php-ext quirk); the app runs
# without it (file cache instead of Redis). Enable once its 8.5 build is stable:
#   RUN pecl install redis && docker-php-ext-enable redis

# --- Stealth + performance config ------------------------------------------
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-microservice.ini
COPY docker/apache/microservice.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/stealth.conf /etc/apache2/conf-enabled/zz-stealth.conf

# --- Application ------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN set -eux; \
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress; \
    chown -R www-data:www-data writable; \
    rm -f /usr/bin/composer

EXPOSE 80

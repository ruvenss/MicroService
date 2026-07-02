# =============================================================================
#  MicroService production image — Apache2 (mpm_event) + PHP-FPM, engine-hidden.
#
#  Target runtime is PHP 8.5 (set PHP_VERSION). Apache serves with the threaded
#  **event MPM** and proxies PHP to a **PHP-FPM** worker pool over FastCGI
#  (ARCHITECTURE §1/§17 — "ultra-performant / parallel"). Apache carries
#  mod_security (to mask the Server header) and mod_rewrite (clean URLs); the app
#  hides PHP. The database is EXTERNAL — never bundled (§17).
#
#  Build:  docker build -f docker/Dockerfile --build-arg PHP_VERSION=8.5 -t microservice:latest .
# =============================================================================
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-fpm AS runtime

# --- System deps + PHP extensions + Apache (event MPM, FastCGI proxy) -------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        apache2 libapache2-mod-security2 libicu-dev unzip; \
    docker-php-ext-install mysqli; \
    docker-php-ext-install pdo_mysql; \
    docker-php-ext-install intl; \
    # Event MPM (not prefork/worker) + FastCGI proxy to PHP-FPM + stealth mods.
    a2dismod mpm_prefork mpm_worker php8 2>/dev/null || true; \
    a2enmod mpm_event proxy proxy_fcgi rewrite headers security2 setenvif; \
    rm -rf /var/lib/apt/lists/*

# OPcache is compiled into the php:8.5 base image; it is configured (with JIT and
# preload) in docker/php/php.ini — no install step needed. Redis is reached through
# the pure-PHP Predis client (a Composer dep, installed below), so the phpredis C
# extension — which does not yet build cleanly on PHP 8.5 — is not required. The app
# uses Redis for shared cache/rate-limit/idempotency when REDIS_HOST is set
# (docker-compose), and falls back to file cache when it is absent or unreachable.

# --- Stealth + performance config ------------------------------------------
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-microservice.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/apache/microservice.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/stealth.conf /etc/apache2/conf-enabled/zz-stealth.conf
COPY docker/apache/compression.conf /etc/apache2/conf-enabled/zz-compression.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
# Scheduler-sidecar worker (webhooks:dispatch + daily maintenance:prune). Same image,
# run as a separate compose service with `command: scheduler.sh` instead of Apache.
COPY docker/scheduler.sh /usr/local/bin/scheduler.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/scheduler.sh

# --- Application ------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN set -eux; \
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress; \
    chown -R www-data:www-data writable; \
    rm -f /usr/bin/composer

# Container health = LIVENESS (is the process up and serving?), not readiness.
# The Docker healthcheck drives restart decisions, so it must not depend on the
# external DB/cache: restarting the app can never fix a database outage, and a
# readiness check here would restart-loop the container during one. `?probe=live`
# touches no dependency and returns 200 whenever the process serves; readiness
# (DB/cache) belongs to a load balancer probing /api/v1/health directly.
# Dependency-free PHP probe, so no extra packages (curl/wget) are added to the
# image. A hang (Docker timeout), a 5xx, or a connection failure marks it unhealthy.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/api/v1/health?probe=live") === false ? 1 : 0);'

EXPOSE 80
CMD ["/usr/local/bin/entrypoint.sh"]

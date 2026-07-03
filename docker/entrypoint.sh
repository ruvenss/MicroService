#!/bin/sh
# Start PHP-FPM (which loads the OPcache preload) in the background, then Apache
# (mpm_event) in the foreground. Apache proxies .php to FPM over FastCGI, so the
# request-serving layer is threaded/event-driven and the PHP layer is a sized
# worker pool — the "ultra-performant / parallel" runtime from ARCHITECTURE §1/§17.
set -e

# PHP-FPM: the master stays up; workers serve requests (env inherited, clear_env=no).
php-fpm --daemonize

# Apache needs its runtime env (APACHE_RUN_USER, PID file, …) before starting.
. /etc/apache2/envvars
exec apache2ctl -D FOREGROUND

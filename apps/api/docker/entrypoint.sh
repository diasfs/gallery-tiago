#!/bin/sh
set -e

# Bind-mounted ./apps/api from the host is often owned by uid 1000; php-fpm
# runs as www-data and must write cache/logs.
mkdir -p /app/var/cache /app/var/log
chown -R www-data:www-data /app/var
chmod -R ug+rwX /app/var

exec "$@"

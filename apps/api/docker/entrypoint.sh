#!/bin/sh
set -e

# Import from live v3 MySQL needs pdo_mysql. Older images may lack it until
# rebuild; install once at container start if missing (CLI + FPM share the .so).
if ! php -m 2>/dev/null | grep -qi '^pdo_mysql$'; then
  echo "gallery-entrypoint: installing pdo_mysql..."
  docker-php-ext-install -j"$(nproc)" pdo_mysql
fi

# Bind-mounted ./apps/api from the host is often owned by uid 1000; php-fpm
# runs as www-data and must write cache/logs.
mkdir -p /app/var/cache /app/var/log
chown -R www-data:www-data /app/var
chmod -R ug+rwX /app/var

# Named volume for MEDIA_ROOT is created as root; php-fpm (www-data) and the
# convert worker need to create originals/converted trees.
MEDIA_ROOT="${MEDIA_ROOT:-/var/gallery/media}"
mkdir -p "$MEDIA_ROOT/originals" "$MEDIA_ROOT/converted" "$MEDIA_ROOT/faces"
chown -R www-data:www-data "$MEDIA_ROOT"
# Also world-writable so the Python face worker (root in its image) can
# write face crops into the shared volume without uid mapping.
chmod -R a+rwX "$MEDIA_ROOT"

exec "$@"

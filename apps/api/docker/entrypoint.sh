#!/bin/sh
set -e

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

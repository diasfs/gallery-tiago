#!/bin/sh
set -e

MEDIA_ROOT="${MEDIA_ROOT:-/var/gallery/media}"
mkdir -p "$MEDIA_ROOT/originals" "$MEDIA_ROOT/converted"
chmod -R a+rwX "$MEDIA_ROOT"

exec "$@"

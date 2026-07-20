#!/bin/sh
set -e

MEDIA_ROOT="${MEDIA_ROOT:-/var/gallery/media}"
mkdir -p "$MEDIA_ROOT/originals" "$MEDIA_ROOT/converted" "$MEDIA_ROOT/faces"
chmod -R a+rwX "$MEDIA_ROOT"

exec "$@"

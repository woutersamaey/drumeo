#!/bin/sh
set -e
# Same directories Config::ensureDirectories() uses; TMPDIR is /shared-tmp in compose.
tmp="${TMPDIR:-/tmp}"
mkdir -p "${CACHE_DIR:-/media/cache}" "${METADATA_DIR:-/media/meta}/logs" "$tmp/vb-locks" "$tmp/vb-jobs"
if [ "$1" = "php-fpm" ]; then
  # Pool runs as root (docker/zz-root.conf): NAS files on the Mac share are often mode 700.
  exec php-fpm -F -R
fi
exec "$@"

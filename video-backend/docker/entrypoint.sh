#!/bin/sh
set -e
mkdir -p /media/cache /media/meta /tmp/vb-locks /tmp/vb-jobs
if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data /media/cache /media/meta /tmp/vb-locks /tmp/vb-jobs || true
fi
if [ "$1" = "php-fpm" ] || [ "$1" = "php-fpm-root" ]; then
  # Root: NAS files on the Mac share are often mode 700.
  exec php-fpm -F -R
fi
exec "$@"

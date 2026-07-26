#!/bin/sh
set -e

docker-entrypoint.sh php-fpm -D &

until [ -f /var/www/html/index.php ]; do
  sleep 1
  done

  nginx -g "daemon off;"

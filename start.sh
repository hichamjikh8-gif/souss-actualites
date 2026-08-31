#!/bin/sh
set -e

docker-entrypoint.sh php-fpm -D &

until [ -f /var/www/html/index.php ]; do
  sleep 1
  done

mkdir -p /var/www/html/wp-content/uploads
chmod -R 777 /var/www/html/wp-content/uploads
  nginx -g "daemon off;"

#!/bin/sh
set -e

docker-entrypoint.sh php-fpm -D &

until [ -f /var/www/html/index.php ]; do
  sleep 1
  done

mkdir -p /var/www/html/wp-content/uploads
chmod -R 777 /var/www/html/wp-content/uploads
# Traductions francaises : wp-content/languages n'est pas sur le volume,
# elles disparaissent a chaque deploiement. On les reinstalle en arriere-plan
# (sans bloquer ni faire planter le demarrage si wordpress.org ne repond pas).
( sleep 10
  wp language core install fr_FR --allow-root --path=/var/www/html || true
  wp language plugin install --all fr_FR --allow-root --path=/var/www/html || true
  wp language theme install --all fr_FR --allow-root --path=/var/www/html || true
) > /tmp/sa-langues.log 2>&1 &

  nginx -g "daemon off;"

# rebuild trigger

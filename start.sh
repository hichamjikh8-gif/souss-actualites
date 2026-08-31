#!/bin/sh
set -e

docker-entrypoint.sh php-fpm -D &

until [ -f /var/www/html/index.php ]; do
  sleep 1
  done

# Initialise les permissions du volume persistant wp-content/uploads
# afin que www-data (utilisateur de PHP-FPM/Nginx) puisse creer des
# sous-dossiers et ecrire des fichiers lors des uploads de medias.
mkdir -p /var/www/html/wp-content/uploads

chown -R www-data:www-data /var/www/html/wp-content
find /var/www/html/wp-content -type d -exec chmod 755 {} \;
find /var/www/html/wp-content/uploads -type f -exec chmod 644 {} \;

if [ ! -f /var/www/html/wp-content/uploads/index.php ]; then
  echo "<?php // Silence is golden." > /var/www/html/wp-content/uploads/index.php
  chown www-data:www-data /var/www/html/wp-content/uploads/index.php
  chmod 644 /var/www/html/wp-content/uploads/index.php
fi

  nginx -g "daemon off;"

#!/bin/sh
set -e

docker-entrypoint.sh php-fpm -D &

until [ -f /var/www/html/index.php ] && [ -f /var/www/html/wp-config.php ]; do
  sleep 1
  done

mkdir -p /var/www/html/wp-content/uploads
chmod -R 777 /var/www/html/wp-content/uploads

# WP Super Cache : wp-content/advanced-cache.php, wp-content/wp-cache-config.php et la
# constante WP_CACHE ne survivent pas a un redeploiement (seul wp-content/uploads est sur
# un volume persistant Railway ; le reste de wp-content repart de l'image a chaque deploi).
# On les regenere donc a chaque demarrage de conteneur, a partir des fichiers fournis par
# le plugin wp-super-cache lui-meme (deja telecharge dans le Dockerfile).
WPSC_DIR=/var/www/html/wp-content/plugins/wp-super-cache
if [ -d "$WPSC_DIR" ]; then
  mkdir -p /var/www/html/wp-content/cache || true
  chmod -R 777 /var/www/html/wp-content/cache || true

  cp -f "$WPSC_DIR/advanced-cache.php" /var/www/html/wp-content/advanced-cache.php || true

  cp -f "$WPSC_DIR/wp-cache-config-sample.php" /var/www/html/wp-content/wp-cache-config.php || true
  sed -i "s/\$cache_enabled = false;/\$cache_enabled = true;/" /var/www/html/wp-content/wp-cache-config.php || true

  if ! grep -q "define( 'WP_CACHE'" /var/www/html/wp-config.php; then
    sed -i "/require_once ABSPATH . 'wp-settings.php';/i define( 'WP_CACHE', true ); define( 'WPCACHEHOME', '$WPSC_DIR/' );" /var/www/html/wp-config.php || true
  fi
fi

# Converter for Media (mode Pass Thru) : wp-content/webpc-passthru.php n'est pas persistant
# non plus (meme raison que ci-dessus) ; sans lui, les images du site tombent en 404 apres
# un redeploiement. On rejoue le hook que le plugin utilise lui-meme pour (re)generer ce
# fichier a partir des reglages deja enregistres (equivalent a reactiver le plugin). Le
# dossier wp-content/uploads-webpc/uploads doit exister avant cet appel : le plugin en a
# besoin pour calculer la correspondance entre une image source et son fichier converti,
# sinon le fichier regenere reste vide de toute correspondance (images toujours cassees).
if [ -d /var/www/html/wp-content/plugins/webp-converter-for-media ]; then
  mkdir -p /var/www/html/wp-content/uploads-webpc/uploads || true
  chmod -R 777 /var/www/html/wp-content/uploads-webpc || true
  wp eval "do_action( 'webpc_refresh_loader', true );" --allow-root --path=/var/www/html || true
fi

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

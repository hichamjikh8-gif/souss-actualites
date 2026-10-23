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

  # Sans cette constante, WP Super Cache envoie max-age=3 + no-cache : le navigateur
  # redemande chaque page a chaque visite. 60 s reste court pour l'actualite.
  if ! grep -q "WPSC_CACHE_CONTROL_HEADER" /var/www/html/wp-config.php; then
    sed -i "/require_once ABSPATH . 'wp-settings.php';/i define( 'WPSC_CACHE_CONTROL_HEADER', 'max-age=60, must-revalidate' );" /var/www/html/wp-config.php || true
  fi

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
# wp-content/uploads-webpc lui-meme n'est pas non plus sur le volume persistant ; les
# images converties (WebP/AVIF) qu'il contient disparaitraient donc aussi a chaque
# redeploiement. On le remplace par un lien symbolique vers wp-content/uploads/.webpc,
# qui lui est bien sur le volume, pour que les conversions survivent.
if [ -d /var/www/html/wp-content/plugins/webp-converter-for-media ]; then
  mkdir -p /var/www/html/wp-content/uploads/.webpc/uploads || true

  rm -rf /var/www/html/wp-content/uploads-webpc || true
  ln -s /var/www/html/wp-content/uploads/.webpc /var/www/html/wp-content/uploads-webpc || true

  chmod -R 777 /var/www/html/wp-content/uploads/.webpc || true

  # Mode "htaccess" : le plugin garde les URLs d'images d'origine, que nginx sert
  # ensuite en WebP statique (voir nginx.conf), au lieu du mode Pass Thru qui faisait
  # passer chaque image par PHP sans cache navigateur.
  wp eval '
    $s = get_option( "webpc_settings", array() );
    if ( ! is_array( $s ) ) { $s = array(); }
    if ( ! isset( $s["loader_type"] ) || "htaccess" !== $s["loader_type"] ) {
      $s["loader_type"] = "htaccess";
      update_option( "webpc_settings", $s );
    }
  ' --allow-root --path=/var/www/html || true

  wp eval "do_action( 'webpc_refresh_loader', true );" --allow-root --path=/var/www/html || true
fi

# Les pages en cache peuvent encore pointer vers webpc-passthru.php : on vide le cache
# HTML a chaque demarrage (il se reconstruit tout seul a la premiere visite).
wp eval 'if ( function_exists( "wp_cache_clear_cache" ) ) { wp_cache_clear_cache(); }' --allow-root --path=/var/www/html || true

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

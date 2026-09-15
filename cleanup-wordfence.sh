#!/bin/sh
set -e

# One-time cleanup: remove stale Wordfence lockout records from wp_options
# so wp-login.php stops returning 503 errors. Safe to run repeatedly since
# it only deletes rows already prefixed with wf_.
wp db query "DELETE FROM wp_options WHERE option_name LIKE 'wf_%';" --allow-root --path=/var/www/html
wp cache flush --allow-root --path=/var/www/html

# Installer le plugin "WP to Social Agency" via WP-CLI au demarrage, maintenant
# que WordPress est bootstrappe (WP-CLI exige un WP bootstrappe qui n'existait
# pas pendant le build de l'image).
# NB: le slug fourni (nextscripts-snap) repond 404 sur wordpress.org. En cas
# d'echec, on bascule automatiquement sur le slug reel NextScripts "SNAP":
# social-networks-auto-poster-facebook-twitter-g.
if ! wp plugin install nextscripts-snap --activate --allow-root --path=/var/www/html 2>/dev/null; then
    echo ">> nextscripts-snap indisponible (404) - bascule sur social-networks-auto-poster-facebook-twitter-g"
    wp plugin install social-networks-auto-poster-facebook-twitter-g --activate --allow-root --path=/var/www/html
fi

exec /start.sh

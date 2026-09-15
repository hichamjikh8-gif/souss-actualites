#!/bin/sh
set -e

# One-time cleanup: remove stale Wordfence lockout records from wp_options
# so wp-login.php stops returning 503 errors. Safe to run repeatedly since
# it only deletes rows already prefixed with wf_.
wp db query "DELETE FROM wp_options WHERE option_name LIKE 'wf_%';" --allow-root --path=/var/www/html
wp cache flush --allow-root --path=/var/www/html

# Installer le plugin "WP to Social Agency" (nextscripts-snap) via WP-CLI au
# demarrage, maintenant que WordPress est bootstrappe. NB: WP-CLI exige un
# WordPress bootstrappe qui n'existait pas pendant le build de l'image.
wp plugin install nextscripts-snap --activate --allow-root --path=/var/www/html

exec /start.sh

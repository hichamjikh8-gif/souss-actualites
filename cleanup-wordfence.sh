#!/bin/sh
set -e

# One-time cleanup: remove stale Wordfence lockout records from wp_options
# so wp-login.php stops returning 503 errors. Safe to run repeatedly since
# it only deletes rows already prefixed with wf_.
wp db query "DELETE FROM wp_options WHERE option_name LIKE 'wf_%';" --allow-root --path=/var/www/html
wp cache flush --allow-root --path=/var/www/html

exec /start.sh

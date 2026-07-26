FROM wordpress:php8.2-apache
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf \
 && ln -sf ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
 && ln -sf ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
 && apache2ctl -M
COPY wp-content/themes/extendable-child /var/www/html/wp-content/themes/extendable-child

# Si des plugins specifiques sont versionnes dans le repo, decommentez :
# COPY wp-content/plugins /var/www/html/wp-content/plugins

# wp-content/uploads doit rester sur un volume persistant Railway,
# ne pas le copier dans l'image (sinon perte des medias a chaque redeploiement)

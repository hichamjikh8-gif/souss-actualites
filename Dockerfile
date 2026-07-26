FROM wordpress:php8.2-apache

RUN a2dismod mpm_event && a2enmod mpm_prefork

COPY wp-content/themes/extendable-child /var/www/html/wp-content/themes/extendable-child

# Si des plugins specifiques sont versionnes dans le repo, decommentez :
# COPY wp-content/plugins /var/www/html/wp-content/plugins

# wp-content/uploads doit rester sur un volume persistant Railway,
# ne pas le copier dans l'image (sinon perte des medias a chaque redeploiement)

FROM wordpress:php8.2-fpm

RUN apt-get update && apt-get install -y nginx && rm -rf /var/lib/apt/lists/*

COPY nginx.conf /etc/nginx/sites-available/default

COPY wp-content/themes/extendable-child /var/www/html/wp-content/themes/extendable-child

COPY start.sh /start.sh
RUN chmod +x /start.sh

# wp-content/uploads doit rester sur un volume persistant Railway,
# ne pas le copier dans l'image (sinon perte des medias a chaque redeploiement)

EXPOSE 80

CMD ["/start.sh"]

FROM wordpress:php8.2-fpm

RUN apt-get update && apt-get install -y nginx unzip && rm -rf /var/lib/apt/lists/*

COPY nginx.conf /etc/nginx/sites-available/default

COPY wp-content/themes/extendable-child /var/www/html/wp-content/themes/extendable-child

# Telecharger le theme parent extendable et les 4 plugins depuis WordPress.org
RUN curl -L "https://downloads.wordpress.org/theme/extendable.latest-stable.zip" -o /tmp/extendable.zip \
    && unzip -q /tmp/extendable.zip -d /var/www/html/wp-content/themes/ \
    && rm /tmp/extendable.zip \
    && curl -L "https://downloads.wordpress.org/plugin/seo-by-rank-math.latest-stable.zip" -o /tmp/rm.zip \
    && unzip -q /tmp/rm.zip -d /var/www/html/wp-content/plugins/ \
    && rm /tmp/rm.zip \
    && curl -L "https://downloads.wordpress.org/plugin/updraftplus.latest-stable.zip" -o /tmp/up.zip \
    && unzip -q /tmp/up.zip -d /var/www/html/wp-content/plugins/ \
    && rm /tmp/up.zip \
    && curl -L "https://downloads.wordpress.org/plugin/wordfence.latest-stable.zip" -o /tmp/wf.zip \
    && unzip -q /tmp/wf.zip -d /var/www/html/wp-content/plugins/ \
    && rm /tmp/wf.zip \
    && curl -L "https://downloads.wordpress.org/plugin/wp-super-cache.latest-stable.zip" -o /tmp/wsc.zip \
    && unzip -q /tmp/wsc.zip -d /var/www/html/wp-content/plugins/ \
    && rm /tmp/wsc.zip

COPY start.sh /start.sh
RUN chmod +x /start.sh

# wp-content/uploads doit rester sur un volume persistant Railway,
# ne pas le copier dans l'image (sinon perte des medias a chaque redeploiement)

EXPOSE 80

CMD ["/start.sh"]

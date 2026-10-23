# Patch de securite PHP ("PHP2Shell", CVE-2026-109618 / CVE-2026-109619) :
# deux RCE non authentifiees (CVSS 9.2) corrigees dans PHP 8.2.35 le 21/10/2026.
# L'image officielle compile PHP (elle ne passe pas par apt) : "apt-get upgrade"
# ne corrige donc PAS PHP. On epingle le digest de wordpress:php8.2-fpm publie
# apres le correctif, ce qui force le pull de la nouvelle couche de base meme si
# Railway a encore l'ancienne en cache. Re-epingler (ou passer a 8.4) pour les
# prochains patchs : le garde-fou ci-dessous fait echouer le build si besoin.
FROM wordpress:php8.2-fpm@sha256:5eaa8d6476c800b71d6d6d1e4b26fac781b80a1204ac4d21488e0b61e3638443

RUN apt-get update && apt-get install -y nginx unzip && rm -rf /var/lib/apt/lists/*

# Filet de securite : le build echoue si le PHP embarque n'est pas corrige,
# plutot que de deployer un PHP vulnerable en production.
RUN php -r "if (!version_compare(PHP_VERSION, '8.2.35', '>=')) { fwrite(STDERR, 'PHP ' . PHP_VERSION . ' vulnerable : 8.2.35 minimum requis'.PHP_EOL); exit(1); }"

COPY php-security.ini /usr/local/etc/php/conf.d/zz-sa-security.ini

# Mitigations temporaires PHP2Shell : le pool FPM (requetes web) tourne sans
# limite de memoire, ce qui supprime la condition OOM controlable de
# CVE-2026-109618. WordPress ne peut pas relever une limite deja a -1 et
# aucun plugin ne peut la modifier (php_admin_value). WP-CLI garde la limite
# par defaut : les conversions WebP lancees au demarrage du conteneur ne
# doivent pas pouvoir vider sa memoire.
RUN ls /usr/local/etc/php-fpm.d/*.conf > /dev/null \
    && for f in /usr/local/etc/php-fpm.d/*.conf; do printf '\nphp_admin_value[memory_limit] = -1\n' >> "$f"; done

# WP-CLI is required by cleanup-wordfence.sh to run database maintenance queries
RUN curl -L "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" -o /usr/local/bin/wp \
    && chmod +x /usr/local/bin/wp

COPY nginx.conf /etc/nginx/sites-available/default

COPY wp-content/themes/extendable-child /var/www/html/wp-content/themes/extendable-child
COPY wp-content/mu-plugins /var/www/html/wp-content/mu-plugins
COPY wp-content/plugins /var/www/html/wp-content/plugins
RUN mkdir -p /var/www/html/.well-known
COPY .well-known/assetlinks.json /var/www/html/.well-known/assetlinks.json

# robots.txt physique : nginx le sert directement (sinon WordPress renvoie un
# robots.txt virtuel, ce qui generait un 404 avant le bootstrappage du site).
COPY robots.txt /var/www/html/robots.txt

# robots.txt physique : nginx le sert sans passer par WordPress, ce qui evite
# le 404 actuel (WP sert souvent un robots.txt virtuel, mais pas dans cette
# config nginx+php-fpm). Le bloc Sitemap pointe vers le sitemap Rank Math.
COPY robots.txt /var/www/html/robots.txt
ENV PLUGINS_REFRESH=2026-10-22

# Telecharger le theme parent extendable et les 5 plugins depuis WordPress.org.
# NB: "WP to Social Agency" (nextscripts-snap) est installe via WP-CLI au
# demarrage du conteneur (voir /cleanup-wordfence.sh), car WP-CLI exige un
# WordPress bootstrappe qui n'existe pas encore pendant le build.
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
    && rm /tmp/wsc.zip \
        && curl -L "https://downloads.wordpress.org/plugin/polylang.latest-stable.zip" -o /tmp/pll.zip \
            && unzip -q /tmp/pll.zip -d /var/www/html/wp-content/plugins/ \
                && rm /tmp/pll.zip \
    && curl -L "https://downloads.wordpress.org/plugin/webp-converter-for-media.latest-stable.zip" -o /tmp/cfm.zip \
    && unzip -q /tmp/cfm.zip -d /var/www/html/wp-content/plugins/ \
    && rm /tmp/cfm.zip \
    && curl -L "https://downloads.wordpress.org/plugin/fast-indexing-api.latest-stable.zip" -o /tmp/fia.zip \
    && unzip -q /tmp/fia.zip -d /var/www/html/wp-content/plugins/ \
    && rm /tmp/fia.zip

COPY start.sh /start.sh
COPY cleanup-wordfence.sh /cleanup-wordfence.sh
RUN chmod +x /start.sh /cleanup-wordfence.sh

# wp-content/uploads doit rester sur un volume persistant Railway,
# ne pas le copier dans l'image (sinon perte des medias a chaque redeploiement)

EXPOSE 80

ENTRYPOINT ["/cleanup-wordfence.sh"]

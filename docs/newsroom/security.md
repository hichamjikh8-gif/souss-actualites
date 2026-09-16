# Sécurité — Souss Actualités

## Secrets

Aucun secret en dur dans le code, jamais. Tout passe par les variables d'environnement des services Railway :

- `souss-actualites` (WordPress) : `WORDPRESS_DB_HOST`, `WORDPRESS_DB_NAME`, `WORDPRESS_DB_USER`, `WORDPRESS_DB_PASSWORD`, `WORDPRESS_CONFIG_EXTRA` (contient `SOUSS_BOT_SECRET`, et optionnellement `FACEBOOK_PAGE_ID` / `FACEBOOK_PAGE_ACCESS_TOKEN` / `X_BEARER_TOKEN` si la publication reelle Facebook/X est activee, et `PEXELS_API_KEY` / `UNSPLASH_ACCESS_KEY` pour la recherche automatique d'image a la une - tous absents aujourd'hui sauf mention contraire, ces fonctionnalites restent inactives tant qu'ils ne sont pas definis).
- `web` (bot) : en plus des variables listees dans `CLAUDE.md`, `GOOGLE_SERVICE_ACCOUNT_JSON` (cle de compte de service Google, lecture seule Search Console) est definie sur Railway.
- `web` (bot) : `ADMIN_TELEGRAM_ID`, `ANTHROPIC_API_KEY`, `BOT_API_SECRET`, `CLAUDE_MODEL`, `FAL_KEY`, `TELEGRAM_BOT_TOKEN`, `WEBHOOK_URL`, `WP_BASE_URL`.
- `external-monitor` : `RAILWAY_TOKEN` (permet un redémarrage contrôlé du service `souss-actualites` en cas de panne détectée), `TELEGRAM_BOT_TOKEN`, `TARGET_URL`, seuils d'alerte.
- `.github/workflows/deploy.yml` : `RAILWAY_TOKEN`, `RAILWAY_PROJECT_ID`, `RAILWAY_SERVICE_ID` en secrets GitHub Actions.

Ne jamais coller une valeur de secret dans `CLAUDE.md`, un commit, un log applicatif, ou un prompt système envoyé à Claude.

## Authentification des API internes

Toutes les routes REST exposées pour le bot (`souss-bot/v1`, `sa-flash/v1`, `sa-events/v1`) exigent l'en-tête `X-Bot-Secret`, comparé à `SOUSS_BOT_SECRET` avec `hash_equals()` — résistant aux attaques par mesure de temps. Ne jamais remplacer `hash_equals()` par un simple `===` sur un secret.

## Périmètre volontairement restreint

Chaque route REST ne fait qu'une action précise et validée (brouillons, publication, stats, commentaires, événements) — pas d'endpoint générique d'exécution de code ou de requête SQL arbitraire. Toute nouvelle route doit suivre ce principe : une action, une validation, un log.

## Le bot Telegram

`isAuthorized()` dans `engine/server.js` ignore toute commande venant d'un `chat.id` différent de `ADMIN_TELEGRAM_ID`. Ne jamais élargir cette vérification sans une raison explicite du rédacteur en chef.

## Watchdog externe

`external-monitor` ne modifie jamais le code, la base de données ou le contenu du site qu'il surveille — il détecte, alerte, et ne redémarre le service que si `ENABLE_SELF_HEAL=true` et qu'un token Railway dédié est fourni. Sans ce token, le self-heal reste inactif (pas d'échec silencieux dangereux, juste une fonctionnalité désactivée avec avertissement en log).

## Least privilege / rotation

Pas de rotation automatisée des secrets aujourd'hui — à faire manuellement dans Railway/GitHub si un secret est suspecté d'avoir fuité. Le token `RAILWAY_TOKEN` utilisé par `external-monitor` ne devrait avoir que les permissions nécessaires à un redémarrage de service, pas un accès compte complet, si Railway permet de le scoper.

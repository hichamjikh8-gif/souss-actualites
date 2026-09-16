# Souss Actualités — Newsroom

## Mission

Souss Actualités est un journal numérique d'Agadir / Souss-Massa. Ce dépôt contient le site (WordPress) **et** l'infrastructure de newsroom IA construite autour de lui. Hicham est le rédacteur en chef : il garde le contrôle éditorial final. L'IA surveille, recherche, compare, vérifie, regroupe, résume, propose et prépare — elle ne décide jamais seule des publications importantes.

**Règle absolue : une information importante ne devient pas automatiquement un article.** Elle devient d'abord un *événement*, puis éventuellement un flash Breaking News (juste un titre en bandeau), et ne devient un article WordPress publiable que si Hicham le décide explicitement.

## Ne pas refaire

Le site fonctionne déjà en production sur `souss-actualites.com`. On construit *autour*, pas *à la place*. Ne supprime rien sans avoir vérifié les dépendances (voir règle de non-destruction plus bas).

## Architecture réelle (vérifiée sur Railway, projet `impartial-acceptance`)

```
SOURCES (a construire)
   |
   v
ENGINE (Railway "web")  <-- bot Telegram + agent Claude conversationnel
   |         |
   |         v
   |   EVENT ENGINE (WordPress mu-plugin sa-event-engine.php)
   |         |
   |         +--> Breaking News (sa-flash-manager.php) -> bandeau du site
   |         +--> Brouillon d'article WordPress (jamais publie automatiquement)
   v
WORDPRESS (Railway "souss-actualites") --- MySQL (Railway "MySQL")
   |
   v
Telegram (Railway "worker", rss-watcher.js republie le propre flux RSS du site)

Watchdog independant : "external-monitor" (repo GitHub separe, Python, self-heal)
Inutilise pour l'instant : "function-bun" (template Railway par defaut, en veille)
```

Six services dans le projet Railway `impartial-acceptance` :

| Service | Rôle réel | Code |
|---|---|---|
| `souss-actualites` | Site WordPress + nginx (Docker) | racine du repo, `wp-content/` |
| `web` | Bot Telegram + agent Claude conversationnel + surveillance uptime + alertes commentaires | `engine/server.js` |
| `worker` | Republie les nouveaux articles **du site** vers un canal Telegram (distribution, pas veille de sources externes) | `engine/rss-watcher.js` |
| `external-monitor` | Watchdog externe : ping le site, alerte email/Telegram, peut redémarrer le service `souss-actualites` via l'API Railway si `ENABLE_SELF_HEAL=true`. Ne modifie jamais le code ni le contenu. | repo GitHub séparé `hichamjikh8-gif/external-monitor` (la copie locale sur ce PC, `~/external-monitor`, n'est **pas** suivie par git — à re-cloner proprement si on doit la modifier) |
| `function-bun` | **Rien pour l'instant.** C'est le template "Hello World" par défaut de Railway (Bun + Hono), jamais développé, actuellement en veille. Ce n'est pas un orchestrateur — corriger toute doc antérieure qui le présenterait comme tel. Candidat possible pour un futur orchestrateur central si besoin, mais à construire de zéro. | — |
| `MySQL` | Base de données WordPress | — |

Il n'existe **aucun environnement de staging** : le déploiement (`.github/workflows/deploy.yml`) se déclenche sur chaque push vers `main` et va directement en production. Toute modification WordPress (mu-plugins notamment) doit donc être relue avec soin avant de pousser — une erreur PHP fatale dans un mu-plugin casse tout le site (les mu-plugins se chargent inconditionnellement, y compris `/wp-admin`).

## Le modèle d'événement (cœur de la newsroom)

Mu-plugin `wp-content/mu-plugins/sa-event-engine.php`. Une information importante devient un **événement** persistant (`EVENT-AAAA-NNNNN`) qui regroupe plusieurs sources au lieu de créer des doublons.

Tables (préfixées `wp_` comme le reste de WordPress) :
- `sa_events` — titre, résumé, statut, importance, confiance, catégorie, lien vers un flash et/ou un brouillon d'article, `merged_into` pour la déduplication.
- `sa_event_sources` — chaque URL/source rattachée à un événement (déduplication stricte par hash d'URL).
- `sa_event_log` — traçabilité : qui a fait quoi, quand (agent ou rédacteur).

Statuts : `detected → researching → verified → breaking / monitoring → editor_review → published → updated → archived` (ou `rejected` à tout moment par le rédacteur).
Importance : `important / to_watch / secondary / not_relevant`.
Confiance : `unverified / partial / confirmed / contradictory` — ne jamais traiter une seule source comme automatiquement vraie.

API REST namespace `sa-events/v1` (même authentification que le reste : en-tête `X-Bot-Secret` comparé par `hash_equals` à la constante `SOUSS_BOT_SECRET`) : `/list`, `/get/{key}`, `/find-similar` (dédup déterministe par URL + liste des événements ouverts pour jugement sémantique), `/create`, `/add-source`, `/update`, `/merge`, `/link-flash`, `/promote-to-article` (crée un **brouillon**, ne publie jamais).

Interface humaine : Réglages → « Événements (Newsroom) » dans `/wp-admin`. Vue liste + fiche détail (sources, chronologie), changement de statut manuel, bouton « Préparer un brouillon d'article » (n'écrit jamais un article publié).

Côté bot (`engine/lib/agent.js`), l'agent Claude dispose des outils `buscar_eventos_similares`, `crear_evento`, `agregar_fuente_evento`, `actualizar_evento`, `preparar_articulo`, et `publicar_flash` (peut être lié à un `event_key`). Le prompt système lui demande de chercher un événement existant avant d'en créer un nouveau, et de ne jamais promouvoir un brouillon en article publié lui-même.

**Déduplication** : `/find-similar` (et la fiche événement dans `/wp-admin`) score maintenant les événements ouverts par recouvrement de mots du titre (`sa_event_title_similarity`, coefficient de Jaccard, seuil 0.35). Validé sur des cas réels : ~0.64 pour deux titres quasi identiques sur le même fait, ~0 pour deux articles météo différents. **Limite assumée : ça ne fonctionne qu'au sein d'une même langue** — un titre français et le même fait en arabe (nos sources locales sont en arabe, certaines nationales en français) auront un score de 0, donc aucune détection cross-langue. La veille ne fusionne jamais automatiquement ; elle logue juste un avertissement (similarité ≥ 0.5) et laisse la décision à l'éditeur (bouton "Fusionner ici") ou à l'agent (`fusionar_eventos`).

**Ce qui manque encore** : déduplication cross-langue (nécessiterait une vraie similarité sémantique, pas seulement lexicale), fact-check multi-source automatisé, analytics.

## Veille de sources externes

`engine/source-watcher.js` (process Railway `sources`, voir `engine/Procfile`) lit `engine/sources.json` et transforme chaque nouvel article RSS en événement `detected`/`unverified` via `sa-events/v1`. Aucun flash ni article n'est jamais créé automatiquement à partir de la veille. Sources actives au 2026-09-17 : **Agadir24** (locale), **La Nouvelle Tribune**, **Aujourd'hui le Maroc** (nationales) — syndication automatique activée (`publish_to_sa: true`). **Hespress** : surveillance activée (`enabled: true`) mais syndication désactivée (`publish_to_sa: false`) — les événements sont créés sans publication automatique sur le site. Toutes les sources ont `filter_languages: true` : seuls les articles en arabe, français ou espagnol sont traités. **Ce service ne peut fonctionner qu'une fois le mu-plugin `sa-event-engine.php` réellement déployé en production** (voir la note push/staging plus haut).

## Breaking News ≠ article

`wp-content/mu-plugins/sa-flash-manager.php` gère des « flashes » : un simple titre affiché dans le bandeau rouge du site (`breaking-news.php`), stocké dans sa propre table `sa_breaking_news`, **sans jamais créer de page d'article**. C'est la brique déjà en place qui incarne cette règle. L'Event Engine s'y raccroche via `/link-flash` pour savoir quel événement a produit quel flash.

## Sécurité

- Aucun secret en dur dans le code : tout passe par des variables d'environnement Railway (`WORDPRESS_CONFIG_EXTRA` pour `SOUSS_BOT_SECRET`, `BOT_API_SECRET`, `ANTHROPIC_API_KEY`, `TELEGRAM_BOT_TOKEN`, `FAL_KEY`, `RAILWAY_TOKEN` pour `external-monitor`, identifiants MySQL).
- Toutes les routes REST du bot (`souss-bot/v1`, `sa-flash/v1`, `sa-events/v1`) exigent l'en-tête `X-Bot-Secret`, comparé avec `hash_equals` (résistant au timing attack). Ne jamais affaiblir ce contrôle.
- Le webhook Telegram (`/telegram-webhook`) ignore toute commande venant d'un chat différent de `ADMIN_TELEGRAM_ID`.
- Ne jamais mettre de secret dans ce fichier, dans un commit, un log ou un prompt système.
- `external-monitor` peut redémarrer le service `souss-actualites` via l'API Railway — mais uniquement en lecture/redémarrage contrôlé, jamais en modification de code ou de base de données (principe affiché dans son propre README).

## Non-destruction

Ne supprime aucun service, fichier, table ou intégration sans avoir vérifié ses dépendances. Pour remplacer quelque chose : construire le nouveau, tester, comparer, migrer, retirer l'ancien seulement si nécessaire. Ne jamais `git push` vers `main` sans que ce soit explicitement voulu : ça déclenche un déploiement direct en production (pas de staging).

## Conventions de code

- PHP (mu-plugins) : style proche de WordPress Core — `sanitize_*`/`esc_*` systématiques, `$wpdb->prepare()` pour toute requête avec entrée utilisateur, tables créées via un `get_option('..._db_version')` pour éviter de re-créer à chaque requête.
- JS (`engine/`) : Node natif (`fetch` global, pas d'framework HTTP autre qu'Express côté `web`), CommonJS (`require`/`module.exports`), pas de dépendance ajoutée sans raison.
- Pas de commentaire qui explique un « quoi » évident — seulement le « pourquoi » quand ce n'est pas évident (ex : pourquoi un hash d'URL plutôt qu'une comparaison texte).

## Tests disponibles localement

Pas de PHP ni Docker installés sur ce poste de développement : impossible de faire tourner WordPress ou `php -l` en local. Pour tout changement PHP :
1. Relecture manuelle attentive (équilibre accolades/parenthèses, cohérence avec le style existant).
2. Vérifier après déploiement que `/wp-admin` reste accessible et que les nouvelles routes REST répondent (`GET /wp-json/sa-events/v1/list` avec l'en-tête secret).

Pour le code Node (`engine/`) : `node --check <fichier>` valide au moins la syntaxe avant de committer.

## Prochaines étapes (voir aussi les priorités P0/P1/P2 données par le rédacteur en chef)

- Ingestion de sources externes (locales Agadir/Souss-Massa, nationales Maroc, internationales à fort impact) avec classification `important / à surveiller / secondaire / non pertinent`.
- Déduplication/regroupement plus fin (au-delà de l'URL exacte) pour fusionner automatiquement des sources qui parlent du même événement avec des URLs différentes.
- SEO structuré (déjà en place : schema NewsArticle, sitemap Google News, vérification Search Console — à étendre : maillage interne, Discover).
- Préparation Facebook / X / newsletter (aujourd'hui seul Telegram est câblé).
- Analytics et boucle de feedback.
- Reconsidérer un vrai environnement de staging avant de laisser l'automatisation toucher plus souvent au contenu.

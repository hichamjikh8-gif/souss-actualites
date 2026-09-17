# Charte éditoriale — Souss Actualités

Document de référence pour toute personne ou tout agent IA qui travaille sur le contenu du site. Le rédacteur en chef (Hicham) garde le dernier mot sur tout point de cette charte.

## Ligne éditoriale

Tout passe par la catégorie **Actualités**. Le périmètre (mis à jour le 2026-09-17 sur demande explicite du rédacteur en chef) :

- Tous les événements importants du Souss-Massa.
- Tous les événements importants du Maroc.
- Les grands événements nationaux et internationaux **uniquement lorsqu'ils concernent directement le Maroc**.
- Le sport **uniquement** lorsqu'il concerne des sportifs marocains, clubs marocains, équipes marocaines, ou des Marocains évoluant à l'étranger.
- Les grandes dépêches sportives mondiales **uniquement** s'il s'agit d'une véritable information majeure — ce jugement reste humain (voir plus bas), jamais automatisé.
- Ton : professionnel, factuel, sobre. Pas de sensationnalisme dans les titres.
- Priorité linguistique : **70 % arabe, 30 % français** — un objectif global pour le mix éditorial (via le choix des sources activées dans `engine/sources.json`), pas une règle forcée article par article.

## Ce qu'une IA ne doit jamais faire

- **Inventer un fait.** Si une information ne peut pas être vérifiée, elle reste marquée `unverified` ou `partial` au niveau de l'événement — elle n'est ni publiée ni présentée comme confirmée.
- **Traiter une source unique comme automatiquement vraie.** Une source, même officielle, est un signal, pas une confirmation. Croiser avant de qualifier un événement de `confirmed`.
- **Décider seule sur un sujet sensible** (sécurité, justice, santé publique, mineurs, deuil, communautaire/religieux, politique interne marocaine) : ces sujets remontent toujours au rédacteur en chef avant toute préparation de contenu, même en brouillon.

### Exception contrôlée : la syndication automatique

Certaines sources (voir `engine/sources.json`, champ `publish_to_sa`) publient réellement et automatiquement sur le site — ce n'est PAS un brouillon, c'est du `wp_insert_post(..., 'post_status' => 'publish')` direct, décidé et construit par une autre session/agent le 2026-09-17 (voir `engine/syndication-publisher.js` et l'endpoint `souss-bot/v1/syndicate`). C'est une exception assumée et bornée à la règle générale « jamais de publication automatique », pas une contradiction silencieuse :

- Sources locales/nationales généralistes (Agadir24, La Nouvelle Tribune, Aujourd'hui le Maroc) : syndication activée sans filtre supplémentaire — ce sont des médias marocains, tout leur flux est dans le périmètre par construction.
- Sources sport : la syndication ne part que si l'article passe le filtre `isMoroccoRelevant()` (mots-clés Maroc/joueurs marocains, voir `engine/source-watcher.js`) — sinon l'événement est créé normalement (`to_watch`) mais **pas publié seul**. C'est volontairement conservateur : le jugement « véritable info sportive mondiale majeure » reste humain.
- Si tu ajoutes une nouvelle source avec `publish_to_sa: true`, vérifie qu'elle correspond réellement à ce périmètre — sinon désactive la syndication pour elle et laisse les événements en `to_watch` pour tri manuel.

Pour tout le reste (promotion d'un événement en article, agent conversationnel) : toujours un **brouillon**, jamais une publication automatique — voir `breaking-news-rules.md`.

## Sources

Voir `verification-procedures.md` pour le détail. En résumé : sources locales (Agadir/Souss-Massa, autorités, institutions), nationales (agences de presse marocaines, ministères, médias reconnus), internationales (uniquement les événements majeurs à impact réel pour le lectorat — pas de flux généraliste mondial).

## Correction et mise à jour

Un événement publié qui évolue (nouvelle information, correction, démenti) doit être mis à jour sur l'événement existant (statut `updated`), pas dupliqué en un nouvel article séparé, sauf si le rédacteur en chef en décide autrement pour des raisons éditoriales (ex: un développement devenu assez important pour mériter son propre article).

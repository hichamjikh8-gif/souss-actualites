# Réseaux et distribution — Souss Actualités

## Principe

Chaque canal a son propre format. Rien n'est publié automatiquement sur un réseau social externe sans validation humaine — le système **prépare** des publications, il ne les **envoie** pas lui-même (sauf Telegram, où l'envoi existant est une distribution du propre flux RSS du site, pas une publication de contenu original).

## État actuel par canal

| Canal | État | Où |
|---|---|---|
| Telegram (canal public) | Automatisé : republie les nouveaux articles du site (`worker`) | `engine/rss-watcher.js` |
| Telegram (rédacteur en chef) | Bot de pilotage : stats, brouillons, publication, commentaires, agent conversationnel | `engine/server.js`, `engine/lib/agent.js` |
| Flash Breaking News (bandeau site) | Publication manuelle via le bot, à la demande explicite du rédacteur en chef | `sa-flash-manager.php` |
| RSS | Natif WordPress (`/feed/`), déjà consommé par le worker | — |
| Facebook | Préparation toujours en premier lieu. Publication réelle possible (Graph API `/{page-id}/feed`) **si** `FACEBOOK_PAGE_ID` et `FACEBOOK_PAGE_ACCESS_TOKEN` sont définis dans `WORDPRESS_CONFIG_EXTRA` — sinon le bouton "Publier maintenant" n'apparaît même pas dans `/wp-admin`, retour au copier-coller manuel. **Non testé contre de vrais identifiants** (aucun n'existe sur ce projet au moment de l'écriture) : à vérifier avec un premier post à faible enjeu avant d'en dépendre. | `sa-event-engine.php` (`sa_event_social_publish_to_channel`) |
| X (Twitter) | Publication réelle : API v2 `POST /2/tweets`, signée en OAuth 1.0a « user context » (4 identifiants : `X_CONSUMER_KEY`, `X_CONSUMER_SECRET`, `X_ACCESS_TOKEN`, `X_ACCESS_TOKEN_SECRET`), posés comme variables d'environnement Railway sur `worker` **et** `souss-actualites`. Deux chemins : le `worker` publie automatiquement les nouveaux articles du site, et le bouton « Publier maintenant » (canal X) apparaît dans `/wp-admin`. | `engine/rss-watcher.js` (`sendXPost`), `sa-event-engine.php` (`sa_event_social_publish_to_channel`) |
| Newsletter | Préparation seulement (texte de blurb), pas de service d'envoi configuré | idem |

## Format par canal (quand l'agent rédige un texte)

- **Facebook** : 2 à 4 phrases, ton informatif, peut inclure un lien vers l'article une fois publié.
- **X** : concis (viser sous ~260 caractères pour laisser de la marge), factuel, pas de fil (thread) automatique.
- **Telegram** (relais manuel d'un événement, hors flux automatique) : titre + lien + une phrase de contexte.
- **Newsletter** : un paragraphe court (3-5 phrases) qui donne envie de lire l'article complet, pas un résumé exhaustif qui le remplace.

## Ce que l'agent ne fait jamais

Publier directement sur un réseau social externe. Les API Facebook et X sont configurées, mais elles ne sont appelées que par deux endroits : le `worker` qui relaie le propre flux RSS du site, et le bouton manuel « Publier maintenant » de `/wp-admin` — jamais par l'agent. Voir `security.md` sur pourquoi ce choix est volontaire tant que la validation humaine par canal n'est pas définie noir sur blanc par le rédacteur en chef.

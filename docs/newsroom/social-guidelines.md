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
| Facebook | **Préparation seulement** : l'agent peut rédiger un texte adapté et le stocker (`sa_event_social_posts`), mais aucune API Facebook n'est connectée — publication manuelle par copier-coller depuis `/wp-admin` | `sa-event-engine.php` (table `sa_event_social_posts`) |
| X (Twitter) | Préparation seulement, même mécanisme que Facebook | idem |
| Newsletter | Préparation seulement (texte de blurb), pas de service d'envoi configuré | idem |

## Format par canal (quand l'agent rédige un texte)

- **Facebook** : 2 à 4 phrases, ton informatif, peut inclure un lien vers l'article une fois publié.
- **X** : concis (viser sous ~260 caractères pour laisser de la marge), factuel, pas de fil (thread) automatique.
- **Telegram** (relais manuel d'un événement, hors flux automatique) : titre + lien + une phrase de contexte.
- **Newsletter** : un paragraphe court (3-5 phrases) qui donne envie de lire l'article complet, pas un résumé exhaustif qui le remplace.

## Ce que l'agent ne fait jamais

Publier directement sur un réseau social externe (aucune API n'est configurée pour cela aujourd'hui — voir `security.md` sur pourquoi ce choix est volontaire tant que la validation humaine par canal n'est pas définie noir sur blanc par le rédacteur en chef).

# Règles Breaking News — Souss Actualités

## La règle absolue

**Breaking News ≠ article automatique.** Une information importante peut devenir un flash (simple titre dans le bandeau rouge du site), rester sous surveillance, être enrichie par de nouvelles sources, et éventuellement — seulement si le rédacteur en chef le décide — devenir un article complet.

## Implémentation actuelle

- **Flash** (`wp-content/mu-plugins/sa-flash-manager.php`) : un titre (8 mots maximum, factuel), affiché uniquement dans le bandeau défilant du site (`breaking-news.php`). Aucune page d'article n'est créée. Table `wp_sa_breaking_news`.
- **Événement** (`wp-content/mu-plugins/sa-event-engine.php`) : l'unité de raisonnement de la newsroom. Un flash devrait toujours être rattaché à un événement (`/link-flash`) pour que les sources et la chronologie restent centralisées, même si le flash lui-même reste minimal.
- **Article** : brouillon WordPress classique, `post_status = draft`. Créé uniquement via `/promote-to-article` (API) ou le bouton "Préparer un brouillon d'article" dans `/wp-admin → Réglages → Événements (Newsroom)`. **Jamais publié automatiquement** — le rédacteur en chef doit ouvrir le brouillon et cliquer "Publier" lui-même (ou utiliser `/publish <id>` sur Telegram, qui reste une action humaine explicite).

## Cycle de vie d'un événement

```
detected → researching → verified → breaking / monitoring → editor_review → published → updated → archived
                                                                  (ou "rejected" a tout moment)
```

- `detected` : information captée (une source, pas encore de recherche).
- `researching` : en cours de vérification / recherche de sources additionnales.
- `verified` : au moins une confirmation croisée obtenue.
- `breaking` : un flash a été publié pour cet événement (bandeau).
- `monitoring` : pas encore assez d'éléments pour un flash ou un article, mais suivi actif.
- `editor_review` : un brouillon d'article existe, en attente du rédacteur en chef.
- `published` : l'article a été publié (par le rédacteur en chef).
- `updated` : l'article publié a reçu une mise à jour depuis.
- `archived` : plus d'évolution attendue.
- `rejected` : écarté par le rédacteur en chef (non pertinent, non confirmable, ou éditorialement écarté).

## Quand créer un nouvel événement vs enrichir l'existant

Toujours appeler `/find-similar` (ou l'outil `buscar_eventos_similares` côté bot) avant de créer un événement. Si l'URL est déjà connue, ou si un événement ouvert récent correspond clairement au même sujet, enrichir cet événement (`/add-source`, `/update`) plutôt que d'en créer un nouveau. En cas de doute entre deux événements existants qui se révèlent être le même sujet, utiliser `/merge` (fusion, jamais suppression — l'historique est conservé).

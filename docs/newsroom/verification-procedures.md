# Vérification des sources — Souss Actualités

## Niveaux de confiance d'un événement

- `unverified` — une seule source, pas encore recoupée. Statut par défaut à la création.
- `partial` — recoupement partiel (ex : une source fiable + une source secondaire qui va dans le même sens, mais pas encore de confirmation officielle).
- `confirmed` — confirmation croisée par au moins deux sources indépendantes, ou une source officielle incontestable (communiqué gouvernemental, autorité compétente).
- `contradictory` — les sources se contredisent. Ne jamais faire disparaître la contradiction dans le résumé : elle doit rester visible (champ `summary` de l'événement, ou notes dans le journal `/get/{key}`).

## Classification des sources (section 27 du cahier des charges)

- **Local** : Agadir, Souss-Massa, institutions et autorités locales, sources locales fiables.
- **National** : actualité marocaine, institutions, gouvernement, ministères, agences de presse marocaines, médias reconnus.
- **International** : uniquement les événements majeurs, à fort impact, avec un intérêt réel pour le lectorat (impact Maroc/région, ou portée internationale significative). Ne pas remonter le flux généraliste mondial.

Chaque source détectée doit aussi recevoir une classification d'importance : `important` / `to_watch` / `secondary` / `not_relevant`. Une information `not_relevant` ne doit pas devenir un événement.

## Procédure recommandée avant de qualifier un événement `confirmed`

1. Identifier au moins deux sources indépendantes qui rapportent le même fait, ou une source officielle non ambiguë.
2. Vérifier que les sources ne se contredisent pas sur les éléments factuels centraux (qui, quoi, où, quand).
3. Si contradiction : garder `contradictory`, documenter la divergence dans le résumé, ne pas trancher artificiellement.
4. Ne jamais confirmer un événement sensible (sécurité, justice, santé, mineurs) sur la seule base d'une source non officielle — ces sujets remontent au rédacteur en chef (voir `editorial-charter.md`).

## Suggestion automatique de doublons

Depuis le tableau de bord (`/wp-admin → Événements (Newsroom)`) et l'outil agent `buscar_eventos_similares`, chaque événement affiche les autres événements ouverts dont le titre partage beaucoup de mots (coefficient de Jaccard sur les mots significatifs, seuil 0.35). C'est une **heuristique lexicale**, pas une compréhension du sens : elle repère bien deux titres quasi identiques sur le même fait, mais **ne fonctionne pas d'une langue à l'autre** (un article en arabe et le même fait en français auront un score de 0). Elle ne fusionne jamais rien toute seule — c'est une suggestion, la fusion reste une action manuelle (bouton "Fusionner ici", ou l'outil `fusionar_eventos`).

## Traçabilité

Chaque action sur un événement (création, ajout de source, changement de statut, fusion, liaison à un flash, brouillon créé) est enregistrée dans la table `wp_sa_event_log` avec l'acteur (`agent` ou `editor:<login>`), l'action et un horodatage — consultable dans `/wp-admin → Événements (Newsroom)` ou via `GET /wp-json/sa-events/v1/get/{event_key}`.

# Charte éditoriale — Souss Actualités

Document de référence pour toute personne ou tout agent IA qui travaille sur le contenu du site. Le rédacteur en chef (Hicham) garde le dernier mot sur tout point de cette charte.

## Ligne éditoriale

- Presse régionale d'Agadir / Souss-Massa, avec ouverture sur l'actualité nationale marocaine et l'actualité internationale à fort impact.
- Ton : professionnel, factuel, sobre. Pas de sensationnalisme dans les titres.
- Langue de publication : français. Le rédacteur en chef peut être adressé en espagnol par l'assistant conversationnel (voir `engine/lib/agent.js`), mais tout contenu publié sur le site est en français.

## Ce qu'une IA ne doit jamais faire

- **Inventer un fait.** Si une information ne peut pas être vérifiée, elle reste marquée `unverified` ou `partial` au niveau de l'événement — elle n'est ni publiée ni présentée comme confirmée.
- **Traiter une source unique comme automatiquement vraie.** Une source, même officielle, est un signal, pas une confirmation. Croiser avant de qualifier un événement de `confirmed`.
- **Publier un article automatiquement.** Voir `breaking-news-rules.md` : une information importante devient un événement, puis éventuellement un flash. Elle ne devient un article publié que si le rédacteur en chef le décide et clique lui-même sur "Publier".
- **Décider seule sur un sujet sensible** (sécurité, justice, santé publique, mineurs, deuil, communautaire/religieux, politique interne marocaine) : ces sujets remontent toujours au rédacteur en chef avant toute préparation de contenu, même en brouillon.

## Sources

Voir `verification-procedures.md` pour le détail. En résumé : sources locales (Agadir/Souss-Massa, autorités, institutions), nationales (agences de presse marocaines, ministères, médias reconnus), internationales (uniquement les événements majeurs à impact réel pour le lectorat — pas de flux généraliste mondial).

## Correction et mise à jour

Un événement publié qui évolue (nouvelle information, correction, démenti) doit être mis à jour sur l'événement existant (statut `updated`), pas dupliqué en un nouvel article séparé, sauf si le rédacteur en chef en décide autrement pour des raisons éditoriales (ex: un développement devenu assez important pour mériter son propre article).

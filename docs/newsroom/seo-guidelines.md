# SEO — Souss Actualités

Fondations déjà en place, à connaître avant d'en ajouter d'autres pour éviter les doublons.

## Déjà construit

- `wp-content/mu-plugins/schema-news-article.php` — injecte le schema.org `NewsArticle` (titre, description, date, auteur, éditeur, image) sur chaque article. Ne pas dupliquer ce rôle si Rank Math (plugin installé, voir `Dockerfile`) génère déjà un schema équivalent — vérifier avant d'ajouter une deuxième balise JSON-LD concurrente.
- `wp-content/mu-plugins/sitemap-google-news.php` + `fix-sitemap-redirect.php` + `trace-sitemap.php` — sitemap Google News dédié.
- `wp-content/mu-plugins/google-site-verification.php` — vérification Search Console.
- `robots.txt` physique servi directement par nginx (voir commentaires dans `Dockerfile` : WordPress sert un robots.txt virtuel qui produisait un 404 avant le bootstrap complet du site).
- Plugin Rank Math (SEO général : méta-titres, méta-descriptions, mots-clés) déjà téléchargé dans l'image Docker.

## Règles pour tout contenu généré (agent ou rédacteur)

- Titre SEO distinct du titre éditorial si besoin, mais rester factuel — pas de putaclic.
- Meta description : 150 à 160 caractères.
- 3 à 5 mots-clés pertinents, jamais du bourrage de mots-clés.
- Structure HTML simple et sémantique : `<h2>` pour les sous-parties, `<p>` pour le corps, `<strong>` avec parcimonie.
- Maillage interne : quand un article fait référence à un événement déjà couvert, lier vers l'article existant plutôt que de répéter le contexte.

## Ne jamais promettre

Ne jamais promettre une position Google précise ni un volume de trafic garanti — le SEO est une conséquence de la qualité éditoriale et technique, pas un objectif qu'on peut forcer.

## Image à la une automatique

Quand un événement est promu en brouillon d'article, le système cherche une photo libre de droits (Pexels en priorité, Unsplash en repli) avec le titre de l'événement comme requête, et l'attache comme image à la une si trouvée. Désactivé tant que `PEXELS_API_KEY` et/ou `UNSPLASH_ACCESS_KEY` ne sont pas définis dans `WORDPRESS_CONFIG_EXTRA`. **Limite connue** : la recherche utilise le titre tel quel — marche bien pour les noms propres (Agadir, Maroc...), moins bien pour un titre entièrement en arabe (pas de traduction automatique, aucun appel IA dans ce mu-plugin). Le crédit du photographe est enregistré sur le média (méta `_sa_stock_photo_credit`) et dans la légende.

## Maillage interne automatique

Quand un événement est promu en brouillon d'article (`sa_event_promote_to_article`), le système cherche jusqu'à 3 articles déjà publiés dont le titre partage des mots significatifs (même heuristique que la détection de doublons d'événements) et ajoute une section "À lire aussi" dans le brouillon. Le rédacteur en chef garde, modifie ou retire cette section avant publication — c'est un brouillon, rien n'est jamais inséré dans un article déjà publié.

## À construire (P2)

Maillage interne automatisé (suggestions de liens vers des événements/articles connexes), suivi Google Discover, audit technique périodique (vitesse, Core Web Vitals) au-delà du cache/gzip déjà en place (`nginx.conf`).

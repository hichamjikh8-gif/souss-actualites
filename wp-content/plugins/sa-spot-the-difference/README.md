# Trouvez les différences — Souss Actualités

Plugin WordPress autonome (pas un mu-plugin) qui ajoute un jeu "Trouvez les différences" publiable dans les articles, sans toucher au code à chaque nouveau jeu.

## Installation

1. Le dossier `wp-content/plugins/sa-spot-the-difference/` est déployé automatiquement avec le site (voir `Dockerfile`).
2. Dans `wp-admin → Extensions`, cliquer **Activer** sur « Trouvez les différences — Souss Actualités ». Rien ne change sur le site avant cette activation manuelle.
3. Un nouveau menu **Jeux** apparaît dans le menu d'administration.

## Créer un nouveau jeu (sans coder)

1. **Jeux → Ajouter un jeu**.
2. Donner un **titre** (affiché aux lecteurs) et, si besoin, une courte **description** dans le champ de contenu.
3. Choisir une **catégorie** (Agadir, Souss-Massa, Taroudant, culture marocaine, etc.) dans le panneau "Régions / Villes" à droite — on peut aussi en ajouter de nouvelles.
4. Dans le bloc "Images du jeu" : cliquer **Choisir l'image A** (photo originale) puis **Choisir l'image B** (la même photo avec des modifications) depuis la médiathèque, ou en important de nouvelles photos. Les deux images doivent avoir le même cadrage.
5. **Enregistrer le brouillon** (bouton à droite) — nécessaire une première fois pour que l'éditeur de différences s'affiche.
6. Dans le bloc "Différences à trouver" : cliquer sur l'image partout où il y a une différence (5, 10, 15, autant que vous voulez). Un repère numéroté apparaît à chaque clic ; cliquer sur un repère le retire (pour corriger sa position, retirez-le puis recliquez au bon endroit). Si deux repères sont posés trop près l'un de l'autre, un message l'indique et le second clic est ignoré.
7. Cliquer **Publier** quand le jeu est prêt. Avant publication, le jeu est un brouillon invisible des lecteurs.

## Insérer un jeu publié dans un article

1. Dans l'éditeur Gutenberg de l'article, ajouter le bloc **"Trouvez les différences"** (rechercher "différences" dans le sélecteur de blocs).
2. Dans le panneau de réglages du bloc (à droite), choisir le jeu publié à afficher dans la liste déroulante.
3. Le jeu s'affiche en aperçu directement dans l'éditeur.

Alternative sans bloc : insérer le shortcode `[sa_spot_diff id="123"]` où `123` est l'identifiant du jeu (visible dans l'URL `post=123` quand on modifie le jeu dans **Jeux**).

## Côté lecteur

Deux images côte à côte (empilées sur mobile), compteur de différences trouvées, message de réussite/erreur, case "Afficher un indice", bouton "Rejouer". Fonctionne au clic comme au doigt (tactile), sur ordinateur, tablette et smartphone.

## Limites actuelles (prévues pour une phase 2)

Page listant tous les jeux, recherche par ville, niveaux de difficulté, chronomètre, classement, partage réseaux sociaux, statistiques, interface arabe — non inclus dans cette première version, mais l'architecture (taxonomie Régions/Villes, CPT dédié) est prête à les recevoir.

## Note technique — persistance des images

Les images choisies passent par la médiathèque WordPress standard (`wp-content/uploads`). Comme pour toute image du site, elles ne survivront à un redéploiement que si un volume Railway persistant est bien attaché au service `souss-actualites` sur ce chemin — à vérifier une fois dans le tableau de bord Railway (ce n'est pas spécifique à ce plugin).

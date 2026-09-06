# Conventions UI & Interaction — Fluvia

> Ce document est la **source de vérité** de l'agent `relecteur-ui` (les deux couches :
> Interaction R… et Design D…) et de `concepteur-ui` (qui propose le bon pattern en amont).
> Adapte, renumérote et complète selon ton produit. Si une règle évolue, **c'est ce document
> qui fait foi**, pas la mémoire des agents.

## Couche Interaction (R…)

- **R1 — Création** : entité courte (≤ ~6 champs) en **modale** ; entité lourde → page assumée.
- **R2 — Édition** : au plus près de la donnée (inline / même modale que la création / page si lourde).
- **R3 — Suppression / irréversible** : confirmation obligatoire, modale **nommant l'objet**, action destructive visuellement distincte. Jamais de suppression en un seul clic.
- **R4 — Recherche & filtrage** : live (JS + `fetch`) **et** dégradable sans JS.
- **R5 — Placement des actions** : action principale en haut à droite ; actions de ligne à droite (menu « … » si > 2).
- **R6 — Retour utilisateur** : chaque action donne un retour via **le** composant toast partagé (jamais `alert()` ni bandeau ad hoc).
- **R7 — États vides / chargement** : liste vide = message + action ; chargement = squelette/spinner.
- **R8 — Pagination** : grandes listes paginées, **jamais** de scroll infini.
- **R9 — Auto-save** : formulaires longs en sauvegarde automatique ; formulaires courts en modale gardent leur submit.
- **R10 — Cards + densité réglable** : listes en cards ; taille de page réglable, préférence **mémorisée par utilisateur** via le stockage de préférences partagé.
- **R11 — Drag-and-drop = raccourci + repli** : toute action DnD a un chemin alternatif équivalent ; sur mobile le repli est le chemin principal.
- **R12 — Feedback de drag + persistance optimiste** : fantôme + zone surlignée ; au dépôt → `fetch` + maj optimiste + toast + rollback si refus serveur.
- **R13 — Dropzone fichiers** : double un `<input type="file">` classique ; validation type/taille **client ET serveur** ; progression + erreur claire.

## Couche Design (D…)

- **D1 — Conteneur de page unique** : une seule enveloppe de mise en page par écran.
- **D2 — Une classe = un composant** : pas de variantes divergentes non intentionnelles.
- **D3 — Un seul bouton primaire** par écran/section.
- **D4 — Toujours le token, jamais le littéral** : couleurs/espacements via tokens du design system.
- **D5 — Contraste AA** : 4.5:1 texte normal, 3:1 texte large / éléments d'interface.
- **D6 — Cliquable = accessible clavier** : rôle + focus + libellé sur tout élément interactif.
- **D7 — Responsive obligatoire** : utilisable sur mobile même si l'usage principal est desktop.

<!-- À REMPLIR : ajoute/retire des règles selon ton produit et tes surfaces (web, mobile natif…). -->

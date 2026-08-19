# TASKS — tableau de claim

Avant de démarrer un chantier, ajoute une ligne ici avec ton instance + statut. Statuts :
`CLAIM` (réservé) · `WIP` (en cours) · `REVIEW` (en revue de cohérence) · `DONE` · `BLOCKED`.
Ne touche pas un chemin déjà en `WIP` par une autre instance.

| # | Tâche | Chemins | Instance | Statut | Maj |
|---|---|---|---|---|---|
| C1 | Contrat de plateforme v0 (noyau, manifeste, événements) | `COORDINATION/CONTRACT/**` | claude (billetterie) | WIP | 19/08 |
| C2 | Échafaudage de coordination (ce dossier) | `COORDINATION/**` | claude (billetterie) | DONE | 19/08 |
| C3 | Spec SDD Suite Finance & Compta | `specs/finance/**` | claude (billetterie) | DONE | 19/08 |
| C6 | Plan + impl Suite Finance (lots FIN-0..FIN-4) | `app/src/{Finance,Ocr}/**`, `app/src/Compta/**` | *à assigner* | CLAIM | 19/08 |
| C4 | Garde-fous CI partagés (cloisonnement, CSRF, manifeste) | `bin/`, CI | *à assigner* | CLAIM | 19/08 |
| C5 | Bus d'événements + registre de modules (impl core) | `app/src/Platform/**` | *à assigner* | CLAIM | 19/08 |

<!-- Ajouter les nouvelles tâches au-dessus de cette ligne. -->

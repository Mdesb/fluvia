# TASKS — tableau de claim

Avant de démarrer un chantier, ajoute une ligne ici avec ton instance + statut. Statuts :
`CLAIM` (réservé) · `WIP` (en cours) · `REVIEW` (en revue de cohérence) · `DONE` · `BLOCKED`.
Ne touche pas un chemin déjà en `WIP` par une autre instance.

| # | Tâche | Chemins | Instance | Statut | Maj |
|---|---|---|---|---|---|
| C1 | Contrat de plateforme v0 (noyau, manifeste, événements) | `COORDINATION/CONTRACT/**` | **claude-A** | DONE | 20/08 |
| C2 | Échafaudage de coordination (ce dossier) | `COORDINATION/**` | claude (billetterie) | DONE | 19/08 |
| C3 | Spec SDD Suite Finance & Compta | `specs/finance/**` | claude (billetterie) | DONE | 19/08 |
| C6 | Suite Finance FIN-0 (OCR) + FIN-1 (Compta) | `app/src/{Ocr,Compta}/**` | **claude-B** | BLOCKED | 20/08 |
| C4 | Garde-fous CI partagés (cloisonnement, CSRF, manifeste) | `bin/`, `.github/workflows/` | **claude-C** | WIP | 19/08 |
| C5 | Bus d'événements + registre de modules (impl core) | `app/src/Platform/**` | **claude-A** | DONE | 20/08 |
| C7 | Harnais de test executable (stack isolee, DDL hors mapping) | `infra/test-stack.sh`, `app/tests/DdlHorsMapping.php` | **claude-A** | DONE | 19/08 |
| C8 | CA-11 Support : l agent ne voit pas les 2 messages du fil (TicketSupportApiTest:109) | `app/src/Support/State/MessageTicketProvider.php` | **claude-A** | DONE | 19/08 |
| C9 | Hygiene du conteneur : `App\: resource '../src/'` sans exclude (entites enregistrees comme services partages) | `app/config/services.yaml` | *a assigner* | CLAIM | 19/08 |
| C10 | Suite Finance FIN-2 (SupplierInvoice) → FIN-3 → FIN-4 | `app/src/Finance/**` | **claude-B** | à venir | 19/08 |

| C11 | Tests de non-regression IDOR Caisse/SEPA (etendre CaisseClotureRoleFixtures : caisse.mouvement absente des fixtures) | `app/tests/Caisse`, `app/src/Caisse/DataFixtures` | *a assigner* | CLAIM | 19/08 |
| C12 | Porter letablissement sur AccesRedevableChangeEvent pour quil soit pontable (RG-PLAT-03) | `app/src/Recouvrement/Event` | *a assigner* | CLAIM | 20/08 |
| C13 | Retirer LegacyEventBridge quand chaque module publiera lui-meme son DomainEvent | `app/src/Platform/Event/Legacy` | *a assigner* | CLAIM | 20/08 |
| ED-0 | Spec administration editeur + tunnel de souscription (SDD) | `specs/editeur/**` | **claude-A** | REVIEW | 20/08 |
| ED-1 | Catalogue doffres : Plan, PlanOption adossees aux capabilities | `app/src/Editeur/**` | *a assigner* | CLAIM | 20/08 |
| ED-2 | Abonnement : cycle de vie, prorata, suspension sur impaye | `app/src/Editeur/**` | *a assigner* | CLAIM | 20/08 |
| ED-3 | Tunnel de souscription SEPA + provisioning idempotent | `app/src/Editeur/**` | *a assigner* | CLAIM | 20/08 |
| ED-4 | Acces dassistance borne et audite (RG-ED-07) | `app/src/Editeur/**`, `app/src/Audit/**` | *a assigner* | CLAIM | 20/08 |
<!-- Ajouter les nouvelles tâches au-dessus de cette ligne. -->

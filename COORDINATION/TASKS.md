# TASKS — tableau de claim

Avant de démarrer un chantier, ajoute une ligne ici avec ton instance + statut. Statuts :
`CLAIM` (réservé) · `WIP` (en cours) · `REVIEW` (en revue de cohérence) · `DONE` · `BLOCKED`.
Ne touche pas un chemin déjà en `WIP` par une autre instance.

| # | Tâche | Chemins | Instance | Statut | Maj |
|---|---|---|---|---|---|
| C1 | Contrat de plateforme v0 (noyau, manifeste, événements) | `COORDINATION/CONTRACT/**` | **claude-A** | DONE | 20/08 |
| C2 | Échafaudage de coordination (ce dossier) | `COORDINATION/**` | claude (billetterie) | DONE | 19/08 |
| C3 | Spec SDD Suite Finance & Compta | `specs/finance/**` | claude (billetterie) | DONE | 19/08 |
| C6 | Suite Finance FIN-0 (OCR) + FIN-1 (Compta) | `app/src/{Ocr,Compta}/**` | **claude-B** | DONE | 21/08 |
| C4 | Garde-fous CI : cloisonnement, nommage, secrets — i18n et CSRF sans objet | `bin/` | **claude-C** | DONE | 22/08 |
| C5 | Bus d'événements + registre de modules (impl core) | `app/src/Platform/**` | **claude-A** | DONE | 20/08 |
| C7 | Harnais de test executable (stack isolee, DDL hors mapping) | `infra/test-stack.sh`, `app/tests/DdlHorsMapping.php` | **claude-A** | DONE | 19/08 |
| C8 | CA-11 Support : l agent ne voit pas les 2 messages du fil (TicketSupportApiTest:109) | `app/src/Support/State/MessageTicketProvider.php` | **claude-A** | DONE | 19/08 |
| C9 | Decouverte des ressources API : identifier le mecanisme reel, rendre la configuration explicite, PUIS exclure les entites des services | `app/config/{services,packages/api_platform}.yaml` | **claude-A** | BLOCKED | 20/08 |
| C10 | Suite Finance FIN-2, FIN-3, FIN-4 - bloc complet | `app/src/Finance/**` | **claude-B** | DONE | 21/08 |

| C11 | Tests de non-regression IDOR Caisse/SEPA (etendre CaisseClotureRoleFixtures : caisse.mouvement absente des fixtures) | `app/tests/Caisse`, `app/src/Caisse/DataFixtures` | *a assigner* | CLAIM | 19/08 |
| C12 | Porter letablissement sur AccesRedevableChangeEvent pour quil soit pontable (RG-PLAT-03) | `app/src/Recouvrement/Event` | *a assigner* | CLAIM | 20/08 |
| C13 | Retirer LegacyEventBridge quand chaque module publiera lui-meme son DomainEvent | `app/src/Platform/Event/Legacy` | *a assigner* | CLAIM | 20/08 |
| ED-0 | Spec administration editeur + tunnel de souscription (SDD) | `specs/editeur/**` | **claude-A** | REVIEW | 20/08 |
| ED-1 | Catalogue doffres : Plan, PlanOption adossees aux capabilities | `app/src/Editeur/**` | **claude-A** | DONE | 20/08 |
| ED-2 | Abonnement : cycle de vie, prorata, mecanisme de suspension | `app/src/Subscription/**` | **claude-A** | DONE | 20/08 |
| ED-3 | Tunnel de souscription SEPA + provisioning idempotent | `app/src/Editeur/**` | **claude-A** | WIP | 20/08 |
| ED-4 | Acces dassistance borne et audite (RG-ED-07) | `app/src/Editeur/**`, `app/src/Audit/**` | *a assigner* | CLAIM | 20/08 |
| C14 | Declarer au mapping ORM les index ecrits en SQL brut, pour que migrations:diff cesse de proposer leur suppression | `app/src/*/Entity`, `app/migrations` | *a assigner* | CLAIM | 20/08 |
| C15 | NF525 : cle de scellement en dur dans ScellementEcritureHandler (conformite legale) | `app/src/Compta/Nf525` | **claude-B** | DONE | 20/08 |
| C16 | Declencheur : hook pre-receive installe sur le bare, garde-fous actifs | `hooks/` | **claude-C** | DONE | 22/08 |
| D7-bis | Asynchrone : messenger + transport Doctrine, worker systemd, transport dechec | `app/config`, `infra/` | **claude-A** | DONE | 21/08 |
| SOC-0 | Spec SDD du module de publication sociale | `specs/social/**` | **claude-A** | CLAIM | 21/08 |
| SOC-1 | Modele post/publications + coffre a jetons chiffre et cloisonne | `app/src/Social/**` | *a assigner* | CLAIM | 21/08 |
| SOC-2 | Adaptateur reseau ouvert (Mastodon/Bluesky) + file, reprises, quotas | `app/src/Social/**` | *a assigner* | CLAIM | 21/08 |
| SOC-3 | Collecte planifiee des statistiques : instantanes + charge brute conservee | `app/src/Social/**` | *a assigner* | CLAIM | 21/08 |
| SOC-4 | Adaptateurs Meta (Page + Instagram), apres verification dentreprise et revue applicative | `app/src/Social/**` | *a assigner* | EXTERNE | 21/08 |
| DMS-0 | GED : spec SDD (cloisonnement, URL signees expirantes, retention legale, versionnement) | `specs/dms/**` | **claude-B** | DONE | 21/08 |
| DMS-1 | GED : implementation du stockage, des versions et des acces — **selon spec arbitree D18** | `app/src/Dms/**` | **claude-B** | CLAIM | 22/08 |
| C17 | NF525 : troisieme chaine (Facturation) — cle obligatoire depuis lenvironnement | `app/src/Facturation/Nf525` | **claude-A** | DONE | 22/08 |
| ACT-0 | Spec SDD : composition dactivites, format de paquet verticale, remplacement de Metier | `specs/activites/**` | **claude-A** | CLAIM | 22/08 |
| ACT-1 | Reservation : quantite consommee, reservation par type, quota de second niveau | `app/src/Reservation/**` | *a assigner* | CLAIM | 22/08 |
| ACT-2 | Module hebergement : nuitee, calendrier doccupation, arrivee et depart | `app/src/Lodging/**` | *a assigner* | CLAIM | 22/08 |
| ACT-3 | Concept de sejour : compte unique sur place, regle une fois au depart | `app/src/Stay/**` | *a assigner* | CLAIM | 22/08 |
| ACT-4 | Module restauration : service a table, addition, envoi cuisine | `app/src/Dining/**` | *a assigner* | CLAIM | 22/08 |
| ACC-0 | Declaration de capacites des pilotes dacces (decision, revocation, encodage, passages) | `app/src/Acces/Port/**` | **claude-A** | CLAIM | 22/08 |
| ACC-1 | Echec explicite sur operation non declaree + restitution a lexploitant | `app/src/Acces/**` | *a assigner* | CLAIM | 22/08 |
| ACC-2 | Second port : encodage dune autorisation sur un medium (distinct de lappairage) | `app/src/Acces/Port/**` | *a assigner* | CLAIM | 22/08 |
| ACC-3 | Projection reelle : une reservation ouvre un acces (remplace le no-op documente) | `app/src/Reservation/ProjectionAcces**` | *a assigner* | CLAIM | 22/08 |
<!-- Ajouter les nouvelles tâches au-dessus de cette ligne. -->

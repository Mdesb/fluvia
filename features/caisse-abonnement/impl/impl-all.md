# Suivi d'implémentation — caisse-abonnement

**État :** plan <!-- discovery → spec → plan → build → review → done -->
**Branche (build) :** feature/caisse-abonnement-v2 (neuve depuis main ; l'ancienne `feature/caisse-abonnement` de claude-A reste en lecture)
**Spec :** features/caisse-abonnement/specs/spec-caisse-abonnement.md
**Plan :** features/caisse-abonnement/plans/plan-caisse-abonnement.md
**Analyse de l'existant :** features/caisse-abonnement/refs/analyse-branche-claude-A.md

**Portée :** le comptoir crée l'abonnement à la vente d'un produit-formule (via `souscrire()`, **après commit**) ; 1er mois encaissé + tracé ; mandat SEPA par lien (e-mail d'abord). Comptoir seul.

## Checkpoints

- [x] **CP-1** — spec validée — Maxime, 16/09 (4 arbitrages ; spec challengée contradicteur + finance/juridique/simplificateur).
- [x] **CP-2** — plan validé — Maxime, 16/09. Quatre décisions : (a) branche neuve, portage manuel des briques saines ; (b) lien de signature e-mail d'abord, SMS ensuite ; (c) mode « mandat existant » = option visible au caissier ; (d) complétion back-office = filet exceptionnel avec motif tracé (jamais un mandat Actif sans consentement).
- [ ] **CP-3** — revue avant merge (security-reviewer + coordination Vente/Sepa/Membership)

## Journal de session

**16/09 — spec CP-1 + analyse de l'existant + plan CP-2, aucun code.** État des lieux (12 agents), spec rédigée + challengée, CP-1 (4 QCM). **Découverte : la fonctionnalité était déjà construite** sur `feature/caisse-abonnement` (claude-A, 07/09, 5 commits, 1158 lignes, un test de 513 lignes), jamais mergée. Revue read-only (4 agents) → verdict **reprendre-les-idées-refaire** : son cœur « atomique » crée l'abonnement DANS la transaction scellée NF525 (rollback du scel sur échec) = l'inverse de G-1, l'anti-patron que le contradicteur avait fait exclure ; bâtie contre l'ancien `App\Sport` (avant le déplacement→Membership) ; G-3/D19, G-6, G-7, D107 absents ; le test ne compile plus contre main. Réutilisable et porté : idempotence (UNIQUE `source_sale_line_id`), enum `StatutMandatSepa::EnAttente`, gardes de cloisonnement, port `MandateChoice`/`SaleSubscriptionInterface`, câblage prix (G-4), méthodo du test E2E. Plan en 11 étapes, CP-2 tranché en 4 QCM.

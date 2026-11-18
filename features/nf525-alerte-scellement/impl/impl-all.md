# Suivi d'implémentation — nf525-alerte-scellement

**État :** build <!-- discovery → spec → plan → build → review → done -->
**Branche :** feature/nf525-alerte-scellement (partie de feature/nf525-ordre-cles, #369)
**Spec :** features/nf525-alerte-scellement/specs/spec-nf525-alerte-scellement.md
**Plan :** features/nf525-alerte-scellement/plans/plan-nf525-alerte-scellement.md

## Checkpoints

- [x] **CP-1** — spec validée par Maxime le 18/11/2026
- [x] **CP-2** — plan validé par Maxime le 18/11/2026 (« Reprend »), avec les défauts Q-2 à Q-5
- [ ] **CP-3** — revue avant merge (date + qui)

## Étapes (reprises du plan)

<!-- Coche au fur et à mesure. Ne modifie jamais une étape déjà « fait » : crée une nouvelle étape. -->

- [x] Étape 1 — vocabulaire chaîne et cause (G-1)
- [x] Étape 2 — refus porteur de cause, détail, message générique (G-8, G-1)
- [ ] Étape 3 — table, entité, index unique, migration (G-1)
- [ ] Étape 4 — écriture sur la seconde connexion (G-1, G-2)
- [ ] Étape 5 — cloche à l'ouverture (G-4)
- [ ] Étape 6 — service d'enregistrement (G-1, G-2, G-4, G-7, G-8)
- [ ] Étape 7 — branchement des trois handlers (G-1, G-2, G-7, G-8)
- [ ] Étape 8 — quarantaine hors ligne (G-8, G-3)
- [ ] Étape 9 — vérification rompue / intacte (G-3, G-7)
- [ ] Étape 10 — API établissement (G-5)
- [ ] Étape 11 — API éditeur et note (G-6)
- [ ] Étape 12 — frontal : client et fonctions pures (G-5, G-6)
- [ ] Étape 13 — frontal : écran administrateur (G-5, G-4)
- [ ] Étape 14 — frontal : écran éditeur (G-6)
- [ ] Étape 15 — caisse épinglée (G-8)
- [ ] Étape 16 — documentation, décision, clôture

## Journal de Session

<!-- Décisions prises en cours de route, écarts constatés, changements d'avis. Append-only. -->
- 18/11/2026 — Étapes 1-2 faites. Suite ciblée 544 -> 554 tests verts (+10 : SealChainRefTest 5, SealingRefusedExceptionTest 5) ; `tests/Vente` entier 291 verts. Écarts : (a) le constructeur de `SealingRefusedException` devient `(SealRefusalCause, string $technicalDetail, ?string $reference = null)` ; ajout de `cause()` et `reference()` ; (b) causes `key_regression` pour les deux branches d'ordre (clé active quittée, dernier maillon en régression) ; (c) les chemins de `test-stack.sh run` sont relatifs à `app/` (`tests/...`). Garde-fous : 3 en échec pour PHP 8.3 de l'hôte (manifeste vs catalogue, filtres déclarés, appels du frontal dans le vide).

## Journal de Rétropropagation

<!-- Rempli par /verifier-specs : date, écart trouvé, décision prise, fichier spec modifié. -->
- 18/11/2026 — Aiguillage SDD complet. Chercheur : refs/faits-chercheur.md. Arbitrages de Maxime : alerte dédiée par chaîne + cloche à l'ouverture ; admin (securite.gerer) + éditeur ; tous les établissements du profil ; résolution automatique au premier scellement réussi + note éditeur ; vérification rompue = même alerte ; message 409 générique. Spec en attente de CP-1.
- 18/11/2026 — Plan (16 étapes) : seconde connexion DBAL pour que l'alerte survive au rollback. Q-1 tranchée par Maxime : une alerte ouverte par une vérification ne se ferme qu'à une vérification intacte (spec G-7 amendée). Défauts retenus sans objection, à confirmer en CP-2 : Q-2 cause `chain_broken` ; Q-3 texte de cloche proposé par l'architecte ; Q-4 l'administrateur ne voit pas la note de l'éditeur ; Q-5 une archive en défaut ouvre l'alerte de la chaîne vivante (fermée à la prochaine vérification intacte). Fiches : l'agent `documentaliste` du plugin n'est pas chargé en session cloud ; un agent généraliste applique ses règles (doc officielle seulement, docs/references/ seulement).

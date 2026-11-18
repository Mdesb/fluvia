# Suivi d'implémentation — nf525-alerte-scellement

**État :** plan <!-- discovery → spec → plan → build → review → done -->
**Branche :** feature/nf525-alerte-scellement (partie de feature/nf525-ordre-cles, #369)
**Spec :** features/nf525-alerte-scellement/specs/spec-nf525-alerte-scellement.md
**Plan :** features/nf525-alerte-scellement/plans/plan-nf525-alerte-scellement.md

## Checkpoints

- [x] **CP-1** — spec validée par Maxime le 18/11/2026
- [ ] **CP-2** — plan validé par l'humain (date + qui)
- [ ] **CP-3** — revue avant merge (date + qui)

## Étapes (reprises du plan)

<!-- Coche au fur et à mesure. Ne modifie jamais une étape déjà « fait » : crée une nouvelle étape. -->

- [ ] Étape 1 — <objectif> (couvre G-…)
- [ ] Étape 2 — <objectif> (couvre G-…)

## Journal de Session

<!-- Décisions prises en cours de route, écarts constatés, changements d'avis. Append-only. -->

## Journal de Rétropropagation

<!-- Rempli par /verifier-specs : date, écart trouvé, décision prise, fichier spec modifié. -->
- 18/11/2026 — Aiguillage SDD complet. Chercheur : refs/faits-chercheur.md. Arbitrages de Maxime : alerte dédiée par chaîne + cloche à l'ouverture ; admin (securite.gerer) + éditeur ; tous les établissements du profil ; résolution automatique au premier scellement réussi + note éditeur ; vérification rompue = même alerte ; message 409 générique. Spec en attente de CP-1.
- 18/11/2026 — Plan (16 étapes) : seconde connexion DBAL pour que l'alerte survive au rollback. Q-1 tranchée par Maxime : une alerte ouverte par une vérification ne se ferme qu'à une vérification intacte (spec G-7 amendée). Défauts retenus sans objection, à confirmer en CP-2 : Q-2 cause `chain_broken` ; Q-3 texte de cloche proposé par l'architecte ; Q-4 l'administrateur ne voit pas la note de l'éditeur ; Q-5 une archive en défaut ouvre l'alerte de la chaîne vivante (fermée à la prochaine vérification intacte). Fiches : l'agent `documentaliste` du plugin n'est pas chargé en session cloud ; un agent généraliste applique ses règles (doc officielle seulement, docs/references/ seulement).

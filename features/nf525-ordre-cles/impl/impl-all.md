# Suivi d'implémentation — nf525-ordre-cles

**État :** build <!-- discovery → spec → plan → build → review → done -->
**Branche :** feature/nf525-ordre-cles (poussée sur claude/happy-dirac-a6y05h, seule branche autorisée en session cloud)
**Spec :** features/nf525-ordre-cles/specs/spec-nf525-ordre-cles.md
**Plan :** features/nf525-ordre-cles/plans/plan-nf525-ordre-cles.md

## Checkpoints

- [x] **CP-1** — spec validée par Maxime le 18/11/2026
- [x] **CP-2** — plan validé par Maxime le 18/11/2026 (avec la migration d'index)
- [ ] **CP-3** — revue avant merge (date + qui)

## Étapes (reprises du plan)

<!-- Coche au fur et à mesure. Ne modifie jamais une étape déjà « fait » : crée une nouvelle étape. -->

- [ ] Étape 1 — La règle pure (aucun accès base)
- [ ] Étape 2 — Libellés et refus dans le trousseau
- [ ] Étape 3 — Vérification vente (G-1) et archive (G-4)
- [ ] Étape 4 — Vérification compta (G-2)
- [ ] Étape 5 — Vérification facturation (G-3)
- [ ] Étape 6 — Remontée hors connexion : refus d'ancrage
- [ ] Étape 7 — Index (migration écrite à la main)
- [ ] Étape 8 — Requête des intervalles par chaîne
- [ ] Étape 9 — Refus de sceller, compta et facturation (G-5)
- [ ] Étape 10 — Refus de sceller, vente (G-5)
- [ ] Étape 11 — app:nf525:check-keys sur toute la chaîne (G-6)
- [ ] Étape 12 — README et procédure de rotation (G-8)
- [ ] Étape 13 — Clôture

## Journal de Session

<!-- Décisions prises en cours de route, écarts constatés, changements d'avis. Append-only. -->

## Journal de Rétropropagation

<!-- Rempli par /verifier-specs : date, écart trouvé, décision prise, fichier spec modifié. -->

- 18/11/2026 — Aiguillage SDD complet (NF525). Chercheur : `refs/faits-chercheur.md`. Arbitrages de Maxime : ordre lu dans la chaîne ; refus de sceller ; aucun retour à une ancienne clé ; signature du ZIP d'archive hors périmètre (Issue à part). Spec rédigée, en attente de CP-1.
- 18/11/2026 — CP-1 validé. Plan (13 étapes) : l'architecte constate qu'aucun index ne porte `seal_key_id` ; Maxime accepte au CP-2 trois index (migration d'index seulement), spec amendée en conséquence.

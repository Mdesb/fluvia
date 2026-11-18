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

- [x] Étape 1 — La règle pure (aucun accès base)
- [x] Étape 2 — Libellés et refus dans le trousseau
- [x] Étape 3 — Vérification vente (G-1) et archive (G-4)
- [x] Étape 4 — Vérification compta (G-2)
- [x] Étape 5 — Vérification facturation (G-3)
- [x] Étape 6 — Remontée hors connexion : refus d'ancrage
- [ ] Étape 7 — Index (migration écrite à la main)
- [ ] Étape 8 — Requête des intervalles par chaîne
- [ ] Étape 9 — Refus de sceller, compta et facturation (G-5)
- [ ] Étape 10 — Refus de sceller, vente (G-5)
- [ ] Étape 11 — app:nf525:check-keys sur toute la chaîne (G-6)
- [ ] Étape 12 — README et procédure de rotation (G-8)
- [ ] Étape 13 — Clôture

## Journal de Session

<!-- Décisions prises en cours de route, écarts constatés, changements d'avis. Append-only. -->

- 18/11/2026 — Étapes 3-6 faites (non commitées). Branchement du `SealKeyOrderTracker` dans `HashChainSignataire::verifieSegment`, `ScellementEcritureHandler::verifieChaine`, `ScellementFactureHandler::verifieChaine` (≈ 56 lignes de production). Décision : l'`InvalidArgumentException` de `feed()` (séquences non strictement croissantes) devient une anomalie « ordre des clés non vérifiable » (chaîne non intacte, échec fermé), jamais une 500. Libellé de régression via `SealKeyring::regressionProblem`. G-4 sans code propre (test d'archive : maillon refait sans identifiant, manifeste/ZIP re-signés). Étape 6 sans changement de code (409 « Chaîne serveur rompue »). Écart au plan : tests d'archive et d'ancrage utilisent la régression « clé puis NULL » (la seule forgeable avec des clés connues du conteneur), le cas A,B,A est couvert en unitaire vente et en base compta/facturation. Compta/facturation : maillons réels re-signés en mémoire (un test, huit suites), pas de handler en chaîne.

## Journal de Rétropropagation

<!-- Rempli par /verifier-specs : date, écart trouvé, décision prise, fichier spec modifié. -->

- 18/11/2026 — Aiguillage SDD complet (NF525). Chercheur : `refs/faits-chercheur.md`. Arbitrages de Maxime : ordre lu dans la chaîne ; refus de sceller ; aucun retour à une ancienne clé ; signature du ZIP d'archive hors périmètre (Issue à part). Spec rédigée, en attente de CP-1.
- 18/11/2026 — CP-1 validé. Plan (13 étapes) : l'architecte constate qu'aucun index ne porte `seal_key_id` ; Maxime accepte au CP-2 trois index (migration d'index seulement), spec amendée en conséquence.
- 18/11/2026 — Étapes 1-2 faites : 48 tests OK (153 445 assertions), tests/Platform/Unit 129 OK, rotations vente/compta/facturation OK. security-reviewer : 8 constats corrigés (entrées validées en échec fermé, conflicts O(n log n), identifiants nettoyés, test exhaustif {NULL,'',A,B,C} ≤ 6 dans les deux sens). relecteur : PASS WITH NOTES. Reporté à l'étape 9 : phrase « clé NEUVE » réservée à ACTIVE_KEY_LEFT ; nommer la clé non NULL de plus grand `last` pour un dernier maillon NULL ; tests de branche (ACTIVE_KEY_LEFT asserté, dernier maillon NULL, clé active C absente des messages, otherKeyId hostile). À l'étape 3 : décider si l'exception de feed() devient une anomalie. Hors lot : assertCanExtend recopie encore l'id brut. Garde-fous : 56 OK sans vendor/ ; avec vendor/ installé et PHP 8.3 sur l'hôte, 3 échecs d'environnement (témoin : les mêmes 3 sans nos changements). Plugin garde-fous-sdd non chargé en session cloud : /garde-fous-sdd:revue-sensible indisponible, revues consignées ici.
- 18/11/2026 — Étapes 3-6 : témoin de mutation (code de production retiré → 6 échecs + 1 erreur vente, 2 compta, 2 facturation, 2 hors ligne/archive ; remis → verts). security-reviewer adversarial : PASS WITH NOTES, aucun bloquant. Reporté : (a) AVANT FUSION, mesurer la règle en lecture seule sur la préprod (commande de l'étape 11) — une chaîne réelle déjà en régression bloquerait la remontée hors ligne ; (b) étape 9 : passer `problem()`, `assertCanExtend` et `CheckSealKeysCommand::provenance` par `safeId` ; (c) étape 12 : le README dit la limite de l'archive (un segment entier re-signé avec une clé retirée et un id uniforme n'est pas vu par la règle).

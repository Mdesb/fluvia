# Suivi d'implémentation — personnel-rh (lot 1)

**État :** plan <!-- discovery → spec → plan → build → review → done -->
**Branche :** feature/personnel-rh
**Worktree :** /home/debian/wt/personnel
**Spec :** features/personnel-rh/specs/spec-personnel-rh.md
**Plan :** features/personnel-rh/plans/plan-personnel-rh.md
**Faits vérifiés :** features/personnel-rh/refs/faits-verifies-rh.md

## Checkpoints

- [x] **CP-1** — spec validée par Maxime le 2026-09-08, avec trois amendements portés dans la spec :
      seuil d'expiration **réglable par établissement** (et non 14/30 en dur), fiche employé affichant
      absences et badges **en lecture seule**, rattachement **facultatif** à la création (« on va
      revoir cette notion de site »).
- [x] **CP-2** — plan rendu le 2026-09-08 (`plans/plan-personnel-rh.md`, 8 étapes + 10 risques).
      Porte automatique : le gate est la CI verte, pas un audit humain.
- [ ] **CP-3** — revue avant merge

## Étapes (reprises du plan)

<!-- Coche au fur et à mesure. Ne modifie jamais une étape déjà « fait » : crée une nouvelle étape. -->

- [x] **Étape 1** — semer des employés de démonstration, `HrDemoFixtures` (G-8) — **faite le 08/09**
- [x] **Étape 2** — ⚠ périmètre d'écriture de `RattachementEmploye` (G-5) — **faite le 08/09, mais pas
      comme prévu** : le processeur prévu ne refusait rien et a été **supprimé** ; l'étape livre le
      test qui fige le mécanisme réel et mesure la limite ouverte
- [ ] **Étape 3** — onglet « Qualifications » : saisie, correction, échéances (G-1, G-2)
- [ ] **Étape 4** — ⚠ fiche employé : identité modifiable, rattachements, bandeau orphelin (G-3, G-4)
- [ ] **Étape 5** — ⚠ réparer l'émission de badge + portée d'accès (G-9, G-7) — *contrôle d'accès physique*
- [ ] **Étape 6** — modifier un créneau, lien roster → qualification (G-6)
- [ ] **Étape 7a** — ⚠ `HrSettings` : entité, migration, résolveur, commande (G-2b) — *entité neuve*
- [ ] **Étape 7b** — ⚠ `HrSettings` : exposition et écran de réglage (G-2b) — *provider sur mesure*
- [ ] **Étape 8** — clôture : cliquets, suite complète, exécution des critères

**Ordre d'exécution :** 1 → 2 → (3, 7a en parallèle) → 4 → 5 → 6 → 7b → 8.
L'étape 2 est un **prérequis dur** de l'étape 4 : cet écran envoie `employe` et `etablissement` dans le
corps ; le livrer avant sa garde exposerait l'écriture non gardée à un vrai utilisateur.

## Journal de Session

<!-- Décisions prises en cours de route, écarts constatés, changements d'avis. Append-only. -->

**2026-09-08 — Origine du chantier.** Demande de Maxime : « le module personnel est incomplet ».
Mesure préalable plutôt qu'inventaire de mémoire : `frontend/scripts/mesurer-ecart.mjs` et un
inventaire des opérations exposées par `app/src/Personnel/`. Résultat : **13 opérations sur ~36 ne
sont déclenchables par personne** — `Qualification` (4), `RattachementEmploye` (5),
`PorteeAccesEmploye` (2), plus les `PATCH` de `Employe` et de `CreneauTravail`. Deux d'entre elles
forment des **cul-de-sac** (le produit refuse un geste et n'offre aucun moyen de lever le refus) :
la qualification exigée par `AffecterEmployeProcessor:100` sans écran pour la saisir, et le
rattachement exigé par `EmissionBadgeStaffHandler:122-138` sans écran pour le poser — alors que
l'écran de création d'employé n'en pose jamais.

**2026-09-08 — Le périmètre est un renversement, pas un ajout.** `specs/personnel/spec-personnel.md:72`
exclut nommément « recrutement, entretiens, dossiers administratifs complets (contrats signés,
bulletins de salaire, DPAE) », et `:59-63` délègue la paie à un SIRH externe. Maxime a arbitré le
2026-09-08 que Fluvia irait « jusqu'aux bulletins et à la DSN », après avoir lu la réserve énoncée
(DSN normée NEODeS, erreur de paie = contentieux prud'homal). **À inscrire dans `DECISIONS.md`** :
sans ça, la prochaine session qui lit la spec construira contre lui.

**2026-09-08 — Suites annoncées, hors lot 1** (détaillées dans la spec) : l'employé comme *ressource*
d'activité liée à l'agenda — ⚠ `WorkShiftsCalendarSource` limite **délibérément** la publication aux
créneaux de celui qui regarde, élargir est un renversement de décision écrite ; et les
**indépendants** (un guide prestataire qui facture, sa facture devant apparaître des deux côtés).

**2026-09-08 — Trois instruments menteurs en montant l'environnement.** Consigné parce que chacun
avait l'apparence d'un succès :

1. `git worktree add … origin/main` m'a placé sur **GitHub/main**, qui est une histoire réécrite par
   la propagation scrubbée — 2 390 commits d'écart avec `bare/main`. Dans ce clone, `origin` = GitHub
   et `bare` = le dépôt nu. La branche a été réalignée sur `bare/main`.
2. `composer install` par ssh : **`composer` n'existe pas sur l'hôte** (tout passe par Docker), et le
   pipe vers `tail` a rendu **exit 0** sur un `command not found`. J'ai cru l'environnement prêt.
3. `./infra/test-stack.sh run` sort en **code 0** quand phpunit est absent, et `up` sort en **code 0**
   sans avoir créé la base quand `vendor/` manque. La suite a ensuite rendu « 42 tests, 38 erreurs »
   pour un `Unknown database`, pas pour une régression.
   ⚠ Le message d'erreur du harnais oriente vers `./infra/reinstaller-dev.sh`, **qui vise la
   préproduction** (`compose.preprod.yaml`) : un worktree qui suit ce conseil ne répare rien chez lui
   et redémarre le PHP-FPM partagé. Le remède pour un worktree est
   `docker run --rm -u "$(id -u):$(id -g)" -v <worktree>:/repo -w /repo/app billetterie-preprod-php composer install`.
   (Le `cache:clear` post-install échoue en 255 par manque de mémoire — sans conséquence : `php_run`
   monte `zz-memory.ini`, pas ce conteneur-là.)

⚠ `composer install` salit `app/config/reference.php`, qui est **suivi** : `git restore` avant tout
commit (règle du `CLAUDE.md`).

**2026-09-08 — La planification a corrigé DEUX faits de la spec validée.** Consigné parce qu'une spec
qui se corrige en silence n'apprend rien à la session suivante.

1. **Un troisième cul-de-sac, non vu à la spec.** `client.js:2418-2419` envoie `body: {}` à
   `POST /personnel/employes/{id}/badges`, qui exige `etablissement`, `modeHoraire` et au moins un
   `espacesAutorises`. **Le bouton « Émettre » n'a jamais pu aboutir.** Vérifié de mes yeux
   (`EmissionBadgeStaffProcessor.php:66,73-76` et `EmissionBadgeStaffHandler.php:75-77`), pas relayé.
   La suite PHP est verte parce que `BadgeStaffTest.php:29-32` envoie le corps **complet** : le
   serveur était prouvé, l'écran ne l'était pas. → nouvel objectif **G-9**, nouvelle **étape 5**, sans
   laquelle **G-4 n'est pas démontrable**.
2. **Un zéro faux que j'avais relayé.** La spec affirmait que le handler « ne pose aucune zone
   (0 occurrence de `addAuthorisedSpace`) ». La méthode réelle est **`addEspaceAutorise`** ;
   `addAuthorisedSpace` est celle d'une **autre classe** (`Acces\Entity\DroitAcces:365`). Le serveur
   pose les zones et **refuse** d'émettre sans zone. L'exigence d'écran qui en découlait est
   supprimée — elle aurait produit un état vide inatteignable par construction. Corrigé dans la spec
   **et** dans `refs/faits-verifies-rh.md`, avec la trace : la phrase avait déjà voyagé en trois
   endroits.

**2026-09-08 — Angle mort mesuré du garde-fou n°28.** Son motif `['"]([\w.]+)['"]` ne matche pas les
entrées `{root}.` de `CHEMINS_DIRECTS` : il ne voit que 2 des 5 entrées de
`PerimetrePersonnelExtension`. **Un vert du n°28 sur `HrSettings` ne prouvera rien** ; la preuve du
cloisonnement doit être le test croisé A/B de l'étape 7b. Non corrigé ici : le garde-fou est partagé
par dix sessions.

**2026-09-08 — Étapes 1 et 2 livrées. Ligne de base 42 tests / 329 assertions → 47 / 348, verte.**

*Étape 1* — `HrDemoFixtures` : 6 employés, 4 qualifications (J−30, J+7, J+21, J+400), 6 rattachements,
1 créneau, 1 affectation. **Idempotence prouvée par recomptage** après un second chargement en
`--append` : 6 / 4 / 6 / 1 / 1 inchangés. Le témoin négatif de l'étape (l'orphelin invisible au rôle
*Lecture seule*) était **déjà couvert** par `CloisonnementPersonnelTest:84,89` — pas de doublon écrit.

*Étape 2* — **le correctif prévu ne corrigeait rien, et a été supprimé.**
`StaffAssignmentScopeProcessor` a été écrit, branché sur `Post`/`Patch`, testé. Suite lancée **avec et
sans lui : 5 tests, 18 assertions, résultats identiques**. Le refus arrive avant tout processeur, à la
**dénormalisation** : API Platform résout l'IRI par le provider d'item, donc à travers l'extension de
cloisonnement → 400 « Item not found ». Et `PerimetreEmployeVerificateur` est **strictement plus large**
que ce filtre (« une affectation quelconque » contient « l'établissement actif »), donc il n'avait
aucun cas où mordre.

> Un contrôle placé après un filtre plus strict que lui ne refuse jamais rien — et tous ses tests de
> refus passent, ce qui le fait paraître utile. C'est le seul cas où **débrancher** le correctif est la
> mesure qui compte.

Livré à la place : `app/tests/Personnel/Api/RattachementScopeTest.php`, qui fige le mécanisme réel
(2 refus, 2 témoins « doit rester autorisé ») et **mesure la limite qui reste ouverte**.

**⚠⚠ Constat de sécurité, plus large que l'audit ne le disait.** L'audit bornait le risque à « connaître
l'UUID d'un orphelin étranger ». Mesuré : **l'orphelin se parcourt**. Un `Employe` sans rattachement
apparaît dans la **collection** `/api/employes` de tout détenteur de `personnel.gerer_employe`, quel
que soit son établissement actif (`PerimetrePersonnelExtension:210` — `NOT EXISTS (rattachement)`, sans
condition de groupe). Or un employé sans rattachement **n'appartient à aucun établissement, donc à
aucun client**, et l'écran de création n'en pose jamais : **tout employé créé par le produit est
aujourd'hui dans cet état, définitivement.** → L'étape 4 n'est pas un confort. Le fermer entièrement
demande un ancrage sur `Employe` : migration, lot à part, arbitrage Maxime.

## Journal de Rétropropagation

<!-- Rempli par /verifier-specs : date, écart trouvé, décision prise, fichier spec modifié. -->

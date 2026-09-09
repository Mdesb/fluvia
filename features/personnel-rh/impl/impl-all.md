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
- [x] **Étape 3** — onglet « Qualifications » : saisie, correction, échéances (G-1, G-2) — **faite le
      08/09**, vérifiée **en exécutant** (lecture ET écriture) contre une API réelle
- [x] **Étape 4** — ⚠ fiche employé : identité modifiable, rattachements, bandeau orphelin (G-3, G-4)
      — **faite le 08/09**, l'enchaînement complet vérifié en exécutant
- [x] **Étape 5** — ⚠ réparer l'émission de badge + portée d'accès (G-9, G-7) — **faite le 09/09**,
      corps accepté par le serveur (201) et portée affichée
- [~] **Étape 6** — modifier un créneau **fait le 09/09** (G-6, prouvé : l'affectation survit) ;
      **le lien roster → qualification reste à faire** (il demande d'ajouter l'identité des employés
      à la charge utile de `RosterProvider`, un provider sur mesure et partagé)
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

**2026-09-08 — Étape 3 : l'onglet Qualifications, et le défaut que seule l'exécution montre.**

Trois appels clients (`qualifications`, `creerQualification`, `majQualification`) et un 6ᵉ sous-onglet.
Le `Get` d'item n'est **volontairement pas branché** : aucun écran ne le consomme, et une fonction
cliente qu'aucun écran n'appelle est comptée comme « appel orphelin » par la mesure d'écart. On
branche ce qu'on utilise → l'écart descend de **529 à 526** (3 opérations, pas 4 comme le plan
l'annonçait).

**⚠ LA COLONNE « EMPLOYÉ » SORTAIT VIDE SUR TOUTES LES LIGNES.** `Employe::$nom` et `$prenom` sont
dans `employe:read`, `affectation_travail:read`, `absence:read`, `badge_staff:read`, `roster:read` —
**pas dans `qualification:read`**. Le serveur rend donc
`"employe": { "@id": …, "@type": "Employe", "id": "<uuid>" }` : c'est bien un **objet**, le test
`typeof === 'object'` passait, et l'affichage rendait « — » partout.

Ni le build, ni les trois garde-fous front, ni un test d'API ne pouvaient le voir. Trouvé en ouvrant
l'écran contre l'API réelle. Corrigé côté front (résolution par l'index des employés déjà chargé pour
le filtre) plutôt que d'élargir `qualification:read` — ça aurait alourdi toutes les lectures de
qualification et touché une entité que d'autres sessions utilisent.
⚠ Et quand l'employé n'est pas dans l'index, l'écran le **dit** (« employé hors de la liste chargée »)
au lieu d'un tiret : `employe` est `nullable: false`, donc un tiret affirmerait une absence
impossible — une ignorance déguisée en fait.

**Vérifié en exécutant, et voici sur quel artefact** : serveur Vite sur le worktree
(`/home/debian/wt/personnel/frontend`, port 5241) contre un serveur PHP jetable branché sur la base de
test `app_testpersonnelrh` (port 8241), fixtures rechargées, compte `personnel.rh@itcotation.com`,
établissement actif « Site A Personnel ». **Rien de partagé n'a été touché** — ni la préproduction, ni
sa base.

- Lecture : les trois états s'affichent distinctement et dans le bon ordre — *expirée depuis 30 j*
  (Camille Renard, BNSSA), *expire dans 7 j* (Dominique Alvarez, MNS), *valide* (Dominique Alvarez,
  BNSSA). Les noms sont résolus ; sans le correctif, trois tirets.
- Écriture : création d'une qualification BAFA pour Alex Wei, valide au 30/06/2027 → la ligne apparaît,
  nom résolu, et **la ligne existe en base** (vérifiée par requête SQL, pas au code de retour).
  C'est le geste qui révèle un défaut de type de contenu (415 sur `ld: true`), qu'aucun test d'API ne
  voit puisqu'ils posent l'en-tête à la main.

⚠ Premier essai du montage : le `GET /api/qualifications` **sans en-tête `X-Etablissement`** rend
`totalItems: 0` avec 4 lignes en base. Le zéro venait du cloisonnement, pas d'une absence.

**2026-09-08 — Étape 4 : la fiche employé. Le second cul-de-sac est refermé.**

Fiche ouverte depuis la liste : identité corrigeable (G-3), rattachements posables, clôturables et
retirables (G-4), bandeau orphelin, badges et absences **en lecture seule**. Écart : **526 → 520**
(6 opérations), et `majRattachement` n'est plus un appel orphelin — il en restait 15, il en reste 14,
tous antérieurs.

**Clore ≠ retirer, et les deux gestes existent.** On *clôt* le rattachement d'un salarié qui ne
travaille plus sur le site : la ligne reste avec sa date de fin, `estActifA()` la lit, et
`EmissionBadgeStaffHandler` s'en sert — donc clore suffit à couper l'éligibilité au badge **sans
effacer l'historique** que la paie relira. On *retire* une ligne saisie par erreur. Même distinction
que suspendre/révoquer sur un badge.

**⚠ LE MÊME DÉFAUT DE RELATION, UNE SECONDE FOIS — ET SOUS UNE AUTRE FORME.** La colonne « Site »
sortait vide. Mesuré sur la pile de test, **deux formes dans la même réponse** :

    "employe": { "@id": …, "@type": "Employe", "id": … }   ← objet, sans nom ni prénom
    "etablissement": "/api/etablissements/<uuid>"          ← IRI PURE, une chaîne

Mon test `typeof === 'object'` échouait donc pour une raison **opposée** à celle des qualifications.
`idDe` absorbe les deux formes. Et le chargement des établissements a été **sorti de la condition
`peutGererEmploye`** : il sert aussi à l'affichage, le conditionner au droit d'écriture rendait la
colonne illisible pour un lecteur.

**Vérifié en exécutant** (Vite sur le worktree, port 5241, proxy vers un serveur PHP jetable sur la
base `app_testpersonnelrh` — **ni la préproduction ni sa base touchées**) :

- Fiche d'un employé **orphelin** → le bandeau s'affiche avec ses deux conséquences mesurées.
- Rattachement posé depuis la fiche → **le bandeau disparaît**, le site s'affiche nommé.
- Poste corrigé → enregistré, visible dans la liste, **et en base** (`Éducateur sportif — natation`).
- `PATCH` de clôture prouvé par l'API avec le **type de contenu exact du client**
  (`application/merge-patch+json`) → 200, `fin` posée **et en base**.
  ⚠ Le geste « Clore » lui-même n'a **pas** pu être déclenché à la souris : `confirmer()` retombe sur
  `window.confirm`, que le pilote de navigateur rejette automatiquement. Ce qui est prouvé est
  l'appel, pas le clic.

**⚠ Et le défaut §0.1 du plan est confirmé EN VRAI** : un clic sur « Émettre un badge » rend
« Référence "etablissement" obligatoire (UUID ou IRI) ». Le bouton n'a jamais pu aboutir — c'est
l'étape 5.

**Pièges d'outillage rencontrés, tous coûteux en temps :**
1. Le serveur PHP intégré est monoprocessus — `PHP_CLI_SERVER_WORKERS=6` est nécessaire, sinon le
   préflight et la requête se bloquent mutuellement.
2. **L'identifiant d'établissement lu en SQL était périmé** : recharger les fixtures régénère les
   UUID. Le lire **par l'API**, juste avant usage.
3. `allow_headers` de la conf CORS ne contient pas `X-Etablissement` — sans effet sur l'application
   (Vite proxifie, donc même origine), mais tout appel manuel en absolu depuis la page est bloqué.
4. Le repère de coordonnées du pilote (800×450) n'est pas le viewport CSS (1280×720) : facteur 0,625.

**2026-09-09 — ⚠ CHANGEMENT DE TRONC : le lot a été rejoué de `bare/main` sur `origin/main`.**

Une session pair (`allaccess-28`) a annoncé une décision de flotte : `origin/main` (GitHub) est le
tronc unique et la source de déploiement ; `bare/main` est abandonné. **Mesuré avant d'agir**, parce
qu'un message de pair n'est pas une mesure :

| | |
|---|---|
| `origin/main` | `9e77ab66`, **08/09 à 20:52** — vivant, travail du jour dessus |
| `bare/main` | `7f95d7c3`, **07/09 à 00:03** — dormant depuis un jour et demi |
| ancêtre commun | `eca89b4a` — **il en existe un** |
| divergence | 2 393 commits d'un côté, 2 417 de l'autre |
| fusion à blanc | **55 conflits** (workflows, `CLAUDE.md`… rien à voir avec ce lot) |

⚠ **Une correction au message du pair** : il annonçait une histoire « INCOMPATIBLE ». Il existe bien
un ancêtre commun — les histoires ne sont pas disjointes, elles ont massivement divergé (le scrub
public). La conclusion pratique du pair est juste (on ne fusionne pas la branche telle quelle) ; sa
justification ne l'est qu'à moitié, et la nuance compte : c'est ce qui rend le **cherry-pick**
possible là où un merge est impraticable.

**Manœuvre** : branche `sauvegarde/personnel-rh-sur-bare` posée en filet, puis
`feature/personnel-rh-lot1` créée depuis `origin/main`, et les trois commits rejoués par
`cherry-pick`. **Aucun conflit** — quatre des six fichiers sont neufs, `Personnel.jsx` était
identique des deux côtés, et `client.js` s'est auto-fusionné.

Vérifié après bascule : build front vert, garde-fous front verts (droits, imports, portée),
`composer.lock` et `package-lock.json` **identiques** entre les deux troncs (donc `vendor/` et
`node_modules/` restent valides), 1 seule migration d'écart.

⚠ **Les chiffres d'écart des messages de commit se lisent sur l'ANCIENNE base** (529 → 526 → 520).
Sur `origin/main`, la même mesure donne **514 inatteignables / 676 atteignables** : le tronc vivant
porte davantage d'écrans. Les deux mesures sont justes, elles ne portent pas sur le même arbre.

**2026-09-09 — Étape 5 : l'émission de badge, et un cul-de-sac de DROITS que personne n'avait vu.**

`emettreBadgeStaff` envoyait `body: {}` ; le serveur exige `etablissement`, `modeHoraire` et au moins
un `espacesAutorises`. Le bouton ouvre désormais une modale qui recueille les trois — ce ne sont pas
des formalités : **elles décident quelles portes s'ouvrent et quand**. Et la colonne « Ouvre » rend
la portée lisible (G-7), ce qu'aucun écran ne faisait.

**⚠ TROISIÈME CUL-DE-SAC, ET IL EST DANS LES DROITS, PAS DANS LES ÉCRANS.**
`/api/espace_acces` exige `acces.lire` (`EspaceAcces.php:33`). Le rôle *Personnel Administrateur RH*
détient `personnel.gerer_badge` — le droit d'**émettre** — mais **pas `acces.lire`**
(`PersonnelFixtures.php:105-108` : `gerer_employe`, `gerer_qualification`, `gerer_badge`, `lire`,
`acces.ingestion`).

Donc **le rôle prévu pour émettre un badge ne peut pas lire les espaces que l'émission exige** :
403 sur la liste, alors qu'il y a 8 espaces en base. Mesuré le 09/09.

Ce n'est pas corrigé ici : le jeu de droits d'un rôle est un arbitrage produit, pas une décision
d'écran. **À remonter à Maxime** — c'est la même famille que le constat déjà consigné sur les
documents (le rôle RH n'a aucune permission `dms.*`, ce qui bloquera le lot 2 avant d'écrire une
ligne).

**⚠ ET MON PROPRE CODE COMMETTAIT LE MENSONGE DU ZÉRO.** La première version faisait
`.catch(() => setEspaces([]))` : le 403 s'affichait comme **« Aucun espace d'accès sur ce site »**.
L'écran affirmait une absence qu'il n'avait pas mesurée, et envoyait l'exploitant créer un espace qui
existe déjà. Corrigé en trois états (`null` / `[]` / erreur), et **vérifié à l'écran** : il affiche
maintenant « Les espaces d'accès n'ont pas pu être lus : accès non autorisé… ».

**Vérifié en exécutant** (même montage : Vite sur le worktree, serveur PHP jetable sur
`app_testpersonnelrh` — rien de partagé touché) :

- Le bouton ouvre la modale au lieu de rendre 422. *Avant : « Référence "etablissement" obligatoire ».*
- **Le corps exact que la modale construit est accepté : 201**, badge créé pour Camille Renard
  (prouvé par l'API en fournissant l'espace directement, ce qui **isole** le blocage : le corps est
  bon, seule la lecture des espaces est refusée).
- La colonne « Ouvre » affiche `1 espace(s) · pendant ses creneaux (± 15 min)`. Le libellé n'apparaît
  pas faute de `acces.lire` — l'écran affiche alors le **nombre**, il n'invente pas de nom.

Écart : 514 → **513** (une seule opération neuve atteignable : `GET /api/portee_acces_employes` ;
le `Get` d'item n'est pas branché, aucun écran ne le consomme).

**2026-09-09 — Étape 6 (première moitié) : corriger un créneau, et un décalage de deux heures.**

Le `Patch` de `CreneauTravail` existait sans appelant : corriger un horaire imposait d'annuler puis
recréer, ce qui **perd les affectations déjà posées**. Sur un planning monté pour la semaine, les
reprendre une par une est exactement la corvée qui fait qu'on ne corrige pas.

Ajouté au passage : le champ **« qualification exigée »**, qui n'était proposé **nulle part** — ni à
la création. C'est pourtant lui qui déclenche le refus d'affectation (CA-5) et le badge rouge du
roster : un créneau pouvait exiger un brevet sans que personne ne l'ait choisi.

La liste des types est sortie dans `frontend/src/api/qualifications.js` : deux écrans la proposent
désormais (la saisie d'une qualification, et le créneau qui en exige une), et deux copies du même
énuméré divergent tôt ou tard — le jour où l'une gagne un type que l'autre ignore, un créneau exige
un brevet qu'aucun écran ne sait saisir.

**⚠ UN CRÉNEAU SAISI « 9H » ÉTAIT ENREGISTRÉ À 9H UTC, DONC RELU À 11H — ET CE N'EST PAS MOI.**

Le formulaire envoyait `${jour}T${heure}:00`, **sans fuseau**. Mesure du 09/09 :
`date_default_timezone_get()` rend **UTC** dans le conteneur, et aucun `Europe/Paris` n'est configuré
(`config/packages/*.yaml` et `docker/php/conf.d/*.ini` muets). Le serveur interprétait donc « 09:00 »
comme 9h UTC, et `dateHeureFr` réaffichait 11h l'été. **Le défaut préexistait à la création.**

**⚠ Et mon édition l'aurait aggravé à chaque passage** : le champ montre l'heure locale (11h) ; la
renvoyer sans fuseau l'aurait enregistrée comme 11h UTC, donc relue à 13h, puis 15h. **Un
aller-retour sans modification aurait déplacé le créneau de deux heures.**

Corrigé par `enInstant()` : `new Date(a, m-1, j, hh, mm).toISOString()` — ce qui part est l'**instant
voulu**, pas les chiffres tapés. Le garde-fou `verifier-dates-locales.mjs` reste vert.

⚠ **RÉSERVE : les créneaux enregistrés AVANT ce correctif gardent leur décalage.** Leur instant en
base est faux ; seule une reprise de données le corrigerait. Ce correctif empêche d'en créer de
nouveaux, il ne répare pas les anciens. **À vérifier sur la préproduction.**

**Vérifié en exécutant** — le test qui compte est l'aller-retour :

- Modale « Corriger le créneau » ouverte, valeurs reprises (11:00–19:00, effectif 3, BNSSA).
- Enregistré **sans rien changer** → l'affichage reste **11:00–19:00**. Sans le correctif : 13:00.
- En base : instant **inchangé** (09:00–17:00 UTC), **affectation survivante (1)**, qualification et
  effectif conservés. G-6 porte sur ce qui **survit**, pas sur le code de retour.

Écart : 513 → **512**.

**Ce qui reste de l'étape 6** : le badge rouge du roster n'est pas encore cliquable. Il demande
d'ajouter l'`id` de l'employé et de la qualification à la charge utile de `RosterProvider` — un
provider **sur mesure**, donc hors extension Doctrine, qui filtre lui-même. Un champ ajouté à sa
sortie est une **nouvelle voie de sortie** : `ProvidersCloisonnementTest` devra être étendu **à ce
champ**, pas seulement relancé.

## Journal de Rétropropagation

<!-- Rempli par /verifier-specs : date, écart trouvé, décision prise, fichier spec modifié. -->

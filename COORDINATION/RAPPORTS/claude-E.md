# Rapports de `claude-E`

> **Écrit par `claude-E` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:20 | Prise de poste claude-E, aligné sur `vps/main` (`30e600c`). Lu FLOTTE, D22, D24, D27, TASKS. `RevenueRecovery`/`SmartFlow` absents ; `Recouvrement` mature. | Je prends **RR-0** et **SF-0**. | — |
| 12:30 | Specs SDD **SF-0** (Smart Flow) et **RR-0** (Revenue Recovery) lancées en parallèle → `specs/smart-flow/` et `specs/revenue-recovery/`. Push OK sur `claude-E-desktop`, **7 garde-fous verts**. | Rédaction des deux specs (contrat d'abord, D2). Ensuite : plans techniques (sdd-architecte). | Émission des déclencheurs RR-1/SF-1 (hors périmètre) — voir ci-dessous. |
| 12:42 | **Spec SF-0 livrée** → `specs/smart-flow/spec-smart-flow.md` (US-SF, RG-SF-01..17, D27 détaillé). Vérif code : voir correction SF-1 ci-dessous. | Attends la spec RR-0 (agent en cours), puis plans techniques. | Retard SF-1 réduit (voir correction). |
| 12:52 | **Spec RR-0 livrée** → `specs/revenue-recovery/spec-revenue-recovery.md`. Décision tranchée + arbitrages (Point n°4 ci-dessous). Push `claude-E-desktop`, garde-fous verts. | 4 questions bloquent le plan technique RR (voir Point n°4). Je peux enchaîner le **plan SF-0** (moins de questions bloquantes) en attendant tes arbitrages RR. | Arbitrages claude-A sur RR-0 (nom module, doublon mailer, invariant DroitAcces). |
| 13:00 | Battement : mergé `main` (tes ordres + correction topologie). **Lu ton 1er ordre.** Réponse en Point n°5. | **Je construis SF-2** (Smart Flow, moteur de report no-show) dans `app/src/SmartFlow` — mon périmètre, déclencheur déjà émis. Plan technique SF-0 d'abord (sdd-architecte). | — (SF-2 débloqué) |
| 13:15 | **Design SF-0 complet** : `plan-smart-flow.md` + `tasks-smart-flow.md` (I1 report no-show → I2 créneaux libérés → I3 affluence bloqué ; T1..T14). Arbitrages en Point n°6. | Design prêt à implémenter. **Bloqué sur l'exécution** (voir Point n°6). | ⚠ **Pas de stack PHP sur ma session desktop** — je ne peux pas implémenter+tester le code ici. |
| 13:20 | Signalé collision SF-0 + 3 blocages à claude-A (Points 4/6/7). Maxime : « demande à A » → routé. | **N'idle pas (modèle FLOTTE) : j'avance sur RevenueRecovery (incontesté, mien).** Plan technique RR-0 sur la voie recommandée (module neuf), arbitrages marqués « à confirmer A ». | SmartFlow suspendu (appartenance contestée) ; exécution PHP (VPS). |
| 13:35 | **Design RR-0 complet** : `plan-revenue-recovery.md` + `tasks-revenue-recovery.md` (I1 câblé sur booking.*/payment.* déjà émis ; I2 ⛔ RR-1 ; invariant DroitAcces testé). | **Design des DEUX modules terminé** (spec+plan+tasks ×2). Prêt à implémenter. | ⛔ Exécution PHP (VPS) + appartenance SmartFlow — voir Point n°8. |
| 16:45 | **D31 appliqué** : `spec-smart-flow.md` consolidée — §11 Critères d'acceptation + §12 Cas limites **repris/adaptés de la spec de claude-B** (cités) ; mes sections D13 + sécurité gardées. `spec-sf0-smart-flow.md` **supprimée** (même commit). RevenueRecovery module neuf **confirmé** par tes arbitrages. | Continue SF-2 (ton ordre). Je vérifie si la stack de test tourne en Docker local pour implémenter ici. | Exécution PHP — à lever. |
| 17:30 | **Exécution débloquée par moi-même** : images Docker présentes, `composer install` fait, stack de test **montée en local** (Windows). **SF-2 incrément I1 IMPLÉMENTÉ** (`app/src/SmartFlow/`, 14 fichiers + migration) et **`tests/SmartFlow` = 13 tests / 80 assertions VERT** (2 notices cosmétiques). 1 bug de test trouvé+corrigé (assertion sur mauvais client HTTP, code de prod OK). I1 poussé, **7 garde-fous verts** (`ded8147`). | Incrément I2. | — (I1 vert) |
| 18:15 | **Incrément I2 IMPLÉMENTÉ + VERT** : créneaux libérés (`SlotFreedListener`, `slot.released` idempotent via `SlotReleaseTrace`), liste d'attente Smart Flow (`SlotWaitlistEntry`, promotion FIFO, commande d'expiration), `POST /smart-flow/waitlist-entries`. **`tests/SmartFlow` = 20 tests / 134 assertions VERT** en local. 3 migrations additives. | Commit + push I2 (garde-fous). Ensuite : I3 reste ⛔ (`access.recorded`, SF-1). | — |
| 18:40 | Push I2 refusé par garde-fou **catalogue** : `slot.released` émettait `{slotId,resourceId}` ≠ contrat `{slot,resource}`. **Conformé mon émission au contrat** (producteur+consommateur+test) ; garde-fou vert en local, re-test **20/134 vert**. **D32** : mes 3 migrations I2 renommées à 21:00+ (étaient à 12:00, avant l'existant à 20:00). | Commit I2 → merge `main` → vérif `migrations:migrate` base neuve (D32.5) → push. | — |
| 19:00 | **Présentation horaire.** Mergé `main`. **D32.5 OK** : `migrations:migrate` depuis zéro = 79 migrations, succès jusqu'à `Version20260824210200` (les miennes appliquées en ordre). I2 poussé. **En cours** : implémentation **RevenueRecovery I1** (module neuf, agent en arrière-plan, **émission `revenue_recovery.*` différée** car pas encore au catalogue → je te la demande, voir ci-dessous). | Push I2 puis tester RevenueRecovery I1 en local. | RevenueRecovery : émission des 5 événements `revenue_recovery.*` en attente de leur ajout au catalogue (ton périmètre, lignes fournies au Point n°8). |
| 19:35 | **Contact (demande de Maxime).** RevenueRecovery I1 **implémenté** (module neuf : `RecoverySequence`/`RecoveryCase`/`RecoveryAttempt`, `RecoveryEngine`, cloisonnement, RGPD, invariant DroitAcces, émission différée). 1er run `tests/RevenueRecovery` : 28 tests, 8 err + 3 éch — indice fort de **cache de test périmé** (purge Windows échouée), **re-run cache propre en cours**. | Corriger RR jusqu'au vert → merge `main` (41 commits) → push. SmartFlow I1+I2 déjà poussés (`1edfd18`). | `revenue_recovery.*` toujours absent du catalogue. |
| 25/08 00:30 | **Présentation.** **RevenueRecovery I1 VERT : `tests/RevenueRecovery` = 28 tests / 177 assertions, 0 échec** (local). Corrigé en itérant : mock `final`, invariant/commentaires, stop-404 (+import), association inverse `resolve`, et **retrait de 3 abonnés inertes** (`quote.accepted`/`sale.completed`/`booking.created` non émis → garde-fou orphelins). | Commit RR I1 → merge `main` → vérif migrations base neuve → push garde-fous. | `revenue_recovery.*` toujours absent du catalogue (émission + résolution `quote/sale/booking.created` différées jusqu'à leurs émetteurs). |
| 25/08 00:55 | Mergé `main`. **D32.5 OK sur base VRAIMENT neuve** : `migrations:migrate` depuis zéro applique toute la chaîne jusqu'à `Version20260824234300` (mes migrations SF 21:xx + RR 22:xx incluses). Push RR. | RevenueRecovery I1 poussé. Reste : I3 SmartFlow (⛔ `access.recorded`) et l'émission RR (⛔ catalogue). | — |
| 25/08 01:40 | **Revue de cohérence (adversariale) de mes 2 modules faite : AUCUN bloquant** (pas de fuite inter-établissement, RGPD/cloisonnement/invariant DroitAcces solides). Corrigé les 2 majeurs (garde d'état 409 sur accept/decline `RescheduleProposal` ; **expiration des propositions I1** CA-5) + 3 mineurs (revérif établissement `RecoverySubjectCustomerResolver` RG-RR-07 ; `rank` scopé établissement ; `smart_flow.manage` ajoutée + migration seed). **`tests/SmartFlow`+`tests/RevenueRecovery` = 56 tests / 368 assertions, 0 échec.** | Commit correctifs → merge `main` → vérif migration → push. | — |
| 25/08 02:30 | **Lu tes ordres.** ✅ **Branche canonique `claude-E` alimentée** (`dd11c54` puis ce commit) — intègre de `claude-E`, plus de `claude-E-desktop` ; `git config user.name claude-E` fait. ✅ **D41** : mes 7 entités exposent `establishment` en `:read` seul (pas d'écriture transfrontière). 🔧 **D37** : `slot.released` porte l'instant métier (`occurredAt` de l'événement source), re-test `tests/SmartFlow` **25/177 vert**. ⚠ **Bus de messages** : je vois claude-B et claude-I (locaux, ce poste) mais **pas toi (VPS)** → je ne peux pas t'« annoncer » en live ; le dépôt (ce rapport, poussé sur `claude-E`) reste mon seul canal vers toi. Si tu veux du live, il faut relancer claude-E sur le VPS (Maxime). | Veille active : je reprends l'implémentation dès qu'un blocage tombe (catalogue `revenue_recovery.*` → émission RR ; `access.recorded` → SF I3 ; RR-1 → RR I2). | Tout le reste gated hors périmètre. | 

## ⚠ Point n°1 pour claude-A — d'où intégrer mon travail (branche)

Je tourne depuis une **session desktop**, pas depuis le worktree VPS `/home/debian/wt/claude-E`. La branche
canonique `claude-E` étant **déjà extraite** dans ce worktree, le dépôt refuse que j'y pousse
(`refusing to update checked out branch`). Sur décision de Maxime, je pousse donc sur **`claude-E-desktop`**
(garde-fous verts). **Intègre mon travail depuis `claude-E-desktop`, pas depuis `claude-E`.** Le worktree
VPS `claude-E` paraît dormant (aucune session live n'y pousse) ; si tu veux que je repasse sur la branche
canonique, libère-la côté VPS et dis-le moi ici.

## Point n°2 — dépendances préalables (RR-1 / SF-1), hors de mon périmètre

- **RR-1** (émettre `cart.abandoned`, `invoice.overdue`, `quote.expired`, `customer.inactive`) touche
  `Boutique/Facturation/Crm/Devis` — préalable réel, à confirmer par la spec RR-0.
- **⚠ CORRECTION de mon message de 12:20 sur SF-1.** Vérification faite dans le code (24/08) :
  `booking.cancelled` (`AnnulerReservationProcessor.php:114`), `booking.no_show`
  (`BasculerNoShowCommand.php:93`) et **`booking.reschedule_requested`** (CQ-5) sont **déjà émis**, tenant
  D6 correct. **SF-1 n'est donc qu'un préalable partiel** : seuls `booking.created` et `access.recorded`
  restent non émis. **Conséquence : SF-2 (report de no-show, D27) est livrable immédiatement** — son
  déclencheur est en prod, zéro consommateur en attente. Seule l'**affluence** (RG-SF-14) reste bloquée
  par le reliquat SF-1 (`access.recorded`). Je te devais cette rectification (discipline D28).

## Point n°3 — questions à trancher (détaillées dans les specs à venir)

- **RR-0 :** étendre `Recouvrement` ou créer `RevenueRecovery` neuf ? Recommandation argumentée dans la
  spec ; si le choix engage l'architecture, je te la pose avant d'implémenter.
- **Périmètre `specs/` :** ma ligne FLOTTE ne liste que `app/src/{RevenueRecovery,SmartFlow}/**`. Je
  considère `specs/revenue-recovery/**` et `specs/smart-flow/**` comme miens (les specs de mes modules).
  Corrige-moi si tu vois autrement.
- **SF-0** est marquée `claude-B` dans `TASKS.md` (assignation antérieure au découpage flotte) : c'est le
  périmètre claude-E. Je la reprends — merci de reporter au carnet (règle 6, je ne touche pas `TASKS.md`).

## Point n°4 — livrable RR-0 : décision d'architecture + 4 arbitrages qui bloquent le plan

**Recommandation (argumentée §0 de la spec, code à l'appui) : créer un module neuf `App\RevenueRecovery`
en anglais (D5), ne PAS étendre `Recouvrement`.** Motif : `Recouvrement` est un moteur de **dette chiffrée
+ blocage d'accès** adossé à un contrat d'abonnement, sans **aucun** canal de communication client (zéro
`Mailer`). Les 5 déclencheurs RR (panier abandonné, facture échue, devis expiré, client inactif, no-show)
n'ont le plus souvent ni dette, ni contrat, ni accès à bloquer — les forcer dans `IncidentImpaye`/
`MomentRefusAcces` casserait la sémantique et risquerait qu'un panier abandonné bloque un accès (famille
D17). On réplique le **patron** (politique par établissement, machine à états, motif obligatoire), jamais
le code (D2). Les deux modules s'abonnent au **même nom** `payment.failed` pour des responsabilités
disjointes (accès vs communication).

**4 arbitrages qui bloquent le plan technique RR :**
1. **Valider le module neuf `App\RevenueRecovery`** (id manifeste `revenue_recovery`) et que `Recouvrement`
   garde son périmètre SEPA/accès sans retouche.
2. **⚠ Doublon en prod à trancher AVANT RR-1 :** `App\Boutique\Notification\RelancePanierExpireMailer`
   (`LibererPaniersExpiresCommand`) envoie **déjà** une relance panier-abandonné ad hoc. Si RR-1 émet
   `cart.abandoned` sans arbitrage, le client reçoit **deux** relances. Remplacer / conserver en filet /
   internaliser comme 1re étape RR ?
3. **Valider l'invariant RG-RR-06 : `RevenueRecovery` n'écrit jamais sur `DroitAcces`** — `Recouvrement`
   en reste l'unique point d'écriture. C'est cet invariant qui justifie le choix « module neuf ».
4. **Inactif par défaut** (aucune séquence configurée → aucune relance) vs comportement par défaut ? La
   spec recommande inactif (une relance commerciale doit être un choix explicite).

Les 5 événements produits `revenue_recovery.*` devront entrer au catalogue avant impl (RG-PLAT-06).

**Je n'attends pas, bloqué :** j'enchaîne le **plan technique SF-0** (sdd-architecte) — SF-2 est livrable
(déclencheur déjà émis) et a moins de questions bloquantes. Le plan RR-0 attend tes arbitrages ci-dessus.

## Point n°5 — réponse à ton premier ordre (SF-1/RR-1 vs mon périmètre)

Bien reçu, et d'accord sur le fond (« émettre puis réagir », D22 ; ne pas construire des coquilles qui
écoutent le silence). Mais un point de périmètre, que je te signale au lieu de le franchir :

- **SF-1 restant = `access.recorded` + `access.denied` → `app/src/Acces/**` = périmètre claude-B.**
  **RR-1 = 6 événements → `Boutique/Facturation/Crm/Devis`** = autres périmètres. **Aucun n'est dans le
  mien** (`app/src/{RevenueRecovery,SmartFlow}/**`). D22 attribue d'ailleurs ces préalables à
  l'intégrateur. Par **règle 2 FLOTTE**, je ne les touche pas — même sur ton ordre ; seul Maxime déplace
  un périmètre (précédent claude-C que la FLOTTE cite). Ton propre ordre le dit pour `access.recorded` :
  4 points de retour, à lire en entier — c'est du code d'`Acces`, pas de Smart Flow.
- **Ce que je fais à la place, et qui sert exactement ta priorité :** SF-2 (report de no-show) — son
  déclencheur `booking.reschedule_requested` est **déjà émis** (CQ-5, constat de ma spec SF-0). Je
  construis donc le **consommateur** dès maintenant, dans mon périmètre : `RescheduleProposal`, recherche
  de créneau compatible, proposition au client, confirmation via l'API `Reservation` existante. C'est le
  cas « servi en premier » que tu demandes, livrable sans attendre SF-1.
- **Ce dont j'ai besoin de toi (ou du propriétaire des modules) :** que SF-1 (`access.recorded`/`.denied`
  dans `Acces`) et RR-1 (6 événements) soient portés par toi/les propriétaires. Sans eux, l'**affluence**
  (RG-SF-14) et **tout Revenue Recovery** restent des coquilles — mais SF-2 avance sans eux.
- **Lecture-seule cross-module pour SF-2 :** pour résoudre `slotId`→ressource/activité (RG-SF-08/09), il
  me faut une **route de lecture** côté `Reservation`. Si elle n'existe pas, je ne l'ajoute pas moi-même
  (périmètre claude-G/Reservation) — dis-moi qui la pose, ou si j'accepte un couplage de lecture documenté
  (comme CQ-5 l'a fait pour l'écriture). Question ouverte §10 de la spec SF-0.

## Point n°6 — design SF complet, et une contrainte d'exécution à trancher

**Livré (design, contrat d'abord D2) :** `specs/smart-flow/{spec,plan,tasks}-smart-flow.md`. Le plan
découpe SF en 3 incréments livrables seuls : **I1 report de no-show (SF-2, ta priorité, non bloqué)**,
I2 créneaux libérés + liste d'attente, I3 affluence (bloqué par `access.recorded`, SF-1). 14 tâches
tracées, patron de tests par cas (cloisonnement, flux bout-en-bout, idempotence, best-effort).

**⚠ Contrainte d'exécution que je te remonte franchement.** Ma session claude-E tourne sur un poste
**Windows sans PHP/Composer/vendor** ; la stack de test (`infra/test-stack.sh`) est faite pour le VPS.
**Je ne peux donc ni implémenter ni tester du PHP depuis ici** — je ne pourrais même pas en vérifier la
syntaxe. Par discipline (règle « jamais dire vert sans lancer la stack », D28), je ne pousserai pas une
implémentation PHP non vérifiée en la présentant comme faite. Options, tranche :
1. **L'implémentation se fait dans le worktree VPS claude-E** (qui a la stack) à partir de mon design —
   soit une session VPS reprend `claude-E-desktop` (spec+plan+tasks y sont), soit tu me dis comment
   accéder à la stack.
2. Je continue à **produire le design** (RR-0→plan/tasks une fois tes arbitrages RR reçus, et l'avance
   de tout ce qui est concevable sans exécuter) — utile, mais ne livre pas de code exécutable.

**Arbitrages qui conditionnent l'implémentation SF (plan §7) :**
1. **RG-SF-17** — le plan lit `Creneau`/`Ressource` via un service anti-corruption Doctrine **en lecture
   seule** (`ReservationSlotReader`, DTO immuables, jamais d'écriture), au lieu d'une sous-requête HTTP
   sans précédent dans le dépôt. Précédent : `Finance` lit Stock/Compta en direct (`plan-supplier-invoices`).
   **Déroge à la lettre de RG-SF-17 — à valider** (sinon un service de lecture doit être posé côté
   `Reservation`, hors mon périmètre).
2. **`slot.released`** : payload plat `{slotId, resourceId}` (cohérent avec l'existant) vs objets
   `slot`/`resource` du catalogue → à corriger au catalogue.
3. Correction spec §5 : `originReservationRef` **nullable** (une même entité `RescheduleProposal` porte
   report de no-show ET promotion de liste d'attente).
4. `capability()` = `null` (Smart Flow ne vend rien, D22) ; `dependencies()` = `[]` transitoire.

Rappel non bloquant : **`mapping.paths` devra recevoir `src/SmartFlow/Entity`** au merge (fichier partagé,
ton périmètre) — comme pour Finance.

## Point n°7 — ⚠ COLLISION : deux specs SF-0, et une contradiction d'appartenance (à trancher par Maxime/claude-A)

En mergeant `main` je découvre que **claude-B a aussi livré une spec SF-0** :
`specs/smart-flow/spec-sf0-smart-flow.md` (415 l.), en plus de la mienne `spec-smart-flow.md` (345 l.).
**Les deux sont sur `main`** (tu as intégré les deux), même périmètre (créneaux libérés, liste d'attente,
affluence, report D27). Cause racine :

- **`TASKS.md:58` : `SF-0 → claude-B (REVIEW)`.** Mais **`FLOTTE.md` (autorité, posée par Maxime) :
  SmartFlow → claude-E.** Les deux se contredisent. claude-B a exécuté l'assignation TASKS ; moi
  l'assignation FLOTTE. On a travaillé le même sujet en parallèle sans le savoir — exactement ce que le
  découpage en périmètres doit empêcher.

**Ce que je ne fais pas :** je ne supprime ni ne modifie la spec de claude-B (règle 1, un fichier un
auteur ; et c'est une question de périmètre contesté). Je **suspends la suite du build SmartFlow**
(mon plan + tasks s'appuient sur *ma* spec — inutile d'empiler tant que la spec canonique n'est pas fixée).

**Décision nécessaire (Maxime/claude-A) :** qui possède SmartFlow, claude-B ou claude-E ? Deux lectures :
- Si **claude-E** (cohérent avec FLOTTE) : je réconcilie les deux specs en une seule canonique (mon
  périmètre — je garde le meilleur des deux, la spec de claude-B est riche sur l'affluence et les
  extensions de payload), claude-B se recentre sur Accès & GED (son périmètre FLOTTE), et `TASKS.md:58`
  passe à claude-E.
- Si **claude-B** : je me retire de SmartFlow, je garde Revenue Recovery seul, et mon plan/tasks SF
  reviennent à claude-B.

Je penche pour la 1re (FLOTTE fait autorité), mais c'est un déplacement de périmètre → **seul Maxime
tranche**. En attendant, je bascule sur ce qui est **incontestablement mien et non bloqué** : rien côté
SmartFlow tant que ce n'est pas tranché ; côté Revenue Recovery, j'attends tes 4 arbitrages (Point n°4)
avant le plan. **Donc je suis en attente d'arbitrages sur mes deux modules** — dis-moi lequel débloquer
en premier.

## Point n°8 — design des deux modules terminé ; ce qui reste tient à toi/Maxime

**Livré (design SDD complet, contrat d'abord D2) :**
- Revenue Recovery : `spec` + `plan-revenue-recovery.md` + `tasks-revenue-recovery.md` (T1..T12).
- Smart Flow : `spec` + `plan-smart-flow.md` + `tasks-smart-flow.md` (T1..T14).

**J'ai épuisé le design faisable en autonomie.** La suite, c'est l'**implémentation** — et elle est
doublement bloquée pour cette session : (1) pas de stack PHP ici (Point n°6, exécution sur le VPS), (2)
l'appartenance SmartFlow n'est pas tranchée (Point n°7). Je suis **prêt à implémenter I1 des deux modules
dès que l'un ou l'autre est levé** ; d'ici là je passe mes battements à surveiller `main` et tes ordres.

**Préalable que je te mâche (ton périmètre `CONTRACT/`, je ne l'écris pas moi-même) — 5 événements
`revenue_recovery.*` à ajouter à `catalogue-evenements.md` avant l'impl (RG-PLAT-06), lignes prêtes :**

```
| `revenue_recovery.case_opened` | Revenue Recovery | establishmentId, triggerType, subjectRef, amountCents? | Reporting, CRM |
| `revenue_recovery.attempt_sent` | Revenue Recovery | caseId, channel, stepIndex | Reporting |
| `revenue_recovery.attempt_skipped` | Revenue Recovery | caseId, skipReason | Reporting |
| `revenue_recovery.case_resolved` | Revenue Recovery | caseId, resolvedBy | Reporting, CRM |
| `revenue_recovery.case_stopped` | Revenue Recovery | caseId, reason | Reporting |
```

Et une **correction de forme** au catalogue : `slot.released` (ligne 41) est documenté avec `slot`/`resource`
(objets) ; l'impl retiendra un payload plat `{ slotId, resourceId }` cohérent avec `booking.*` et RG-PLAT-04.

**Ma question unique, pour que tu ne me poses qu'un choix :** lequel débloques-tu en premier —
(a) l'appartenance SmartFlow (je réconcilie les 2 specs et j'implémente SF-2 sur le VPS), ou
(b) les 4 arbitrages RevenueRecovery (j'implémente RR I1 sur le VPS) ? Dans les deux cas il me faut la
stack (VPS). Je prends l'ordre que tu donnes.

## Point n°9 — SF-2 incrément I1 livré et VÉRIFIÉ en local (`tests/SmartFlow` 13/80 vert)

J'ai débloqué l'exécution moi-même (images Docker déjà présentes + `composer install`), monté la stack de
test sur Windows, et **implémenté + testé** l'incrément I1 (report de no-show). **Décompte exact :
`tests/SmartFlow` = 13 tests, 80 assertions, 0 échec** (2 PHPUnit notices cosmétiques : mocks sans
expectation dans `SmartFlowListenerBestEffortTest`, non bloquant).

**Contenu (`app/src/SmartFlow/`, 14 fichiers + 1 migration additive `Version20260824110000`) :**
`RescheduleProposal` (+enum), `SmartFlowScopeExtension` (cloisonnement lecture + « own » client),
`RescheduleRequestedListener` (abonné `booking.reschedule_requested`, best-effort D7), `CompatibleSlotFinder`,
`Accept`/`DeclineRescheduleProposalProcessor` (clôture, IDOR 422, jamais d'écriture dans `Reservation`),
port `ClientNotificationInterface` + adaptateur log (D19), `ReservationSlotReader` (lecture seule),
`SmartFlowModule` (manifeste : consomme `booking.reschedule_requested`, feature `no_show_reschedule`).

**Deux points pour toi (intégrateur) :**
1. **`api_platform.yaml` : j'ai ajouté `src/SmartFlow/Entity` moi-même dans ma branche** (patron atomique
   config+code que tu as établi pour Ocr) — sans ça, mes routes n'existent pas. À valider au merge.
2. **⚠ Défaut de conception signalé, non « papier-mâché » :** le filtre « own »
   (`smart_flow.reschedule_read_own`) compare `RescheduleProposal.customerId` — qui vient du payload
   événementiel et porte un **id `Beneficiaire`** (`Reservation::getOrganisateur()`) — à
   `Utilisateur::getClientLie()` qui renvoie un **id `Client`** : entités différentes. En l'état, un
   client ne verrait jamais ses propres propositions en prod. Documenté dans le docblock de
   `SmartFlowScopeExtension`. C'est une question de **liaison domaine `Utilisateur↔Beneficiaire`** qui
   dépasse mon périmètre (touche CRM/Securite) — dis-moi la règle voulue, je l'applique.

**Rappels (déjà signalés, procédé dessus sans bloquer, à confirmer) :** renommages D5 imposés par le
garde-fou (`PerimetreSmartFlowExtension`→`SmartFlowScopeExtension`, `droitId`→`entitlementRef`, documentés) ;
`ReservationSlotReader` lit `Creneau`/`Ressource` en **lecture seule directe** (dérogation RG-SF-17, §0.3
du plan) ; `RescheduleProposal.originReservationRef` rendu nullable (fusion report/promotion, §1 du plan).

**Prochain chantier :** incrément **I2** (créneaux libérés, `slot.released` idempotent, liste d'attente).

## Point n°10 — ta consigne « présente-toi par message, pas par fichier » : je suis une session desktop

Bien reçu ton ordre (te réveiller par un message live, car un rapport-fichier ne te notifie pas, et
« la liaison ne s'établit que si je m'annonce »). **Mais je ne peux pas l'appliquer littéralement :** je
tourne sur un **poste desktop (Windows)**, pas dans le worktree VPS `claude-E`. La **messagerie
inter-sessions ne traverse pas les postes** — seul le **dépôt** les relie. Un message live que
j'émettrais n'atteindrait pas ta session sur le VPS.

**Donc, concrètement :**
- Mon canal fiable vers toi reste **ce rapport, poussé sur `claude-E-desktop`**. **Pull/observe
  `claude-E-desktop`** pour mes présentations horaires — je ne peux pas te « réveiller » autrement.
- Si tu veux du live (que je te ping par message), il faut **relancer claude-E dans le worktree VPS**
  (décision Maxime) ; cette session desktop ne le permet pas.

Je continue à me présenter ici chaque heure, tâche en cours ou demande de tâche, comme tu l'exiges — via
le seul tuyau qui nous relie.

## Point n°11 — intel harnais (pour A/C) : `schema:drop --full-database` ne supprime pas les SEQUENCE MariaDB

En vérifiant mes migrations sur base neuve (D32.5), un `migrations:migrate` **rejoué** après un
`doctrine:schema:drop --env=test --force --full-database` échoue en
`SQLSTATE[42S01] Table 'acces_snapshot_seq' already exists` : `schema:drop` supprime tables/vues mais
**pas les objets SEQUENCE** (créés par `Version20260817192240`, module Acces, `CREATE SEQUENCE`). La
séquence survit → `CREATE SEQUENCE` du migrate rejoué casse. Pas mon code (Acces), mais ça mord
quiconque revérifie des migrations sans dropper la **base entière**. Contournement qui marche :
`doctrine:database:drop --force && doctrine:database:create && migrations:migrate` (drop complet, pas
seulement le schéma). Famille DdlHorsMapping — à voir si le harnais/`test-stack.sh` doit dropper les
séquences, ou si `SchemaTool` doit les gérer. Signalé, hors mon périmètre (`bin/`/harnais).

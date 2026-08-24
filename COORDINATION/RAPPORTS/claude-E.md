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

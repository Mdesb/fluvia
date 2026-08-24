# Spec — CQ-7 : validité de la carte après recharge, configurable (`RG-CQ7-xx`)

- **Lot / module :** L1 · Offre (`App\Offre`, config produit) — lu par L3 · Accès (`App\Acces`, calcul)
- **Stories couvertes :** aucune `US-Lx-nn` dédiée au backlog `backlog.html` (recherché, aucune
  occurrence `CQ-7`/`CQ7`) ; tâche du backlog de coordination A, complément direct de CQ-1
  (`spec-cq1-recharge-carte.md`), annoncée dès son §0 : « CQ-7 — le paramétrage "conserver la validité
  d'origine" vs "prolonger" est une option du produit-carte, pas un choix fait ici. »
- **Règles de gestion :** RG-M1-04/13 (carte multi-entrées, stock initial — non modifiées), RG-CQ1-04
  (calcul d'échéance à la recharge, étendu ici sans réécriture), + `RG-CQ7-01` à `RG-CQ7-06` (nouvelles,
  ce lot)
- **Décisions actées, non re-tranchées :** D26 (2026-08-23 — « une recharge prolonge la validité, et
  c'est configurable » ; défaut livré = prolongation ; *« le comportement reste une option du
  produit-carte »*, `COORDINATION/DECISIONS.md:589-603`), D5 (anglais pour tout identifiant technique
  **neuf** — enum, colonne), D3/D8 (cloisonnement à périmètre serveur — sans objet ici, aucune donnée
  résolue depuis un identifiant client), D2 (contract-first, pas d'appel direct module→module — sans
  objet, lecture en sens unique déjà en place)
- **Statut :** brouillon

## 0. Ce que ce lot n'est pas (lire avant tout)

- **CQ-1** (déjà livré) — a construit tout le mécanisme de recharge (déclenchement, incrément atomique,
  cloisonnement, refus explicites, `access.card_recharged`) et **le défaut** de calcul d'échéance
  (`RG-CQ1-04`, prolongation, une période complète depuis la recharge). CQ-7 n'y touche pas : il ajoute
  **une option** lue par ce même calcul, exactement comme son propre docblock l'annonçait déjà
  (`app/src/Acces/Service/CardExpiryCalculator.php:17-23`, commentaire de la méthode `calculer()` :
  *« il n'existe que comme point d'extension CQ-7... un futur appelant pourrait court-circuiter cette
  méthode... pour retourner `$fenetreFinActuelle` telle quelle »*).
- **CQ-2/CQ-4** (modale caisse, canal `propositionRecharge`) — hors périmètre, aucun écran caisse n'est
  construit ni modifié ici. CQ-7 est un paramétrage **produit** (fiche produit-carte côté back-office
  Offre), pas une décision prise au comptoir.
- **CQ-3** (carte de N réservations, `TypeDroitAcces::Booking`) — `creditRestant` y est aujourd'hui figé
  à `null` (`ProjectionAccesReservationHandler`) ; ce lot ne concerne que `TypeDroitAcces::CarteQuota`.
- **Le porte-monnaie virtuel (PMV, `App\Crm\Service\PmvRechargeHandler`, RG-M4-04)** — objet différent
  (`crm.html`/cahier §M4), avec sa propre question ouverte non tranchée dans le cahier détaillé
  (*« Recharge d'un PMV expiré — réactive-t-elle le solde antérieur figé ou repart-elle à zéro... À
  paramétrer par établissement »*, `cahier-detaille.html:793`). CQ-7 ne tranche **pas** cette question
  pour le PMV ; il tranche l'équivalent pour la carte multi-entrées (§5, `RG-CQ7-04`), en s'en inspirant
  comme précédent (le cahier avait déjà anticipé qu'une recharge sur un objet expiré est une question
  distincte, paramétrable, pas un cas à laisser indéfini).
- **Un « produit de recharge » dédié ou une reformulation de l'événement `access.card_recharged`** — non
  requis par ce lot (§7).

## 1. Objectif

Permettre à l'exploitant de choisir, **par produit-carte**, ce que devient l'échéance de validité
(`DroitAcces.fenetreFin`) quand cette carte est rechargée : la **prolonger** d'une période complète
(comportement CQ-1, défaut D26) ou la **conserver** telle quelle. Aujourd'hui, ce choix n'existe pas —
`CardExpiryCalculator` applique inconditionnellement la prolongation à toute carte portant
`validiteDuree` et/ou `dateButoir`.

## 2. Périmètre

- **Inclus :**
  - Un enum `RechargeValidityMode` (anglais, D5) à deux valeurs : `Extend` (prolonger, défaut) et `Keep`
    (conserver) — `RG-CQ7-01`.
  - Un champ de configuration sur `CarteMultiEntrees` portant ce mode, lu/écrit via l'API produit
    existante (mêmes groupes de sérialisation que `validiteDuree`/`dateButoir`) — `RG-CQ7-01`.
  - La branche de calcul correspondante dans `CardExpiryCalculator::calculer()` — `RG-CQ7-02`/`RG-CQ7-03`.
  - Le comportement du mode `Keep` sur une carte dont l'échéance est déjà dépassée au moment de la
    recharge — `RG-CQ7-04` (le point délicat signalé par l'énoncé de la tâche).
  - La confirmation que `dateButoir` n'intervient pas en mode `Keep` — `RG-CQ7-05`.
  - Une migration de schéma additive (`ADD COLUMN ... NOT NULL DEFAULT 'extend'`) — §9.
  - La non-régression explicite de `CardRechargeTest::testCa3.../testCa4...` (mode par défaut inchangé).
- **Exclu (pour l'instant) :**
  - Un troisième mode (« ancienne échéance + période », explicitement écarté du défaut `Extend` par
    D26) — voir §5 note sous `RG-CQ7-02` : pas de besoin produit identifié, non construit.
  - Une valeur par défaut globale/établissement (configurable hors produit) — le défaut est un
    **littéral de code** (`RechargeValidityMode::Extend`), pas une donnée paramétrable par établissement
    (§4 « Où vit la config », décision).
  - Tout écran back-office pour éditer ce champ (formulaire fiche produit-carte) — l'API le porte, l'UI
    est un lot séparé (hors backlog A actuel, non spécifié ici).
  - Toute modification du même paramétrage pour le PMV (`App\Crm`) — objet distinct, cf. §0.
  - Un historique/audit des changements de mode (qui a basculé une carte de `Extend` à `Keep`, quand) —
    non requis par les CA de ce lot.

## 3. Contexte existant (code réel, vérifié)

- `App\Offre\Entity\CarteMultiEntrees` (`app/src/Offre/Entity/CarteMultiEntrees.php`) porte déjà
  `validiteDuree` (`?\DateInterval`, ligne 41) et `dateButoir` (`?\DateTimeImmutable`, ligne 45), tous
  deux `nullable`, groupes `produit:read/write`, `carte:read/write` (lignes 26-45). C'est la fiche
  produit-carte, propriété de `App\Offre` — le lieu naturel du nouveau champ (§4).
- `App\Acces\Service\CardExpiryCalculator::calculer()` (`app/src/Acces/Service/CardExpiryCalculator.php:24-42`)
  est un service pur (aucune dépendance Doctrine), déjà appelé par deux points d'entrée :
  1. `App\Acces\Projection\StubProjectionDroit::projeter()` (`app/src/Acces/Projection/StubProjectionDroit.php:61-64`)
     — **émission initiale**, uniquement à la **première** projection (`$estNouveau`), avec
     `$fenetreFinActuelle = null` systématiquement (aucune échéance ne préexiste à ce moment).
  2. `App\Acces\Service\CardRechargeHandler::recharge()` (`app/src/Acces/Service/CardRechargeHandler.php:122-127`)
     — **recharge**, avec `$fenetreFinActuelle = $droit->getFenetreFin()` (l'échéance courante, qui peut
     être `null`, future, ou déjà passée).
  La signature (`calculer(CarteMultiEntrees $carte, ?\DateTimeImmutable $fenetreFinActuelle,
  \DateTimeImmutable $maintenant): ?\DateTimeImmutable`) distingue déjà les deux appels par la présence
  ou non de `$fenetreFinActuelle` — c'est le point d'appui exploité par `RG-CQ7-03` (§5) : un seul
  service, une seule méthode, un seul branchement, valable pour les deux appelants sans qu'aucun des deux
  n'ait besoin d'être modifié.
- Le calcul actuel (`extend`, lignes 29-41) : si `validiteDuree` est nulle et `dateButoir` nulle →
  `null` (illimitée) ; si `validiteDuree` nulle mais `dateButoir` renseignée → `dateButoir` (plafond
  fixe) ; sinon → `min(maintenant + validiteDuree, dateButoir ?? maintenant + validiteDuree)`. **Cette
  branche reste inchangée** ; `RG-CQ7-03` s'insère **avant** elle, jamais dedans.
- `App\Acces\Service\CardRechargeHandler::recharge()` (lignes 122-127) résout `$carte` depuis le produit
  **vendu pour cette recharge** (`carteVendue()`, lignes 195-204) et appelle `calculer()` sans aucune
  connaissance du mode — le point d'insertion de ce lot est donc entièrement contenu dans
  `CardExpiryCalculator`, sans toucher `CardRechargeHandler` (confirmé §5, `RG-CQ7-03`, dernier
  paragraphe).
- `App\Tests\Acces\Api\CardRechargeTest` (`app/tests/Acces/Api/CardRechargeTest.php`) — `testCa3...`
  (lignes 114-136) et `testCa4...` (lignes 138-156) fixent le comportement `extend` par défaut via
  `parametrerCarteDemo()` (lignes 705-712), qui ne fixe aujourd'hui que `validiteDuree`/`dateButoir`. Ces
  deux tests **doivent rester verts sans modification** après ce lot (le mode par défaut d'une carte non
  reconfigurée est `extend`) — `RG-CQ7-02`.
- Frontière de module (D3/D8, cloisonnement) : `CardExpiryCalculator` importe déjà
  `App\Offre\Entity\CarteMultiEntrees` (ligne 7) et lit `validiteDuree`/`dateButoir` sans qu'aucun garde-
  fou de cloisonnement ne s'applique — ce sont des **données de configuration produit**, pas des données
  résolues depuis un identifiant fourni par un client HTTP (D3/D8 protègent contre l'IDOR sur des
  entités **appartenant à un tenant**, pas contre la lecture d'un paramètre de catalogue). Lire un
  champ supplémentaire (`rechargeValidityMode`) sur le même objet, de la même manière, est **le même
  précédent, à l'identique** — confirmé, aucune violation.

## 4. Acteurs & droits

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Gestionnaire d'offre (back-office M1) | Lire/écrire `CarteMultiEntrees.rechargeValidityMode` sur la fiche produit-carte | `offre.produit.write` / `offre.produit.read` (existantes, inchangées — mêmes permissions que `validiteDuree`/`dateButoir` aujourd'hui, mêmes groupes de sérialisation) |
| Agent de caisse | Recharge une carte (CQ-1, inchangé) ; **ne choisit jamais le mode** — il est déjà figé sur le produit | `vente.encaisser` (existante, inchangée) |
| Système (`CardExpiryCalculator`) | Lit le mode pour calculer `fenetreFin` | aucune (service interne, pas de surface API) |

**Ce lot ne crée aucune nouvelle permission ni aucun nouvel endpoint.** Le champ est exposé par
l'API produit **existante** (`/api/produits/{id}` et sa ressource imbriquée `carte`), au même titre que
`validiteDuree`/`dateButoir` — mêmes groupes `produit:read`/`produit:write`/`carte:read`/`carte:write`.

## 5. Comportements & règles

### Configuration

- **RG-CQ7-01 — Le mode de renouvellement est une propriété du produit-carte, à deux valeurs.**
  `App\Offre\Enum\RechargeValidityMode` (nouveau, anglais D5) :
  - `Extend = 'extend'` — prolonge (comportement CQ-1/D26, défaut).
  - `Keep = 'keep'` — conserve l'échéance existante, ne la recalcule jamais à la recharge.
  Porté par un champ **non nullable** sur `CarteMultiEntrees`, `rechargeValidityMode`, valeur par défaut
  `Extend` (au niveau PHP **et** en colonne SQL, même patron que `DroitAcces::$statutProjection` /
  `StatutProjectionDroit::Valide`, `app/src/Acces/Entity/DroitAcces.php:82-84`). Une carte existante en
  base, jamais reconfigurée, se comporte donc **exactement** comme avant ce lot (non-régression).
  ⚠ HYPOTHÈSE non bloquante, à confirmer en plan : nom de propriété proposé entièrement anglais
  (`rechargeValidityMode`), alors que les champs voisins de la même entité sont français
  (`validiteDuree`, `dateButoir`). Précédent direct pour ce dilemme : `spec-cq5-noshow-credit.md` §10
  point 2, qui a tranché en faveur de la cohérence locale française (`issueCreditNoShow`) sur un autre
  lot, **à confirmer/ajuster en plan si la revue de cohérence l'exige** — ici la recommandation penche
  pour le nom anglais complet car le concept est entièrement nouveau (aucun terme français existant à
  prolonger, contrairement à `RegleAnnulation`), mais ce n'est pas structurant : le renommer ne change
  ni le schéma logique ni les CA.

- **RG-CQ7-02 — `Extend` reste le défaut, comportement CQ-1 strictement inchangé.** Quand
  `rechargeValidityMode = Extend`, `CardExpiryCalculator::calculer()` applique **exactement** la
  formule actuelle (§3) : `min(maintenant + validiteDuree, dateButoir)` selon les cas, indépendamment de
  `$fenetreFinActuelle`. Non-régression exigée : `CardRechargeTest::testCa3.../testCa4...` passent sans
  modification.
  **Troisième mode envisagé puis écarté** (« ancienne échéance + période », ex.
  `extend_from_current`) : pas de besoin produit identifié — D26 a explicitement écarté cette lecture
  pour le défaut, et aucun exploitant n'a demandé un troisième comportement distinct de `Extend`/`Keep`.
  L'enum reste ouvert (un `case` de plus est additif, sans migration de données existantes) si le besoin
  apparaît ; **non construit dans ce lot**.

- **RG-CQ7-03 — `Keep` ne touche `fenetreFin` qu'à la recharge, jamais à l'émission initiale.**
  Quand `rechargeValidityMode = Keep` :
  - **Recharge** (`$fenetreFinActuelle` non nul, ou explicitement déjà porteur d'une valeur y compris
    `null` fixée par une émission sans `validiteDuree`) : `calculer()` retourne `$fenetreFinActuelle`
    **sans le recalculer** — aucune lecture de `validiteDuree`/`dateButoir` dans cette branche.
  - **Émission initiale** (`StubProjectionDroit::projeter()`, `$fenetreFinActuelle = null` par
    construction, §3) : le mode `Keep` ne s'applique **pas** — une carte neuve doit recevoir une
    première échéance calculée normalement (comme en `Extend`), sinon une carte configurée `Keep` avec
    `validiteDuree` renseignée n'expirerait **jamais**, ce qui n'est pas l'intention de « conserver la
    validité d'origine » (il faut d'abord qu'une validité d'origine existe pour qu'il y ait quelque
    chose à conserver).
  **Implémentation minimale, un seul point d'insertion** : la distinction entre les deux appelants
  n'exige **aucune** modification de `StubProjectionDroit` ni de `CardRechargeHandler` — elle se déduit
  déjà de la valeur de `$fenetreFinActuelle` (`null` à l'émission par construction, jamais `null`-par-
  construction à la recharge d'une carte qui a déjà été projetée). La règle s'écrit donc comme **une
  seule condition ajoutée en tête de `CardExpiryCalculator::calculer()`** :
  « si `Keep` et `$fenetreFinActuelle !== null`, retourner `$fenetreFinActuelle` immédiatement » — exactement
  le point d'extension déjà annoncé dans le docblock de la méthode (§3).
  ⚠ HYPOTHÈSE à vérifier en plan : cette règle traite implicitement le cas rare d'une carte `Keep` dont
  `StubProjectionDroit` aurait, par un futur changement, calculé `fenetreFin = null` dès l'émission
  (carte sans `validiteDuree`/`dateButoir`, §3 branche « illimitée ») — une recharge ultérieure verrait
  alors `$fenetreFinActuelle = null` et **retomberait dans la branche normale** (recalcul), pas dans la
  branche `Keep`. Comportement jugé correct (rien à « conserver » sur une carte illimitée, le résultat
  `null` est identique dans les deux branches), signalé pour qu'il ne surprenne personne en test.

- **RG-CQ7-04 — Recharge d'une carte déjà expirée en mode `Keep` : succès, échéance non réactivée
  (risque assumé, ⚠ arbitrage A).** Quand `rechargeValidityMode = Keep` et que `fenetreFin` **actuelle**
  est déjà dans le passé au moment de la recharge, la recharge **réussit** : le crédit est ajouté
  (`RG-CQ1-02/08`, inchangé), mais `fenetreFin` **reste dans le passé** — `Keep` signifie littéralement
  « ne jamais toucher `fenetreFin` à la recharge », sans exception liée à son échéance. La carte rechargée
  reste donc **inutilisable au passage** (le moteur de validation compare `fenetreFin` à `maintenant`,
  indépendamment de ce lot) jusqu'à ce que son échéance soit relevée par un autre moyen (bascule
  ponctuelle en `Extend`, ou nouvelle carte).
  **Justification de ce choix plutôt qu'un refus explicite** : `Keep` est un choix **explicite et global**
  de l'exploitant sur ce produit — celui qui le sélectionne a déjà accepté que la recharge ne gère jamais
  l'échéance, y compris ce cas. Ajouter une exception « sauf si déjà expirée » réintroduirait une
  branche de recalcul (donc un second comportement caché dans `Keep`), ce que `RG-CQ7-03` évite
  précisément. Un exploitant qui veut la réactivation automatique d'une carte expirée à la recharge
  dispose déjà du mode adapté : `Extend`.
  **Option écartée : refus explicite (409) si `fenetreFin < maintenant`.** Rejetée dans ce lot — elle
  ajouterait une branche métier dans `CardRechargeHandler` (contrairement à `RG-CQ7-03`, contenue dans
  `CardExpiryCalculator` seul) pour un cas que l'exploitant a lui-même configuré ; elle romprait aussi la
  garantie « `Keep` ne bloque jamais une vente, il ignore juste l'échéance » — une caissière qui encaisse
  une recharge ne doit pas être bloquée par un état de configuration produit qu'elle ne maîtrise pas.
  **⚠ Arbitrage A demandé avant implémentation** : ce point est explicitement signalé comme le plus
  susceptible d'être tranché autrement (ex. refus, ou réactivation automatique) — la recommandation
  ci-dessus (succès silencieux, risque documenté) est un choix par défaut motivé par la simplicité et la
  cohérence avec le risque déjà assumé de D26 (grignotage en mode `Extend`), pas une certitude produit.
  Précédent analogue non tranché à ce jour : la même question existe pour le PMV
  (`RG-M4-04`, `cahier-detaille.html:769-793`, « à paramétrer par établissement ») — ce lot ne prétend
  pas la trancher pour le PMV, seulement pour la carte multi-entrées.

- **RG-CQ7-05 — `dateButoir` n'est pas consulté en mode `Keep`.** Conséquence directe de `RG-CQ7-03` :
  la branche `Keep` retourne avant toute lecture de `validiteDuree`/`dateButoir`. `dateButoir` reste un
  plafond qui n'a de sens que **dans** le calcul de prolongation (`Extend`) ; en `Keep`, rien n'est
  recalculé, donc rien n'est plafonné. Une carte `Keep` dont `dateButoir` est dépassée se comporte
  identiquement à une carte `Keep` sans `dateButoir` : aucune différence observable, `dateButoir` devient
  un champ inerte pour cette carte tant qu'elle reste en mode `Keep`.

- **RG-CQ7-06 — Frontière de module confirmée, aucun changement de direction de dépendance.**
  `App\Acces` continue de lire `App\Offre\Entity\CarteMultiEntrees` en lecture seule, comme pour
  `validiteDuree`/`dateButoir` depuis CQ-1 (§3, dernier point) — `App\Offre` ne connaît toujours aucune
  entité d'`App\Acces`. Aucun nouveau port n'est nécessaire (contrairement à `RG-CQ1-09`, qui concernait
  l'écriture de `DroitAcces`/`Support`/`Appairage` depuis `App\Vente` — ici il s'agit d'une lecture
  supplémentaire sur un objet déjà lu par un service qui vit **dans** `App\Acces`).

## 6. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| `App\Offre\Enum\RechargeValidityMode` (**nouveau**) | — | `enum: string` | `Extend = 'extend'`, `Keep = 'keep'` | anglais (D5), `RG-CQ7-01` |
| `CarteMultiEntrees` (existant, `App\Offre\Entity`) | `rechargeValidityMode` (**nouveau**) | `RechargeValidityMode` | non nullable, défaut `Extend` (PHP + colonne SQL) | groupes `produit:read/write`, `carte:read/write` — mêmes que `validiteDuree`/`dateButoir` |
| | `validiteDuree`, `dateButoir` (existants) | `?\DateInterval`, `?\DateTimeImmutable` | inchangés | non consultés en mode `Keep` (`RG-CQ7-05`) |
| `App\Acces\Service\CardExpiryCalculator` (existant) | `calculer()` | méthode | **une condition ajoutée en tête** (`RG-CQ7-03`) | seul point de code modifié en dehors de l'entité/enum |
| `App\Acces\Service\CardRechargeHandler` (existant) | — | — | **inchangé** | continue de résoudre `$carte` et d'appeler `calculer()` sans connaître le mode |
| `App\Acces\Projection\StubProjectionDroit` (existant) | — | — | **inchangé** | le mode `Keep` ne s'applique pas à l'émission initiale (`$fenetreFinActuelle = null`) |
| Table `off_carte_multi_entrees` (existante) | `recharge_validity_mode` (**nouvelle colonne**) | `VARCHAR(16) NOT NULL DEFAULT 'extend'` | migration additive | §9 |

Aucun nouvel événement de domaine n'est requis par les critères d'acceptation de ce lot (§7). Enrichir
`access.card_recharged` d'un champ `validityMode` (payload) est une extension possible mais non
nécessaire — ⚠ HYPOTHÈSE non bloquante, laissée en dépendance ouverte (§10) plutôt qu'ajoutée ici sans
consommateur identifié (même prudence que D22, déjà appliquée par CQ-1 §7).

## 7. Critères d'acceptation

- **CA-1 (RG-CQ7-01/02, non-régression du défaut)** — *Étant donné* une carte multi-entrées dont
  `rechargeValidityMode` n'a jamais été fixé explicitement (valeur par défaut après migration), *quand*
  elle est rechargée, *alors* le comportement est **strictement identique** à CQ-1 : `fenetreFin` devient
  `maintenant + validiteDuree`, plafonnée par `dateButoir` le cas échéant — `CardRechargeTest::
  testCa3EcheanceRepartPourUnePeriodeCompleteDepuisMaintenant` et `testCa4EcheancePlafonneeParDateButoir`
  passent sans aucune modification de leur code.
- **CA-2 (RG-CQ7-01, migration additive)** — *Étant donné* la base de données avant ce lot, *quand* la
  migration est jouée, *alors* chaque ligne existante de `off_carte_multi_entrees` porte
  `recharge_validity_mode = 'extend'` sans action manuelle, et aucune vente/recharge en cours n'échoue.
- **CA-3 (RG-CQ7-03, mode `Keep`, cas nominal)** — *Étant donné* une carte configurée
  `rechargeValidityMode = Keep`, `validiteDuree = P1Y`, dont `DroitAcces.fenetreFin` actuelle est fixée
  à `J + 3 jours` (échéance future), *quand* une recharge est validée, *alors* `fenetreFin` reste
  exactement `J + 3 jours` après l'opération (inchangée au jour près), tandis que `creditRestant`
  augmente normalement (comportement CQ-1 inchangé sur le crédit).
- **CA-4 (RG-CQ7-05, `dateButoir` inerte en `Keep`)** — *Étant donné* une carte `Keep` avec
  `dateButoir` fixée dans le passé, *quand* elle est rechargée, *alors* la recharge réussit et
  `fenetreFin` n'est ni recalculée ni plafonnée par ce `dateButoir` — elle reste à sa valeur d'avant
  recharge, à l'identique du cas CA-3.
- **CA-5 (RG-CQ7-04, carte `Keep` déjà expirée)** — *Étant donné* une carte `Keep` dont `fenetreFin`
  actuelle est déjà dans le passé (hier), *quand* une recharge est validée, *alors* l'opération
  **réussit** (crédit ajouté, vente scellée NF525 comme toute recharge CQ-1), et `fenetreFin` reste
  inchangée — donc toujours dans le passé après l'opération. *(Ce CA matérialise la recommandation
  `RG-CQ7-04` ; si l'arbitrage A retient le refus explicite à la place, ce CA est remplacé par un CA
  symétrique : refus 409, crédit inchangé, message explicite invitant à basculer en `Extend`.)*
- **CA-6 (RG-CQ7-03, `Keep` sans effet sur l'émission initiale)** — *Étant donné* un produit-carte
  configuré `rechargeValidityMode = Keep` avec `validiteDuree = P1Y`, *quand* une carte de ce produit
  est **émise pour la première fois** (première vente, jamais rechargée), *alors* `DroitAcces.fenetreFin`
  est calculée normalement (`maintenant + P1Y`, comme en `Extend`) — le mode `Keep` ne prive pas une
  carte neuve de sa première échéance.
- **CA-7 (RG-CQ7-01, exposition API)** — *Étant donné* un gestionnaire d'offre habilité, *quand* il lit
  ou modifie la fiche d'un produit-carte via l'API existante, *alors* `carte.rechargeValidityMode`
  apparaît en lecture (`extend` ou `keep`) et peut être modifié en écriture, avec les mêmes règles de
  droits que `validiteDuree`/`dateButoir` aujourd'hui.
- **CA-8 (RG-CQ7-02, carte illimitée)** — *Étant donné* une carte sans `validiteDuree` ni `dateButoir`,
  quel que soit `rechargeValidityMode` (`Extend` ou `Keep`), *quand* elle est rechargée, *alors*
  `fenetreFin` reste `null` (carte illimitée, comportement inchangé dans les deux modes).

## 8. Cas limites

- **Carte `Keep` jamais rechargée, `validiteDuree` modifiée après l'émission.** Le mode `Keep` ne
  s'applique qu'à la recharge (`RG-CQ7-03`) ; modifier `validiteDuree` sur la fiche produit après
  l'émission d'une carte n'a aucun effet rétroactif sur les cartes déjà émises (comportement déjà vrai
  aujourd'hui pour `Extend`, non spécifique à ce lot).
- **Bascule d'une carte de `Extend` à `Keep` (ou l'inverse) entre deux recharges du même produit.** Le
  mode est lu **au moment de chaque recharge**, depuis la `CarteMultiEntrees` du produit **vendu pour
  cette recharge** (`CardRechargeHandler::carteVendue()`, inchangé) — un changement de configuration
  produit s'applique donc à la **prochaine** recharge, jamais rétroactivement à une recharge déjà
  validée. ⚠ HYPOTHÈSE non bloquante : si l'exploitant vend un second produit-carte différent (SKU de
  recharge distinct, cf. `spec-cq1-recharge-carte.md` §5 `RG-CQ1-01`) portant un mode différent du
  produit d'émission d'origine, c'est **ce second mode** qui s'applique — cohérent avec `RG-CQ1-04`
  existant (« calculée depuis la `CarteMultiEntrees` du produit **vendu** pour cette recharge »), non
  modifié par ce lot.
- **Recharge concurrente de deux ventes sur une carte `Keep`.** Aucun changement au patron
  d'atomicité `RG-CQ1-08` (`UPDATE` SQL conditionnel) : en `Keep`, la colonne `fenetre_fin` est
  simplement écrite avec la **même valeur qu'avant** (mirage cohérent), donc aucun risque de perte
  d'incrément supplémentaire par rapport à `Extend`.
- **`fenetreDebut`.** Non concerné par ce lot, comme pour `RG-CQ1-04` (`fenetreDebut` reste `null`
  aujourd'hui pour un droit `CarteQuota`, `spec-cq1-recharge-carte.md` §5 `RG-CQ1-04`).

## 9. Migration

Additive, aucune donnée existante perdue, aucun risque de verrouillage long (une seule colonne, valeur
par défaut constante) :

```sql
ALTER TABLE off_carte_multi_entrees
  ADD recharge_validity_mode VARCHAR(16) DEFAULT 'extend' NOT NULL;
```

`down()` symétrique :

```sql
ALTER TABLE off_carte_multi_entrees DROP recharge_validity_mode;
```

Aucune modification requise sur `acces_droit_acces` (`fenetre_fin` existe déjà, RG-CQ1-04) ni sur
aucune autre table. Migration indépendante de toute autre migration en attente sur `App\Offre`/`App\Acces`.

## 10. Dépendances

- **Dépend de** (existant, non modifié dans son comportement observable) : CQ-1 en totalité
  (`spec-cq1-recharge-carte.md`) — ce lot n'est livrable qu'après CQ-1 (déjà le cas, CQ-1 est livré).
  `App\Acces\Service\CardExpiryCalculator`, `App\Acces\Service\CardRechargeHandler`,
  `App\Acces\Projection\StubProjectionDroit`, `App\Offre\Entity\CarteMultiEntrees`.
- **Référencé par** : aucun lot connu n'attend ce paramétrage pour être livrable (CQ-2/CQ-4 restent
  indépendants, §0).
- **Décision ouverte à confirmer en plan** : `RG-CQ7-04` (⚠ arbitrage A, §5) — succès silencieux vs
  refus explicite d'une recharge `Keep` sur une carte déjà expirée. Le choix retenu détermine si
  `CardRechargeHandler` reste **inchangé** (recommandation de ce lot) ou reçoit une nouvelle branche de
  refus (option écartée, documentée pour ne pas être redécouverte plus tard).
- **Décision ouverte mineure, non bloquante** : nom exact de la propriété PHP (`rechargeValidityMode`
  proposé, alternative française mixte possible — `RG-CQ7-01`, précédent CQ-5 §10 point 2).
- **Ne dépend pas** de CQ-2, CQ-3, CQ-4, CQ-0 pour être livrable et testable en autonomie — même posture
  d'indépendance que CQ-1 vis-à-vis de ces mêmes lots.
- **Risque signalé, non résolu ici** : la question analogue pour le PMV (`RG-M4-04`, §0) reste ouverte ;
  si un futur lot l'aborde, il pourra s'inspirer de l'arbitrage rendu ici sur `RG-CQ7-04` sans qu'aucune
  dépendance de code n'existe entre les deux (objets et modules différents).

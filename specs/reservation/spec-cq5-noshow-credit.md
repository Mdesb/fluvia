# Spec — CQ-5 : no-show, l'issue sur le crédit (`RG-CQ5-xx`)

- **Lot / module :** L5 · Réservation (`App\Reservation`) — lit/écrit ponctuellement `App\Acces\Entity\DroitAcces`
  (même précédent de couplage qu'`App\Reservation\Service\ProjectionAccesReservationHandler`, aucun
  fichier `App\Acces\*` modifié pour ce comportement)
- **Stories couvertes :** aucune `US-Lx-nn` dédiée au backlog ; lot issu de D24/D27
  (`COORDINATION/DECISIONS.md`), complément direct de `RG-M5-09` (no-show/annulation tardive)
- **Règles de gestion :** RG-M5-09 (no-show/annulation tardive, non re-tranché), RG-ACC3-01..05
  (projection d'accès sur réservation, non re-tranché), + `RG-CQ5-01` à `RG-CQ5-10` (nouvelles, ce lot)
- **Décisions actées, non re-tranchées :** D24 (seconde dimension orthogonale à la facturation, 3
  issues, paramétrées aux 4 portées de `RegleAnnulation`), D27 (défaut livré = `RestoredWithReschedule`,
  dégradation explicite tant que Smart Flow/SF-2 n'existe pas), D22 (émettre puis réagir — le
  consommateur peut arriver après l'événement), D19 (port + adaptateur factice avant le tiers/le
  module manquant), D3/D8 (cloisonnement à périmètre serveur), D2 (contract-first, catalogue avant
  implémentation), D5 (anglais pour tout identifiant technique **nouveau**), D7 (bus synchrone
  in-process, événements publiés après flush/commit du même travail)
- **Statut :** brouillon

## 0. Ce que ce lot n'est pas (lire avant tout)

- **La facturation du no-show** (`ModeFacturationNoShow`, `FacturationNoShow`, `StrategieFacturationNoShow`)
  n'est **pas** modifiée ici. D24 est explicite : c'est un **second axe orthogonal**, pas une 5ᵉ valeur
  de l'enum existant. Ce lot **lit** `modeFacturation` pour la citer en exemple d'orthogonalité (CA-10),
  ne le touche jamais.
- **CQ-3** (carte de N réservations, `TypeDroitAcces::Booking`, `creditRestant` aujourd'hui figé à
  `null` dans `ProjectionAccesReservationHandler:78`) n'est **pas** ce lot. CQ-5 conçoit le mécanisme
  pour qu'il s'active **sans modification** le jour où CQ-3 ouvre ce champ (§9).
- **CQ-6** (carte de séances nominative, quota de stock, rattachement à un bénéficiaire identifié,
  D24 pt.3) n'est **pas** ce lot. CQ-5 ne construit ni le produit-carte, ni le rattachement nominatif,
  ni la consommation à la réservation — seulement ce qui se passe **au no-show**, une fois qu'un crédit
  existe.
- **Smart Flow / SF-2** (proposer réellement un nouveau créneau) n'est **ni écrit ni spécifié**. D27 le
  dit noir sur blanc : ce lot livre la **moitié qui fonctionne** (restitution du crédit) et **publie
  l'événement** — aucun créneau n'est proposé, et l'interface ne doit **jamais** le laisser croire (§6,
  RG-CQ5-09).
- **L'annulation libre** (dans le délai franc, `StatutReservation::AnnuleeLibre`) n'est **pas** couverte
  par `RegleAnnulation` — elle n'a jamais engagé de pénalité, la question ne s'y pose donc pas de la même
  façon aujourd'hui. Ce que devient le crédit d'une annulation libre relève de la logique de
  consommation/CQ-6, pas de ce lot ; signalé comme trou potentiel en §10.

## 1. Objectif

Qu'un no-show ou une annulation tardive facturée réponde, en plus de « combien facture-t-on ? », à la
question que D24 a isolée : **la séance manquée est-elle décomptée du solde, restituée, ou restituée
avec un report proposé ?** — paramétrable aux quatre portées déjà connues de `RegleAnnulation`
(établissement, type de ressource, ressource, activité), pour qu'un salon de massage puisse être strict
là où la piscine du même établissement reste indulgente (D24). Le défaut livré est le plus généreux —
**restitué avec report** (D27) — et la moitié de ce défaut qui dépend d'un module non écrit (Smart Flow)
est assumée comme incomplète, jamais maquillée en promesse.

## 2. Périmètre

- **Inclus :**
  - La dimension `IssueCreditNoShow` sur `RegleAnnulation`, paramétrable aux 4 portées existantes,
    défaut `RestoredWithReschedule` (`RG-CQ5-01`).
  - La résolution — réutilisation stricte, non modifiée, de `ResolveurRegleAnnulation` (`RG-CQ5-02`).
  - Le point d'application unique, dans le passage partagé `DeclencherFacturationNoShowHandler`
    (`RG-CQ5-03`).
  - La résolution du `DroitAcces` créditable lié à la réservation, et son no-op documenté quand aucun
    crédit n'existe — le cas universel aujourd'hui (`RG-CQ5-04`).
  - L'application par issue — décompté (rien), restitué (+1 atomique), restitué+report (+1 atomique +
    événement) — avec le patron atomique déjà établi (`CardRechargeHandler`/`ValidationPassageHandler`)
    (`RG-CQ5-05`).
  - La traçabilité sur `FacturationNoShow` (`RG-CQ5-06`), l'idempotence (`RG-CQ5-07`), l'émission
    d'événements avec extension du catalogue (`RG-CQ5-08`), la dégradation d'interface explicite D27
    (`RG-CQ5-09`), le cloisonnement (`RG-CQ5-10`).
- **Exclu (autres lots, ne pas refaire ici) :**
  - `ModeFacturationNoShow` et tout ce qui facture réellement (vente différée, PMV, prélèvement,
    facture) — axe séparé, inchangé.
  - CQ-3 : ouverture de `creditRestant` sur les droits `Booking`.
  - CQ-6 : produit-carte de séances nominatif, sa consommation à la réservation.
  - SF-0/SF-2 : Smart Flow, la proposition réelle d'un créneau.
  - Le crédit d'une annulation **libre** (dans le délai franc) — signalé en §10, pas construit ici.
  - Un écran/back-office dédié — l'API expose les champs (§5) ; la surface d'affichage suit D13 (modale)
    dans un lot ultérieur si besoin.

## 3. État actuel (code réel) — le point qui détermine tout le reste

### 3.1 Ce qui existe déjà et n'est pas touché

- `RegleAnnulation` (`app/src/Reservation/Entity/RegleAnnulation.php`) porte déjà les 4 portées
  (`PorteeRegleAnnulation::Etablissement/TypeRessource/Ressource/Activite`, lignes 56-72), le délai
  franc, `modeFacturation` (`ModeFacturationNoShow`, ligne 93-95), les exonérations.
- `ResolveurRegleAnnulation::resoudre()` (`app/src/Reservation/Service/ResolveurRegleAnnulation.php:25-58`)
  implémente déjà la précédence **activité > ressource > type_ressource > établissement**, retourne
  `null` si aucune règle active — comportement à réutiliser tel quel (`RG-CQ5-02`), rien à corriger.
- `DeclencherFacturationNoShowHandler::declencher()`
  (`app/src/Reservation/Service/DeclencherFacturationNoShowHandler.php:36-66`) est le point de passage
  **partagé** entre la branche tardive de `AnnulerReservationProcessor`
  (`app/src/Reservation/State/AnnulerReservationProcessor.php:78`) et la branche no-show de
  `BasculerNoShowCommand` (`app/src/Reservation/Command/BasculerNoShowCommand.php:82`). C'est le seul
  endroit où `RegleAnnulation` est résolue **et** appliquée pour ces deux cas — c'est ici que
  `RG-CQ5-03` s'accroche.
- `booking.no_show` **existe déjà** au catalogue (`COORDINATION/CONTRACT/catalogue-evenements.md:38`) et
  est **déjà publié** par `BasculerNoShowCommand:92-116`, avec `customerId`, `amountAtRisk`,
  `hasBillingRule`, `slotId`. Il n'y a **rien à créer** pour l'événement lui-même — seulement à
  l'étendre (§6). `booking.cancelled` existe et est publié symétriquement par
  `AnnulerReservationProcessor:112-124`, y compris pour la branche tardive.

### 3.2 Le mécanisme réel de crédit — la question posée par la mission, tranchée dans le code

Deux mécanismes de crédit coexistent, **sans lien entre eux** :

**a) `TypeDroitAcces::CarteQuota`** (carte multi-entrées piscine/salle, D23). `creditRestant` est réel,
décrémenté **exclusivement au passage physique** — `ValidationPassageHandler.php:183-204`, un `UPDATE`
SQL conditionnel (`credit_restant = credit_restant - 1 WHERE ... credit_restant > :plancher`), jamais à
la réservation, jamais au booking. Ce droit n'est **jamais** rattaché à une `Reservation` :
`ProjectionAccesReservationHandler` (voir b) construit uniquement des droits `TypeDroitAcces::Booking`,
et `DroitAcces.reservationRef` n'est renseigné que pour ceux-là (`ProjectionAccesReservationHandler.php:67-68`).
**Un no-show de réservation n'a donc structurellement rien à voir avec un droit `CarteQuota`.**

**b) `TypeDroitAcces::Booking`** (projection d'accès sur réservation, ACC-3). C'est le seul type de
droit lié à une `Reservation` (`reservationRef`). Mais
`ProjectionAccesReservationHandler::projeterSiApplicable()` pose **explicitement et systématiquement**
`->setCreditRestant(null)` (ligne 78, commentaire ligne 72-75 : « creditRestant/produitRef = null (pas
de décompte, pas de Produit M1) »). **Aujourd'hui, sans exception, un droit `Booking` n'a jamais de
crédit** — ni décrémenté au booking, ni décrémenté au passage, ni décrémentable du tout.

**Conclusion, sans ambiguïté :** *aujourd'hui, aucune réservation ne décrémente jamais aucun crédit, à
aucun moment.* Le no-show n'a donc **rien à restituer** (rien n'a bougé) ni **rien à décompter de plus**
(rien n'est en jeu). La dimension `IssueCreditNoShow` que ce lot ajoute n'a, en l'état du code, **aucun
effet observable sur un solde réel** — c'est un mécanisme complet mais dormant, exactement comme
`booking.no_show` était publié sans aucun abonné avant Revenue Recovery (D22).

### 3.3 Ce que fera CQ-3/CQ-6, et pourquoi ça détermine le sens de « décompté » vs « restitué »

D24 §2 établit que le quota **périodique** existant (`QuotaFormuleResolver`, « deux aquagym par
semaine ») est résolu **à la réservation**, dans `ReserverProcessor` — pas au passage. La carte de
séances de D24 (quota de **stock**, ce que CQ-6 construira) est décrite comme un mécanisme voisin qui
« partage le même point de consommation ». C'est cohérent avec un besoin produit réel : un quota de
stock doit être vérifié **et décrémenté à la réservation**, sinon rien n'empêche de réserver plus de
séances que la carte n'en contient.

⚠ **HYPOTHÈSE (informe le sens des 3 issues, à confirmer par la spec CQ-3/CQ-6 elle-même) :** le futur
crédit d'une carte de séances (droit `Booking` à `creditRestant` non nul, ou équivalent CQ-6) sera
décrémenté **à la confirmation de la réservation** (`ReserverProcessor`, par analogie avec
`QuotaFormuleResolver`), **pas** au passage physique — une prestation sur rendez-vous (massage) n'a pas
nécessairement de badge/portillon. C'est cette hypothèse qui donne son sens à D24 : « décompté » n'est
pas une action supplémentaire au no-show, c'est **l'absence** d'action — le crédit, déjà pris à la
réservation, **reste pris**. « Restitué » **est** une action — rendre ce qui avait déjà été prélevé. Si
cette hypothèse s'avérait fausse (décompte au passage plutôt qu'au booking), le sens des deux issues
s'inverserait et `RG-CQ5-05` devrait être revu — c'est pourquoi ce lot conçoit les deux issues comme
symétriques et indépendantes du point de décompte réel plutôt que de figer une hypothèse dans le nom des
opérations SQL (§4).

## 4. La dimension `IssueCreditNoShow`

```php
enum IssueCreditNoShow: string
{
    case Decremented = 'decremented';
    case RestoredValue = 'restored'; // nommage indicatif — voir §10 pt.2 pour le nom de propriété PHP
    case RestoredWithReschedule = 'restored_with_reschedule';
}
```

Ajoutée à `RegleAnnulation` comme un champ obligatoire de plus, au même rang que `modeFacturation`
(même colonne DB `enumType`, même exposition API, même permission `reservation.parametrer_annulation`) —
**pas une nouvelle table, pas une nouvelle portée**. Défaut de construction : `RestoredWithReschedule`
(D27).

## 5. Comportements & règles

- **`RG-CQ5-01`** — `RegleAnnulation` porte un champ `issueCreditNoShow: IssueCreditNoShow`, colonne
  `enumType`, non nullable, défaut applicatif `RestoredWithReschedule` (D27). Exposé en lecture/écriture
  sur les mêmes opérations API que `modeFacturation` (`Post`/`Patch`,
  `reservation.parametrer_annulation`), même groupe de sérialisation `regle_annulation:read/write`.

- **`RG-CQ5-02`** — La résolution de la règle applicable **ne change pas** : `ResolveurRegleAnnulation::resoudre()`
  reste l'unique point de résolution, précédence activité > ressource > type_ressource > établissement,
  inchangée. La `RegleAnnulation` retournée porte les deux axes (`modeFacturation` et
  `issueCreditNoShow`) — un seul objet résolu une seule fois, lu deux fois indépendamment (D24 :
  orthogonalité, pas produit cartésien).

- **`RG-CQ5-03`** — L'application de l'issue crédit a lieu **dans**
  `DeclencherFacturationNoShowHandler::declencher()`, immédiatement après la création de
  `FacturationNoShow` (même règle déjà résolue, même transaction, même flush). Si `$regle === null`
  (aucune règle active) : **aucune décision de crédit n'est prise** — symétrique du no-op facturation
  déjà existant à la ligne 44-48 (« aucune facturation par défaut »). Un établissement qui n'a pas
  paramétré `RegleAnnulation` ne voit **aucun** mouvement de crédit automatique, dans un sens comme dans
  l'autre — pas de restitution silencieuse par défaut d'absence de règle.

- **`RG-CQ5-04`** — Résolution du droit créditable : recherche du `DroitAcces` lié à la réservation via
  `ProjectionAccesReservation.droitAccesRef` (même repository lookup que
  `ProjectionAccesReservationHandler::revoquerSiProjete()`, ligne 115-119). Deux cas de sortie
  immédiate, **documentés comme non-erreurs** :
  - aucune `ProjectionAccesReservation` (Ressource `ouvreAcces=false`, ou jamais projetée) ;
  - une projection existe mais `DroitAcces.creditRestant === null` — **le cas universel aujourd'hui**
    (§3.2).

  Dans les deux cas : `creditActionne = false` sur `FacturationNoShow` (`RG-CQ5-06`), aucun `UPDATE` SQL
  émis, aucun événement de restitution. Le résultat est enregistré quand même — l'API et l'événement
  doivent pouvoir dire « la règle demandait une restitution, mais il n'y avait rien à restituer »,
  distinctement de « la règle demandait un décompte ».

- **`RG-CQ5-05`** — Quand un droit créditable existe (`creditRestant` non nul), application par issue :
  - **`Decremented`** — aucune écriture supplémentaire. Sous l'hypothèse §3.3 (décompte au booking), le
    crédit déjà pris **reste pris** ; la séance est perdue. `creditActionne = true`,
    `creditRestitue = false`.
  - **`RestoredValue` (« restitué »)** — un `UPDATE` SQL conditionnel unique,
    **même patron que `CardRechargeHandler`/`ValidationPassageHandler`** (pas de read-modify-write) :
    `UPDATE acces_droit_acces SET credit_restant = credit_restant + 1 WHERE id = :id`, suivi d'un
    `$em->refresh($droit)` (même garde que `CardRechargeHandler:159` contre l'écrasement d'une écriture
    concurrente par un `flush()` Doctrine basé sur une valeur périmée). Si un `Appairage` actif existe
    pour ce droit, bascule `Support.versionMaj` (`VersionSnapshotSequencer::suivant()`, même règle que
    D23 : un terminal hors-ligne doit voir le nouveau solde). `creditActionne = true`,
    `creditRestitue = true`.
  - **`RestoredWithReschedule`** — strictement la même écriture que `RestoredValue`, plus la publication
    de l'événement dédié (`RG-CQ5-08`).

  Ce service vit dans `App\Reservation\Service` (nouveau, ex. `AppliquerIssueCreditNoShowHandler`) et
  lit/écrit directement `App\Acces\Entity\DroitAcces`/`Support`/`Appairage` — **même précédent de
  couplage** que `ProjectionAccesReservationHandler` (docblock ligne 20-22 : « aucun fichier `App\Acces\*`
  n'est modifié pour ce comportement »). Il **ne réutilise pas** `CardRechargeHandler` tel quel : sa
  signature est liée à `BilletSupport`/`Vente` (le flux de vente), qu'un no-show n'a pas — seul le
  **patron** SQL/atomicité est repris, pas la classe.

- **`RG-CQ5-06`** — `FacturationNoShow` (déjà 1-1 avec `Reservation`, contrainte unique existante
  `uniq_facturation_no_show_reservation` sur `reservation_id`,
  `app/src/Reservation/Entity/FacturationNoShow.php:30`) est étendue de trois champs :
  `issueCreditNoShow` (copie figée de la valeur appliquée — une règle peut être reparamétrée après
  coup, on trace ce qui a **réellement** été décidé, pas ce que dirait la règle si on la relisait
  aujourd'hui), `creditActionne` (bool), `creditRestitue` (bool). C'est ce qui permet à un exploitant
  de répondre à « qu'est-ce qui s'est vraiment passé sur le crédit de ce client ? » sans deviner.

- **`RG-CQ5-07`** — Idempotence. Primaire : les deux appelants de `declencher()` quittent l'état qui
  permettrait un second appel dès le premier passage — `BasculerNoShowCommand` ne sélectionne que
  `statut = Confirmee` (ligne 77), `AnnulerReservationProcessor` exige `occupePlace()` en entrée (ligne
  53-55) et bascule le statut avant tout traitement métier. Secondaire, filet de sécurité : la
  contrainte unique `uniq_facturation_no_show_reservation` transforme un second appel accidentel
  (bug, retry, script admin) en **échec dur au flush** (violation de contrainte, transaction annulée)
  plutôt qu'en double restitution silencieuse — aucun crédit ne peut être restitué deux fois pour la
  même réservation.

- **`RG-CQ5-08`** — Émission d'événement :
  - `booking.no_show` (existant) et `booking.cancelled` (existant, branche tardive uniquement — la
    branche libre n'a jamais résolu de `RegleAnnulation`) sont étendus de deux champs de payload :
    `creditIssue` (`decremented`/`restored`/`restored_with_reschedule`/absent si aucune règle) et
    `creditRestoredAmount` (`0`|`1`, toujours `0` si `creditActionne = false`). Extension **additive**,
    aucun champ existant retiré ou renommé — pas de rupture pour Revenue Recovery/Smart Flow, déjà
    abonnés.
  - Un événement **nouveau**, `booking.reschedule_requested`, à ajouter au catalogue
    (`COORDINATION/CONTRACT/catalogue-evenements.md`, **avant** implémentation, D2), publié
    **uniquement** quand `issueCreditNoShow = RestoredWithReschedule` **ET** `creditActionne = true`
    (pas de demande de report pour un crédit qui n'a jamais existé). Payload minimal : `customerId`,
    `reservationRef`, `slotId` (créneau manqué), `droitId` (droit crédité). Émis depuis le même
    appelant que l'événement `booking.no_show`/`booking.cancelled` correspondant — **jamais** depuis
    `DeclencherFacturationNoShowHandler` lui-même, pour la même raison déjà actée dans
    `AnnulerReservationProcessor:104-106` (« ce handler reçoit le statut cible en argument, il ne sait
    pas lequel des deux événements il produit »). Publié **après** le flush/commit du travail qu'il
    annonce (D7).
  - Nommage volontaire — `reschedule_requested`, pas `reschedule_proposed` ni `reschedule_scheduled` :
    l'événement est une **demande** adressée à Smart Flow, pas la confirmation qu'un créneau a été
    proposé. Aucun consommateur aujourd'hui (SF-2 non écrit) — pattern déjà appliqué à `payment.failed`
    avant Revenue Recovery (D19/D22) : le contrat se construit et se teste sans le tiers/le module
    manquant.

- **`RG-CQ5-09`** — Dégradation d'interface, **le point le plus important de ce lot** (D27). Tant que
  Smart Flow/SF-2 n'existe pas, **aucune** surface — API, back-office, notification client — ne doit
  affirmer qu'un nouveau créneau va être proposé. Ce que l'API peut légitimement dire :
  `creditRestitue: true` (fait accompli, vérifiable, le solde a bougé) et, séparément,
  `reportDemande: true` (un signal a été émis vers un mécanisme qui n'existe pas encore — ⚠
  **HYPOTHÈSE**, nom exact à trancher en plan, §10 pt.1) — jamais un champ qui suggère une date, un
  créneau ou une garantie de rappel. La différence entre « restitué » et « restitué avec report » doit
  être **visible** dans l'API dès ce lot (deux booléens distincts), même si le second reste sans effet
  observable pour le client final avant SF-2.

- **`RG-CQ5-10`** — Cloisonnement (D3/D8). Le paramétrage de `issueCreditNoShow` suit exactement les
  mêmes contrôles d'accès et de périmètre établissement que les champs existants de `RegleAnnulation` —
  aucune nouvelle surface d'entrée, aucun nouvel endpoint. La résolution du `DroitAcces` créditable
  (`RG-CQ5-04`) est **interne** (aucun identifiant fourni par le client, contrairement à
  `CardRechargeHandler` qui résout depuis un `BilletSupport` client) — hors du périmètre strict de D8,
  mais doit filtrer par le même établissement que la réservation, même garde que
  `ProjectionAccesReservationHandler` qui pose systématiquement
  `droit.setEtablissement(reservation.getEtablissement())`.

## 6. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| `RegleAnnulation` | `issueCreditNoShow` | `IssueCreditNoShow` (enum string) | non nul, défaut `restored_with_reschedule` | Nouveau champ, même rang que `modeFacturation` (`RG-CQ5-01`) |
| `IssueCreditNoShow` (nouvel enum) | — | `decremented` \| `restored` \| `restored_with_reschedule` | — | `App\Reservation\Enum`, anglais (D5) |
| `FacturationNoShow` | `issueCreditNoShow` | `IssueCreditNoShow` (enum string), nullable | copie figée de la valeur appliquée | Audit — ne suit pas une règle reparamétrée après coup (`RG-CQ5-06`) |
| `FacturationNoShow` | `creditActionne` | bool, défaut `false` | — | Un droit créditable a-t-il été trouvé ? (`RG-CQ5-04`) |
| `FacturationNoShow` | `creditRestitue` | bool, défaut `false` | — | `true` seulement si `Restored`/`RestoredWithReschedule` **et** `creditActionne=true` |
| `DroitAcces` | `creditRestant` | int, nullable (existant, inchangé) | — | Lu/écrit par ce lot ; `null` = pas de crédit, no-op (`RG-CQ5-04`) |
| Événement `booking.no_show` (existant, étendu) | `creditIssue` | string \| absent | absent si aucune règle résolue | Extension additive (`RG-CQ5-08`) |
| Événement `booking.no_show` (existant, étendu) | `creditRestoredAmount` | `0`\|`1` | — | Toujours `0` si `creditActionne=false` |
| Événement `booking.cancelled` (existant, étendu) | `creditIssue`, `creditRestoredAmount` | idem | seulement branche tardive | Cohérence avec le passage partagé (`RG-CQ5-08`) |
| Événement `booking.reschedule_requested` (nouveau) | `customerId`, `reservationRef`, `slotId`, `droitId` | string (UUID) | publié seulement si `RestoredWithReschedule` **et** `creditActionne=true` | Zéro consommateur avant SF-2 (`RG-CQ5-08`) |

## 7. Critères d'acceptation

- **CA-1** — *Étant donné* une `RegleAnnulation` de portée Activité avec `issueCreditNoShow=Restored`
  et un `DroitAcces` créditable (`creditRestant=3`) projeté sur la réservation, *quand* la réservation
  bascule en no-show, *alors* `creditRestant` passe à `4`, `FacturationNoShow.creditRestitue=true`, et
  l'événement `booking.no_show` porte `creditIssue=restored`, `creditRestoredAmount=1`.

- **CA-2** — *Étant donné* la même règle mais `issueCreditNoShow=Decremented`, *quand* la réservation
  bascule en no-show, *alors* `creditRestant` reste `3` (inchangé), `creditActionne=true`,
  `creditRestitue=false`, l'événement porte `creditIssue=decremented`, `creditRestoredAmount=0`.

- **CA-3** — *Étant donné* une nouvelle `RegleAnnulation` créée sans préciser `issueCreditNoShow`,
  *alors* elle porte `restored_with_reschedule` (défaut D27).

- **CA-4** — *Étant donné* `issueCreditNoShow=RestoredWithReschedule` et un droit créditable, *quand*
  la réservation bascule en no-show, *alors* `creditRestant +1` **et** l'événement
  `booking.reschedule_requested` est publié (customerId/slotId/droitId corrects), **et** aucune entité
  « proposition de créneau », aucune notification, aucun créneau réellement proposé n'existe nulle part
  dans le système à l'issue du traitement (assertion négative — Smart Flow absent).

- **CA-5** — *Étant donné* une réservation sans `DroitAcces` projeté (Ressource `ouvreAcces=false`, cas
  majoritaire aujourd'hui) et une règle `Restored`, *quand* elle bascule en no-show, *alors*
  `creditActionne=false`, `creditRestitue=false`, aucun `UPDATE` SQL sur `acces_droit_acces`, aucun
  champ de l'API ne prétend qu'un crédit a bougé.

- **CA-6** — *Étant donné* un `DroitAcces` projeté mais `creditRestant=null` (cas universel `Booking`
  aujourd'hui, §3.2), *alors* même résultat que CA-5 — no-op documenté, pas une erreur, pas d'exception.

- **CA-7** — *Étant donné* deux `RegleAnnulation` dans le même établissement — l'une portée Activité =
  « Massage » avec `Decremented`, l'autre portée Établissement (repli) avec `RestoredWithReschedule` —
  *quand* une réservation sur l'activité « Massage » bascule en no-show, *alors* c'est la règle Activité
  (`Decremented`) qui s'applique, pas celle d'établissement — précédence de `ResolveurRegleAnnulation`
  non modifiée, vérifiée pour ce nouvel axe.

- **CA-8** — *Étant donné* une réservation déjà basculée en no-show avec restitution effectuée, *quand*
  un second appel de `declencher()` est tenté sur la même réservation, *alors* il échoue (violation de
  contrainte unique, transaction annulée) et le solde n'a été incrémenté **qu'une seule fois**.

- **CA-9** — *Étant donné* une réservation annulée hors délai franc par un agent
  (`AnnulerReservationProcessor`, branche tardive) avec une règle `Restored`, *alors* le même mouvement
  de crédit qu'un no-show automatique est appliqué (même handler partagé, même résultat) — l'exploitant
  n'a pas un comportement différent selon qui déclenche le passage.

- **CA-10** — *Étant donné* une règle avec `modeFacturation=DebitPmv` **et** `issueCreditNoShow=Restored`,
  *quand* la réservation bascule en no-show, *alors* **les deux** effets ont lieu indépendamment — le
  porte-monnaie est débité (facturation) **et** le crédit est restitué (+1) — preuve observable de
  l'orthogonalité D24 (une séance peut être décomptée/restituée **et** facturée/non facturée, dans
  n'importe quelle combinaison).

## 8. Cas limites

- **Établissement absent sur la réservation** — même garde héritée que `BasculerNoShowCommand:90-91`
  (`if ($etablissementNoShow !== null)`) : si absent, ni `booking.no_show` ni
  `booking.reschedule_requested` ne sont publiés. Comportement hérité, pas une nouveauté de ce lot.
- **Concurrence crédit** — un droit créditable pourrait en théorie être modifié en parallèle par un
  passage physique (cas hypothétique, aujourd'hui impossible pour un droit `Booking` puisqu'il n'a
  jamais de crédit, mais deviendra possible avec CQ-3/CQ-6) : le patron `UPDATE` SQL conditionnel +
  `refresh()` (`RG-CQ5-05`) protège contre l'écrasement, même garde que `CardRechargeHandler`.
- **`RegleAnnulation` reparamétrée entre la réservation et le no-show** — c'est la règle **en vigueur au
  moment du basculement** qui s'applique (résolution à l'instant T, comportement déjà existant pour
  `modeFacturation`, pas une nouveauté). `FacturationNoShow.issueCreditNoShow` fige ce qui a été décidé,
  pas ce que dirait la règle si on la relisait après coup.
- **Réservation à plusieurs participants** — ⚠ HYPOTHÈSE : `ProjectionAccesReservationHandler` projette
  **un seul** `DroitAcces` par `Reservation` (pas par participant), donc ce lot restitue/décompte **un
  seul** crédit par réservation, pas un par tête. Le modèle exact de consommation nominative (un crédit
  par bénéficiaire vs par réservation) est de la responsabilité de CQ-6 — à confirmer par sa spec.
- **Aucune `RegleAnnulation` active** — aucune décision de crédit n'est prise (`RG-CQ5-03`), symétrique
  du comportement facturation existant. Ce n'est **pas** traité comme « restitution par défaut » même
  si `RestoredWithReschedule` est le défaut d'une règle **créée** — l'absence de règle n'est pas
  équivalente à une règle par défaut.

## 9. Recommandation de périmètre — CQ-5 est livrable maintenant, sans attendre CQ-3/CQ-6

Le mécanisme (dimension, résolution, application, idempotence, événements) est conçu pour être un
**no-op sûr et testable** sur l'état actuel du crédit (universellement `null`, §3.2) : testé en
construisant directement, dans les tests, un `DroitAcces` créditable rattaché à une réservation (même
posture que `CardRechargeHandler` testé avant que CQ-6 n'existe côté vente) — ce cas deviendra le cas
réel de production dès que CQ-3 retirera le `setCreditRestant(null)` systématique de
`ProjectionAccesReservationHandler:78`.

**Recommandation : livrer CQ-5 avant CQ-3/CQ-6, pas après.** L'ordre inverse — laisser CQ-3/CQ-6 faire
varier `creditRestant` sur des droits `Booking` sans qu'aucune politique de no-show ne sache quoi en
faire — reproduit exactement le défaut déjà identifié pour `ProjectionAccesReservationHandler` avant sa
consommation réelle (D22 : « un fait publié sans personne pour le lire ») en le retournant à l'envers
(un crédit qui bouge sans politique de sortie). CQ-5 posé en premier signifie que le jour où CQ-3/CQ-6
ouvrent le crédit, le no-show sait **déjà** quoi en faire — aucune régression à craindre, aucune fenêtre
où un crédit bougerait sans règle de sortie déterministe.

## 10. Décisions ouvertes

1. **Nom exact du champ API annonçant la demande de report sans promettre le report lui-même**
   (`RG-CQ5-09`) — proposé `reportDemande`/`rescheduleRequested`, à trancher en plan avec la contrainte
   non négociable : ne jamais suggérer une date ou un créneau avant SF-2.
2. **Nom de la propriété PHP portant l'enum** — `issueCreditNoShow` (mixte, cohérent avec le reste
   français de `RegleAnnulation`) vs un nom entièrement anglais type `creditOutcome` (D5, nouveau code).
   Ce lot retient `issueCreditNoShow` par cohérence locale avec l'entité existante ; à confirmer/ajuster
   en plan si la revue de cohérence l'exige.
3. **Le crédit d'une annulation libre** (dans le délai franc) n'est pas traité par `RegleAnnulation`
   (§0). Si CQ-6 décrémente réellement à la réservation (§3.3), une annulation libre doit
   logiquement restituer le crédit inconditionnellement — mais ce n'est **pas** une décision de
   `IssueCreditNoShow` (qui ne s'applique qu'à la branche tardive/no-show). Signalé pour que CQ-6 ne
   l'oublie pas ; **pas construit ici**.
4. **Faut-il un champ explicite disant « le mécanisme de crédit est indisponible pour cette
   réservation » ?** (ex. `mecanismeCreditIndisponible: true` quand `creditActionne=false` faute de
   droit créditable) plutôt que de laisser l'exploitant déduire l'absence d'action de deux booléens à
   `false`. Proposé, non tranché — l'utilité dépend de si l'écran back-office (hors périmètre ce lot)
   affiche ce champ un jour.
5. **Portée du service d'application** (`RG-CQ5-05`) — proposé dans `App\Reservation\Service` par
   précédent direct (`ProjectionAccesReservationHandler`), mais pourrait aussi être vu comme relevant
   d'`App\Acces` si l'intention est de centraliser toutes les opérations de crédit côté Accès (au risque
   d'inverser la direction de dépendance établie par ACC-3). Argumenté en faveur de Réservation dans ce
   lot ; à confirmer en plan.
6. **Confirmation du point de décompte réel** (§3.3, hypothèse structurante) — le sens de `Decremented`
   vs `Restored` dépend entièrement de l'hypothèse « décompte au booking, pas au passage ». Si la spec
   CQ-3/CQ-6 tranche différemment, `RG-CQ5-05` doit être relu avant implémentation, pas après.

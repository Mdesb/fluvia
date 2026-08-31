# Spec — Smart Flow (`SF-0`)

- **Lot / module :** transverse, **greenfield** — nouveau module `App\SmartFlow`, branché sur
  `App\Reservation` (mature) et `App\Acces` par le bus d'événements uniquement (D2). Aucun fichier
  `App\Reservation\*`/`App\Acces\*` n'est modifié par ce module — il **consomme**, il n'écrit jamais
  dans un autre domaine.
- **Stories couvertes :** aucune `US-Lx-nn` existante. Module hors backlog initial, priorisé par D22
  puis rendu **critique** par D27 (il porte la moitié du comportement par défaut du no-show sur séance
  prépayée). Les user stories de ce document sont numérotées `US-SF-nn`, sur le modèle déjà appliqué par
  `spec-stock.md`/`spec-personnel.md`/`spec-finance-suite.md` : ⚠ **hors backlog — à faire valider et
  chiffrer avant développement.**
- **Règles de gestion :** nouvelles `RG-SF-01` à `RG-SF-14` (ce document). Référence sans re-trancher :
  `RG-M5-04/06/09` (annulation, liste d'attente, no-show — `App\Reservation`), `RG-CQ5-01..10`
  (`specs/reservation/spec-cq5-noshow-credit.md`, D24/D27), `RG-ACC-06` (`Passage`, `App\Acces`).
- **Décisions actées, non re-tranchées :** D22 (Smart Flow réagit à des événements, ne fait rien par
  lui-même ; « émettre puis réagir »), D24/D27 (no-show sur séance prépayée, `IssueCreditNoShow`,
  `RestoredWithReschedule` = défaut livré, dégradation explicite tant que ce module n'existe pas), D2
  (contract-first, communication uniquement par événements), D3/D8 (cloisonnement à périmètre serveur,
  échec fermé), D5 (anglais pour tout identifiant technique neuf), D6 (le tenant d'un événement dérive
  du sujet, jamais de `X-Etablissement`), D7 (bus synchrone in-process), D13 (modale par défaut, écran
  l'exception justifiée), D19 (port + adaptateur avant un besoin non encore prouvé).
- **Statut :** brouillon.

## 0. Constat vérifié avant conception (à ne pas re-découvrir en plan)

Le brief de lancement de cette tâche indiquait `booking.cancelled`, `booking.no_show` et
`access.recorded` comme « émis nulle part ». **Vérification faite dans le code (24/08) : ce n'est plus
tout à fait vrai**, et la différence change le périmètre de ce document.

| Événement | Statut réel constaté | Preuve |
|---|---|---|
| `booking.cancelled` | **Émis** depuis `AnnulerReservationProcessor` (les deux branches, libre et tardive), avec le payload étendu `creditIssue`/`creditRestoredAmount` du catalogue | `app/src/Reservation/State/AnnulerReservationProcessor.php:114-133` |
| `booking.no_show` | **Émis** depuis `BasculerNoShowCommand`, avec `amountAtRisk`, `hasBillingRule`, `creditIssue`/`creditRestoredAmount` | `app/src/Reservation/Command/BasculerNoShowCommand.php:93-123` |
| `booking.reschedule_requested` | **Émis** depuis les deux mêmes points d'appel, **uniquement** si `IssueCreditNoShow::RestoredWithReschedule` a été effectivement appliqué (crédit restitué), avec `customerId`/`reservationRef`/`slotId`/`droitId` | `AnnulerReservationProcessor.php:140-152`, `BasculerNoShowCommand.php:134-145` |
| `access.card_recharged` | **Émis** depuis `CardRechargeHandler` | `app/src/Acces/Service/CardRechargeHandler.php:179` |
| `booking.created` | ⚠ **Non émis** — aucune occurrence dans `App\Reservation` (vérifié par recherche, aucun `eventBus->publish` sur ce nom) | — |
| `access.recorded` | ⚠ **Non émis** — `ValidationPassageHandler` et les processors qui écrivent `Passage` n'appellent jamais `EventBus::publish` | `app/src/Acces/Service/ValidationPassageHandler.php` (aucune occurrence) |

**Conséquence directe :** le cas d'usage prioritaire de D27 — consommer `booking.reschedule_requested`
pour proposer un nouveau créneau — a déjà son déclencheur. **SF-1 n'est donc plus un préalable complet**,
c'est un préalable **partiel** : il reste à émettre `booking.created` et `access.recorded` (footfall),
mais plus `booking.cancelled`/`booking.no_show`/`booking.reschedule_requested`, déjà en place et
couverts par les tests du lot CQ-5. Ce constat est repris en détail en §8.

## 1. Objectif

Que la plateforme **réagisse** aux faits déjà produits par `Reservation` et `Acces` sans qu'aucun humain
n'ait à surveiller un tableau : un no-show sur séance prépayée se voit proposer un nouveau créneau
(D27), une place libérée par une annulation est reproposée à la liste d'attente ou revendue, un retard
constaté ne fait pas perdre silencieusement une place, et l'affluence réelle (passages, recharges) est
disponible sans re-calcul manuel. Smart Flow ne vend rien et ne facture rien lui-même — il orchestre des
conséquences déjà décrites par le catalogue d'événements.

## 2. Périmètre

- **Inclus :**
  - **SF-2 — Report de no-show (D27, prioritaire)** : consommer `booking.reschedule_requested`,
    proposer un nouveau créneau compatible au client, jusqu'à confirmation ou expiration de la
    proposition.
  - **Créneaux libérés** : consommer `booking.cancelled`/`booking.no_show`, décider si la place libérée
    doit être reproposée (liste d'attente interne à `Reservation`, déjà gérée par
    `PromotionListeAttenteHandler` — Smart Flow ne la duplique pas) ou **revendue** hors du canal
    d'attente existant ; publier `slot.released` dans ce second cas.
  - **Liste d'attente Smart Flow** — distincte de `App\Reservation\Entity\ListeAttente` (interne,
    couplée à la jauge du créneau) : une file **par ressource** que Smart Flow peut proposer même quand
    aucun créneau précis n'est encore identifié (ex. « prévenez-moi du premier massage libre cette
    semaine »), promue à réception de `slot.released`.
  - **Affluence (footfall)** : agrégation par établissement/espace/tranche horaire à partir de
    `access.recorded` et `access.card_recharged`, exposée en lecture (tableau de bord, pas un écran de
    saisie).
  - **Retards** — cf. §4, RG-SF-13 : périmètre volontairement réduit tant que la définition produit
    n'est pas arbitrée (question ouverte §10).
- **Exclu (pour l'instant) :**
  - Émettre `booking.created` et `access.recorded` depuis `Reservation`/`Acces` — **SF-1**, hors
    périmètre de ce document et de claude-E (portée par l'intégrateur / les propriétaires de ces
    modules, cf. §8).
  - Revenue Recovery (relance commerciale panier abandonné/impayé/devis expiré) — module frère, spec
    séparée.
  - Toute écriture dans `App\Reservation`/`App\Acces` : Smart Flow ne crée jamais directement une
    `Reservation` de report — il **initie** une proposition, l'acceptation du client crée la réservation
    par le chemin normal de `Reservation` (API existante), jamais par un accès direct à ses entités
    (cohérent avec D2 : pas d'appel module→module).
  - Paiement/facturation de la nouvelle réservation issue d'un report : le crédit a déjà été restitué
    par CQ-5 avant que l'événement n'atteigne Smart Flow ; la nouvelle réservation suit le chemin
    tarifaire normal de `Reservation`.

## 3. Acteurs & droits

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Client final (portail/app) | Voir une proposition de report et l'accepter/la refuser | `smart_flow.reschedule_read_own`, action d'acceptation via l'API `Reservation` existante (pas une permission Smart Flow) |
| Agent d'accueil / caisse | Voir les propositions de report en cours pour un client, les relancer manuellement | `smart_flow.reschedule_manage` |
| Exploitant / responsable planning | Consulter les créneaux libérés, la liste d'attente Smart Flow, l'affluence | `smart_flow.read` |
| Exploitant / responsable planning | Paramétrer la politique de proposition (fenêtre de recherche, nombre de propositions, délai d'expiration) | `smart_flow.manage` |
| Système (tâche planifiée) | Expirer une proposition non répondue, relancer la file d'attente | — (acteur `null` dans l'enveloppe d'événement, D6/`EventActor`) |

⚠ HYPOTHÈSE — noms de permissions proposés par analogie avec le modèle `module × action` du socle
(`finance.*`, `reservation.*`), à arbitrer avec M8 avant figement, comme pour tous les modules déjà
livrés.

## 4. Comportements & règles

### 4.1 Créneaux libérés (SF-2, moitié « libération »)

- **RG-SF-01** — À réception de `booking.cancelled` ou `booking.no_show` avec un `slotId`, Smart Flow
  vérifie si le créneau redevient disponible (capacité résiduelle > 0). Il ne réécrit jamais l'état du
  créneau lui-même (propriété de `Reservation`) — il **lit** l'état via l'API interne exposée par
  `Reservation` (provider en lecture, pas d'accès direct à l'entité Doctrine d'un autre module) et agit
  en aval.
- **RG-SF-02** — La promotion de la **liste d'attente interne** au créneau (`ListeAttente` couplée à la
  jauge, `PromotionListeAttenteHandler`) reste la responsabilité de `Reservation` — elle se déclenche
  déjà, en synchrone, depuis `AnnulerReservationProcessor`/`BasculerNoShowCommand` **avant** que
  l'événement n'atteigne le bus. Smart Flow ne la duplique pas et ne la court-circuite pas.
  ⚠ HYPOTHÈSE — au moment où Smart Flow reçoit `booking.cancelled`/`booking.no_show` (bus synchrone,
  même transaction), la promotion interne a donc déjà pu consommer la place. Smart Flow doit **relire**
  la disponibilité réelle du créneau au moment de son traitement, jamais supposer que la place est
  encore libre du seul fait qu'un événement de libération est arrivé.
- **RG-SF-03** — Si la place reste libre après la liste d'attente interne (créneau non complet, ou
  aucune inscription en attente), Smart Flow publie `slot.released` (`slot`, `resource` — format déjà au
  catalogue) : c'est le signal que la revente/réattribution externe (SF-2, hors périmètre du présent
  document au-delà de la publication) peut agir.
- **RG-SF-04** — `slot.released` n'est publié **qu'une fois** par libération constatée (idempotence par
  `(slotId, causeEventId)` — clé technique à préciser au plan) : un `booking.cancelled` suivi d'un
  `booking.no_show` sur le même créneau (cas rare, deux réservations distinctes sur une ressource
  partageable) ne doit pas dupliquer le signal pour la part déjà signalée.

### 4.2 Liste d'attente Smart Flow (distincte de celle de `Reservation`)

- **RG-SF-05** — Une inscription Smart Flow porte une **ressource** (pas nécessairement un créneau
  précis) et un bénéficiaire, avec une fenêtre de recherche (ex. « n'importe quel créneau de cette
  semaine »). Elle est **le complément**, pas le remplacement, de `App\Reservation\Entity\ListeAttente`
  (qui reste la seule liste d'attente **sur un créneau complet donné**, RG-M5-06).
- **RG-SF-06** — À réception de `slot.released`, Smart Flow tente une promotion sur la file Smart Flow
  correspondant à la ressource du créneau libéré, par ordre d'inscription (FIFO), avant d'envisager une
  revente non ciblée.
- **RG-SF-07** — Une promotion Smart Flow ne crée **jamais** directement de `Reservation` : elle
  matérialise une **proposition** (avec délai d'expiration configurable, défaut ⚠ HYPOTHÈSE 15 minutes
  — même ordre de grandeur que `PromotionListeAttenteHandler::DELAI_CONFIRMATION_MINUTES`, faute de
  valeur produit arbitrée) que le client confirme via le chemin normal de réservation. Sans confirmation
  avant expiration, l'inscription suivante est tentée.

### 4.3 No-show avec report — SF-2, cas d'usage prioritaire D27

C'est la partie qui porte, selon D27, « la moitié du comportement par défaut de la plateforme sur le
no-show ». Elle est spécifiée en détail parce qu'elle est déjà **attendue** par un consommateur zéro
aujourd'hui (`booking.reschedule_requested`) — chaque no-show sur séance prépayée avec la règle par
défaut publie déjà cet événement, silencieusement absorbé par aucun abonné.

- **RG-SF-08** — Smart Flow s'abonne à `booking.reschedule_requested`. Le payload
  (`customerId`, `reservationRef`, `slotId`, `droitId`) ne porte **pas** l'activité/le type de ressource
  d'origine : Smart Flow doit résoudre le créneau d'origine via une lecture (provider `Reservation`) à
  partir de `slotId` pour connaître la ressource et l'activité, condition pour chercher un créneau
  **compatible** (RG-SF-09).
- **RG-SF-09** — « Compatible » signifie : même ressource ou même `codeType`/activité (⚠ HYPOTHÈSE —
  le catalogue et D27 ne précisent pas le degré de tolérance ; à trancher, cf. §10 question 1), dans une
  fenêtre de recherche paramétrable (défaut ⚠ HYPOTHÈSE 14 jours), avec capacité résiduelle disponible
  au moment de la recherche.
- **RG-SF-10** — Smart Flow propose **au client**, pas au personnel : la proposition est un objet
  consultable par le client (portail/app) et notifiable (canal de notification existant, `App\CRM` ou
  équivalent — port à réutiliser, pas à recréer, cf. `NotificationReservationInterface` côté
  `Reservation` comme précédent de conception). L'agent d'accueil peut voir l'état de la proposition en
  cours (permission `smart_flow.reschedule_manage`) mais ne la crée pas manuellement dans ce lot.
- **RG-SF-11** — Si aucun créneau compatible n'est trouvé dans la fenêtre de recherche, la proposition
  reste `en_attente_creneau` (pas d'échec silencieux) et une nouvelle tentative est faite à chaque
  `slot.released` touchant la même ressource, jusqu'à expiration globale (défaut ⚠ HYPOTHÈSE 30 jours).
- **RG-SF-12** — La confirmation du client passe par l'API existante de `Reservation`
  (`POST /reservation/reservations` ou équivalent) avec une référence à la proposition Smart Flow en
  paramètre de traçabilité — jamais par une écriture directe de Smart Flow dans `Reservation`. Une fois
  la nouvelle réservation créée, la proposition passe en `confirmee` ; Smart Flow ne décrémente ni ne
  restitue de crédit une seconde fois (déjà fait par CQ-5 avant l'émission de l'événement consommé).

### 4.4 Retards

- **RG-SF-13** — ⚠ **Portée volontairement limitée.** Aucune donnée ni aucun événement du catalogue ne
  capture aujourd'hui un « retard » (ni sur `Reservation` — seul `Emargement` porte
  `present`/`absent`, binaire, sans horodatage de comparaison au début du créneau exposé en événement —
  ni sur `Acces` — `access.recorded` porterait un horodatage de passage, mais n'est pas encore émis,
  cf. §8). Ce document ne spécifie donc **aucun comportement de retard opérationnel** au-delà du constat
  suivant, pour ne pas préempter une décision produit non prise : la donnée existe potentiellement
  (comparer `access.recorded.occurredAt` au `Creneau.debut` une fois SF-1 posé), mais ni le seuil de
  « retard », ni l'action attendue (notifier l'exploitant ? libérer la place après N minutes ? alerter
  le client ?) ne sont définis. Voir question ouverte §10.

### 4.5 Affluence (footfall)

- **RG-SF-14** — Smart Flow agrège, par établissement et par tranche horaire (⚠ HYPOTHÈSE — granularité
  à l'heure, à confirmer), le nombre de `access.recorded` reçus (une fois SF-1 posé) et le distingue par
  `resultat` (autorisé/refusé — champ déjà porté par `Passage`, à répercuter dans le payload
  `access.recorded` lors de son émission, cf. §8). `access.card_recharged` alimente un second indicateur
  (volume de recharge, pas un compte de passage) — les deux ne sont **jamais** fusionnés dans le même
  total, ce serait compter deux faits différents comme un seul.

## 5. Objets de données (entités/agrégats pressentis, sans code)

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| `RescheduleProposal` | `id` | UUID | PK | Une proposition par no-show restitué-avec-report constaté |
| `RescheduleProposal` | `establishmentId` | UUID | NOT NULL, dérivé du `tenant` de l'événement source (D6) | Jamais de `X-Etablissement` |
| `RescheduleProposal` | `originReservationRef` | UUID | NOT NULL | = `reservationRef` du payload `booking.reschedule_requested` |
| `RescheduleProposal` | `originSlotId` | UUID | NOT NULL | = `slotId` du payload |
| `RescheduleProposal` | `customerId` | UUID | NOT NULL | = `customerId` du payload — référence, Smart Flow ne stocke aucune donnée personnelle au-delà de l'id |
| `RescheduleProposal` | `droitId` | UUID | NOT NULL | = `droitId` du payload — traçabilité du crédit restitué à l'origine |
| `RescheduleProposal` | `status` | enum | `searching`\|`proposed`\|`confirmed`\|`expired` | `searching` = RG-SF-11, `proposed` = candidat trouvé et notifié |
| `RescheduleProposal` | `proposedSlotId` | UUID nullable | — | rempli seulement en statut `proposed` |
| `RescheduleProposal` | `expiresAt` | datetime | NOT NULL | fenêtre de recherche globale (RG-SF-11) |
| `RescheduleProposal` | `confirmedReservationRef` | UUID nullable | — | rempli à la confirmation (RG-SF-12) |
| `SlotWaitlistEntry` | `id` | UUID | PK | Liste d'attente Smart Flow, RG-SF-05 |
| `SlotWaitlistEntry` | `establishmentId` | UUID | NOT NULL | dérivé serveur |
| `SlotWaitlistEntry` | `resourceId` | UUID | NOT NULL | référence à `Reservation\Ressource`, jamais une relation Doctrine cross-module |
| `SlotWaitlistEntry` | `beneficiaryId` | UUID | NOT NULL | référence à `Crm\Beneficiaire` |
| `SlotWaitlistEntry` | `searchWindowStart`/`searchWindowEnd` | datetime | NOT NULL | fenêtre de recherche demandée |
| `SlotWaitlistEntry` | `rank` | int | NOT NULL | FIFO (RG-SF-06) |
| `SlotWaitlistEntry` | `status` | enum | `waiting`\|`promoted`\|`expired`\|`cancelled` | miroir de `StatutListeAttente` (`Reservation`), namespace propre |
| `FootfallAggregate` | `establishmentId` | UUID | NOT NULL | clé d'agrégation |
| `FootfallAggregate` | `periodStart` | datetime | NOT NULL | tranche horaire (RG-SF-14) |
| `FootfallAggregate` | `grantedCount`/`deniedCount` | int | ≥0 | depuis `access.recorded` |
| `FootfallAggregate` | `rechargeCount` | int | ≥0 | depuis `access.card_recharged`, indicateur distinct |

⚠ HYPOTHÈSE — ces trois agrégats sont une **projection en lecture**, reconstructible depuis le flux
d'événements si nécessaire ; le modèle exact (table dédiée vs. vue calculée) est un choix de plan
technique, pas de spec.

## 6. Événements consommés / produits

| Événement | Sens | Source | Tenant (D6) | Payload attendu | Déclenche quoi dans Smart Flow |
|---|---|---|---|---|---|
| `booking.created` | consommé | `Reservation` — ⚠ **non émis aujourd'hui (SF-1)** | dérivé de `Reservation.etablissement` | `slot`, `resource` (catalogue actuel, à affiner au plan côté émetteur) | Aucun comportement obligatoire dans ce lot ; réservé pour un futur signal d'occupation. Ne bloque pas SF-2. |
| `booking.cancelled` | consommé | `Reservation`, **déjà émis** (`AnnulerReservationProcessor`) | `Reservation.etablissement` | `slotId`, `leadTimeMinutes`, `withinFreeWindow`, `creditIssue?`, `creditRestoredAmount?` | RG-SF-01 à RG-SF-04 (libération de créneau) |
| `booking.no_show` | consommé | `Reservation`, **déjà émis** (`BasculerNoShowCommand`) | `Reservation.etablissement` | `customerId`, `amountAtRisk`, `hasBillingRule`, `slotId`, `creditIssue?`, `creditRestoredAmount?` | RG-SF-01 à RG-SF-04 (libération de créneau) |
| `booking.reschedule_requested` | consommé | `Reservation` (CQ-5), **déjà émis**, zéro consommateur avant ce lot | `Reservation.etablissement` | `customerId`, `reservationRef`, `slotId`, `droitId` | RG-SF-08 à RG-SF-12 (création `RescheduleProposal`, recherche de créneau, proposition, confirmation) |
| `access.recorded` | consommé | `Acces` — ⚠ **non émis aujourd'hui (SF-1)** | à dériver de `Passage.etablissement` (déjà porté par l'entité, D6 respecté dès l'émission) | proposé : `door`(→`espace`), `credential`(→`support`/`droit`, id seulement), `result`(→`resultat`), `slotId?` (si le passage se rattache à une réservation via `ProjectionAccesReservation`) | RG-SF-14 (footfall) ; base éventuelle d'un futur signal de retard (RG-SF-13, non spécifié) |
| `access.card_recharged` | consommé | `Acces`, **déjà émis** (`CardRechargeHandler`) | `DroitAcces.etablissement` (à vérifier au plan) | `droitId`, `supportId`, `creditsAdded`, `creditBalanceAfter`, `newExpiryAt?`, `saleId` | RG-SF-14 (indicateur de recharge, distinct du comptage de passages) |
| `slot.released` | **produit** | Smart Flow (RG-SF-03) | établissement du créneau libéré | `slot`, `resource` (format déjà au catalogue) | Auto-consommé par RG-SF-06 (promotion liste d'attente Smart Flow) ; consommable par un futur module de revente |

Rappel RG-PLAT-04/D6 : Smart Flow ne publie et ne consomme que des **références** (UUID) — jamais de
nom, coordonnées ou moyen de contact du client dans un payload d'événement. La notification au client
(RG-SF-10) passe par un port dédié côté Smart Flow, pas par le bus d'événements.

## 7. API/opérations et écrans-ou-modales (D13)

Aucun nouvel écran plein. Conformément à D13 :

- **Modale « Créneaux à réattribuer »**, ouverte depuis l'espace de travail planning existant
  (`Reservation` reste l'espace de travail durable ; Smart Flow n'en crée pas un second) : liste des
  `slot.released` en attente de reprise, avec action « proposer à la liste d'attente Smart Flow »/
  « laisser en revente ». Justification D13 : action ponctuelle sur une liste déjà affichée ailleurs,
  pas un espace de travail à part.
- **Modale « Proposition de report »**, côté client (portail/app) : ouverte depuis la notification
  reçue, affiche le créneau proposé, deux actions (accepter → chemin normal `Reservation` ; refuser →
  statut `expired` immédiat, RG-SF-12). Pas de tunnel à étapes : une seule décision.
- **Pas d'écran dédié à l'affluence** dans ce lot — les indicateurs `FootfallAggregate` sont exposés en
  API de lecture pour être **intégrés** au Reporting existant (M7), pas dupliqués dans un tableau de
  bord Smart Flow séparé (cohérent avec « zéro page redondante »).
- **API (lecture)** : `GET /smart-flow/reschedule-proposals` (filtrable par `customerId`, `status`),
  `GET /smart-flow/waitlist-entries` (filtrable par `resourceId`, `status`), `GET /smart-flow/footfall`
  (agrégats, filtrable par établissement/période).
- **API (écriture)** : `POST /smart-flow/waitlist-entries` (inscription manuelle, RG-SF-05),
  `POST /smart-flow/reschedule-proposals/{id}/accept` et `/decline` — ⚠ HYPOTHÈSE, à confirmer si
  l'acceptation doit plutôt être un simple appel à l'API `Reservation` avec un paramètre de référence
  (RG-SF-12), auquel cas cette route Smart Flow ne fait que fermer la proposition après coup ; à trancher
  au plan technique.

## 8. Sécurité & cloisonnement

- **RG-SF-15 (D3/D8)** — Tout objet Smart Flow (`RescheduleProposal`, `SlotWaitlistEntry`,
  `FootfallAggregate`) porte un `establishmentId` **dérivé serveur**, jamais du client. Pour les objets
  créés en réaction à un événement, il vient du `tenant` de l'enveloppe (déjà conforme D6, puisque
  l'émetteur le dérive de l'entité sujet). Pour les objets créés par une action utilisateur
  (`POST /smart-flow/waitlist-entries`), il est résolu depuis le périmètre serveur de la session, jamais
  depuis un champ du corps de la requête — même règle que tout `Processor` du dépôt (D8).
- **RG-SF-16** — Toute lecture d'une entité `Reservation`/`Acces` référencée par id (résolution de
  `slotId`, `resourceId`, `droitId` reçus dans un payload d'événement ou une requête) revérifie
  l'appartenance à l'établissement courant avant usage — échec fermé, jamais de repli silencieux. Un
  `slotId` qui ne résout à aucun créneau du même établissement est traité comme une donnée absente, pas
  comme une erreur qui interromprait le traitement de l'événement (le bus est synchrone, D7 : une
  exception non gérée remonterait à l'émetteur `Reservation`/`Acces`, ce qui n'est pas souhaitable pour
  une incohérence qui concerne uniquement Smart Flow).
- **RG-SF-17** — Aucun accès direct (repository Doctrine, injection de service) aux entités d'un autre
  module. Toute lecture croisée passe par une **API de lecture** exposée par le module propriétaire
  (provider API Platform) — Smart Flow ne reproduit pas le précédent de couplage direct
  (`ProjectionAccesReservationHandler`, `ApplyNoShowCreditIssueHandler`) : ce précédent est documenté
  par CQ-5 comme un compromis assumé pour un lot qui *écrit* dans `Acces` ; Smart Flow, qui ne fait que
  *lire* pour décider, n'a pas la même justification et doit rester sur des lectures API découplées
  (⚠ HYPOTHÈSE à confirmer au plan : si aucune route de lecture adéquate n'existe côté `Reservation`
  pour résoudre `slotId`→ressource/activité, il faudra soit l'ajouter côté `Reservation` — hors
  périmètre de ce document — soit accepter un couplage de lecture documenté comme CQ-5 l'a fait pour
  l'écriture).

## 9. Dépendances & préalables (SF-1)

**SF-1 reste un préalable, mais réduit** par rapport au brief initial (§0) :

| Événement à émettre | Depuis | Statut | Bloque quoi dans ce document |
|---|---|---|---|
| `booking.created` | `Reservation` (probablement `ReserverProcessor`) | Non émis | Rien de bloquant pour SF-2/RG-SF-01..12 ; seulement un futur usage d'occupation, non spécifié ici |
| `access.recorded` | `Acces` (`ValidationPassageHandler` et les processors qui écrivent `Passage`) | Non émis | RG-SF-14 (affluence) intégralement, et tout traitement futur de retard (RG-SF-13) |

Tant que `access.recorded` n'est pas émis, **RG-SF-14 (affluence) ne peut produire aucun agrégat** — le
module reste une coquille sur cette seule fonction, précisément le mode de défaillance identifié par
D22 (`ProjectionAccesReservation`). En revanche, **SF-2 (report de no-show, D27) est immédiatement
livrable** dès que ce module existe : son déclencheur (`booking.reschedule_requested`) est déjà en
production côté `Reservation`.

Pour cadrer l'émission côté source (portée par l'intégrateur / les propriétaires de `Reservation` et
`Acces`, hors périmètre de claude-E), chaque événement à émettre doit suivre le même patron déjà en
place pour `booking.cancelled`/`booking.no_show` : `EventTenant` dérivé de l'entité sujet, payload en
références uniquement (RG-PLAT-04), publication **après** flush/commit du travail (D7-bis), test de
non-régression sur le flux existant (aucune route/comportement observable modifié par l'ajout d'une
publication d'événement).

## 10. Questions ouvertes à arbitrer par claude-A

1. **Degré de tolérance de « compatible » pour un report (RG-SF-09).** Même ressource strictement, même
   `codeType`, ou même activité suffit ? Le cahier/D27 ne le précise pas. Impact direct sur le taux de
   report réussi.
2. **Fenêtres et délais par défaut** (recherche de créneau compatible 14 jours, expiration globale de
   proposition 30 jours, délai de confirmation d'une proposition Smart Flow 15 minutes) — toutes
   marquées ⚠ HYPOTHÈSE, non chiffrées par une source, calquées sur l'ordre de grandeur déjà choisi pour
   `PromotionListeAttenteHandler`. À confirmer ou à rendre paramétrables par établissement dès ce lot.
3. **Retards (RG-SF-13) — la question reste entièrement ouverte.** Quelle est la définition produit
   d'un « retard » dans ce contexte (client en retard sur un créneau réservé ? praticien en retard ?
   les deux ?), quel seuil, et quelle action attendue (notification, libération différée de la place,
   alerte à l'exploitant) ? Sans réponse, ce lot ne peut couvrir que le constat de données (§4.4) — pas
   un comportement.
4. **Canal de notification client (RG-SF-10).** Existe-t-il déjà un port transverse de notification
   réutilisable (au-delà de `NotificationReservationInterface`, propre à `Reservation`) ou Smart Flow
   doit-il définir son propre port + adaptateur (D19, sur le modèle `CollecteurSepaStubAdapter`) ?
5. **`POST /smart-flow/reschedule-proposals/{id}/accept` (§7)** — route dédiée qui orchestre l'appel à
   `Reservation`, ou simple fermeture de proposition après une création de réservation faite par le
   client via l'API `Reservation` existante (avec référence de traçabilité) ? Change la conception de
   l'intégration entre les deux modules sans changer le comportement observable côté client.
6. **`FootfallAggregate` — granularité et rétention.** Tranche horaire proposée par hypothèse ; aucune
   politique de rétention/agrégation à long terme n'est définie (le catalogue ne dit rien du volume
   attendu de `access.recorded`).
7. **Permissions `smart_flow.*` (§3)** — comme pour tous les modules déjà livrés, à arbitrer avec M8
   avant figement, notamment la distinction lecture (`smart_flow.read`) / gestion opérationnelle
   (`smart_flow.reschedule_manage`) / paramétrage (`smart_flow.manage`).

## 11. Critères d'acceptation

> Repris et **adaptés de la spec `spec-sf0-smart-flow.md` de claude-B** (arbitrage **D31** : ma spec est
> canonique car SmartFlow est le périmètre claude-E, mais ses critères d'acceptation et ses cas limites
> manquaient à la mienne). Transposés au modèle du présent document (`RescheduleProposal`,
> `SlotWaitlistEntry`, `FootfallAggregate`, RG-SF-01..17) et à ses événements — les événements
> `slot.offered`/`slot.offer_accepted` propres à la spec de B ne sont **pas** repris (mon modèle notifie
> hors bus, RG-SF-10, et n'émet que `slot.released`).

- **CA-1** — *Étant donné* un établissement où la feature Smart Flow n'est pas activée, *quand*
  `booking.cancelled` est publié, *alors* aucun `RescheduleProposal`, `SlotWaitlistEntry` ni
  `slot.released` n'est produit.
- **CA-2** — *Étant donné* un créneau complet avec un candidat en liste d'attente **interne**
  (`App\Reservation`, RG-M5-06), *quand* une réservation est annulée dans le délai franc, *alors* la
  promotion native de `Reservation` a lieu **en premier** (bus synchrone) et Smart Flow, en réagissant
  ensuite à `booking.cancelled`, **relit la disponibilité réelle** et ne publie pas `slot.released` si la
  place est déjà reprise (RG-SF-02).
- **CA-3** — *Étant donné* un créneau libéré sans candidat interne et une `SlotWaitlistEntry` `waiting`
  couvrant la ressource, *quand* `slot.released` est publié, *alors* une `RescheduleProposal` est
  matérialisée pour le candidat le plus ancien (FIFO, RG-SF-06).
- **CA-4** — *Étant donné* `booking.reschedule_requested` publié pour un no-show restitué-avec-report,
  *quand* Smart Flow le consomme, *alors* une `RescheduleProposal` `searching` est créée ; si un créneau
  compatible existe (RG-SF-09), elle passe `proposed` et le client est notifié (RG-SF-08..10).
- **CA-5** — *Étant donné* une `RescheduleProposal` `proposed`/`searching` dont `expiresAt` est dépassé
  sans confirmation, *quand* la tâche planifiée d'expiration s'exécute, *alors* elle passe `expired`
  (RG-SF-11/12) ; s'il s'agissait d'une promotion de liste d'attente, l'inscription suivante est tentée.
- **CA-6** — *Étant donné* une `RescheduleProposal` `proposed`, *quand* le client crée sa réservation par
  le chemin normal de `Reservation` puis appelle `POST /smart-flow/reschedule-proposals/{id}/accept`,
  *alors* la proposition passe `confirmed` ; un `accept` référençant une réservation d'un autre
  établissement **ou** d'un autre `customerId` est refusé 422 (IDOR, RG-SF-16, §0.9 du plan).
- **CA-7** — *Étant donné* `access.recorded` **non émis** (état réel du code, SF-1), *quand* on interroge
  `GET /smart-flow/footfall`, *alors* la réponse est un état vide **documenté comme bloqué par une
  dépendance non livrée**, jamais une donnée simulée ni une erreur silencieuse (même discipline que D27
  pour le report ; RG-SF-14).
- **CA-8** — *Étant donné* deux établissements A et B, *quand* un créneau se libère chez A, *alors* seul
  le périmètre de A voit les `RescheduleProposal`/`SlotWaitlistEntry` correspondants — jamais de fuite
  cross-établissement (D3/D8, RG-SF-15/16).
- **CA-9** — *Étant donné* un même `booking.cancelled` publié deux fois (rejeu, remontée hors-ligne
  différée), *quand* Smart Flow le consomme la seconde fois, *alors* aucun `slot.released` en double
  n'est publié (idempotence par `(slotId, subject.id)` via `SlotReleaseTrace`, RG-SF-04).
- **CA-10** — *Étant donné* le manifeste `App\SmartFlow`, *quand* la suite s'exécute, *alors* chaque
  événement de `eventsEmitted()` figure au catalogue (RG-PLAT-06, `ManifestCatalogueTest`).

## 12. Cas limites

> Même origine que la §11 (spec de claude-B, arbitrage D31), adaptés au présent modèle.

- **Plusieurs places libérées d'un coup** (créneau à capacité > 1) : chaque libération constatée donne
  une trace et un traitement **distincts** (RG-SF-04), jamais une proposition fusionnée à deux places.
- **Le créneau redevient complet entre la proposition et la confirmation.** La garde de capacité reste
  chez `Reservation` (`JaugeCreneauGuard`, source de vérité, RG-SF-01) : la création de la réservation de
  report échoue côté `Reservation`, la proposition n'est jamais confirmée et expire, puis RG-SF-06/11
  relance. Assumé en v0 — la capacité n'est jamais dupliquée chez Smart Flow.
- **Acceptation explicite plutôt que corrélation.** Contrairement à la spec de B (qui corrélait un
  `booking.created` au créneau offert, avec un risque de faux positif de conversion), mon modèle confirme
  par un **appel explicite** `POST .../accept` (§0.9 du plan) : pas de faux positif de corrélation, au
  prix d'un appel API de plus — écart de conception assumé et signalé.
- **`SlotWaitlistEntry` aux critères trop larges** : peut recevoir une proposition sur un créneau peu
  pertinent. Garde-fou v0 minimal = `searchWindowStart`/`searchWindowEnd` obligatoires (RG-SF-05) ;
  affinage laissé à SF-2 (⚠ hypothèse).
- **Désactivation de la feature Smart Flow avec des `RescheduleProposal` en cours** : aucune donnée
  supprimée, mais la tâche planifiée d'expiration/promotion **vérifie l'activation par établissement**
  avant de traiter chacun — pas une fois globalement au démarrage.
- **Réentrance du bus (D7)** : `slot.released` → promotion → notification ne doit jamais reboucler vers un
  événement déjà en cours de publication ; la profondeur est bornée par le noyau
  (`SymfonyEventBus`) — ne pas republier un déclencheur depuis son propre traitement.
- **Volume de `access.recorded`** (footfall, I3) : agréger par événement plutôt que par lot est simple
  mais coûteux à fort trafic — question de performance explicitement **différée à l'incrément I3**, non
  tranchée ici.

---

**Prochaine étape :** plan technique `SF-0→plan` (sdd-architecte), qui devra notamment (a) trancher le
mécanisme de lecture croisée avec `Reservation` (RG-SF-17, provider API vs couplage documenté), (b)
détailler l'idempotence de `slot.released` (RG-SF-04), et (c) proposer le manifeste `ModuleManifest` du
module `smart_flow` (capability, `eventsConsumed`/`eventsEmitted`, features) une fois les questions §10
arbitrées.

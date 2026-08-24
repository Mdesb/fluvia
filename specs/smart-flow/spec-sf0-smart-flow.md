# Spec — Smart Flow : retards, créneaux libérés, liste d'attente, affluence (`SF-0`)

- **Lot / module :** Opérations temps réel · nouveau module transverse `App\SmartFlow` (catalogue-modules.md §H)
- **Tâche backlog A :** `SF-0` (`COORDINATION/TASKS.md`) — spec SDD, préalable à `SF-2` (implémentation)
- **Stories couvertes :** aucune `US-Lx-nn` dédiée dans `backlog.html` — module issu de D22/D27
  (`COORDINATION/DECISIONS.md`), pas du backlog MVP initial
- **Règles de gestion :** `RG-M5-06` (liste d'attente native Réservation, **non re-tranchée** — voir §0),
  `RG-ACC-04` (jauge FMI, **non re-tranchée** — voir §0), `RG-CQ5-08` (`booking.reschedule_requested`,
  **non re-tranchée**) + `RG-SF-01` à `RG-SF-15` (nouvelles, ce lot)
- **Décisions actées, non re-tranchées :** D2 (contract-first, jamais d'appel direct module→module),
  D3/D8 (cloisonnement à périmètre serveur), D5 (anglais pour tout identifiant technique nouveau), D6
  (tenant dérivé du sujet de l'événement), D7 (bus synchrone in-process), D7-bis (asynchrone en
  complément, pas en remplacement), D22 (Revenue Recovery et Smart Flow passent en tête ; émettre
  d'abord, réagir ensuite — « Smart Flow part vraiment de zéro »), D27 (le défaut de crédit no-show est
  *restitué avec report* ; ce report **s'appuie sur un module qui n'existe pas** — c'est ce lot qui lui
  donne un sens)
- **Statut :** brouillon

## 0. Ce que ce lot n'est pas (lire avant tout)

La recherche de code préalable à cette spec a trouvé **deux mécanismes existants** qui ressemblent de
près à ce que le backlog demande à Smart Flow. Les confondre produirait soit une redondance dangereuse
(deux systèmes qui promettent la même place), soit un module qui refait, plus lentement et par le bus,
ce qu'un autre fait déjà bien en synchrone. Cette section pose la frontière avant le reste de la spec.

- **La liste d'attente « plein au moment de la réservation » existe déjà et n'est pas ce lot.**
  `App\Reservation\Entity\ListeAttente` (RG-M5-06) est un mécanisme complet et testé : un bénéficiaire
  s'inscrit sur **un** `Creneau` précis pendant qu'il est complet (`POST
  /reservation/creneaux/{id}/liste-attente`), rang FIFO, et
  `PromotionListeAttenteHandler::promouvoirSiPlaceDisponible()` promeut automatiquement le premier rang
  **de façon synchrone, dans la même transaction** que l'annulation qui libère la place — **avant**
  même que `booking.cancelled` soit publié sur le bus (`AnnulerReservationProcessor.php:96-97` appelle
  la promotion, **puis** publie l'événement aux lignes 114+). Smart Flow ne réécrit pas ce mécanisme :
  il agit **après** lui (§4, RG-SF-03), sur ce qu'il n'a pas pu couvrir.
- **La régulation d'affluence en temps réel existe déjà et n'est pas ce lot.** `App\Acces\Entity\JaugeFmi`
  (RG-ACC-04) tient un compteur de présence simultanée par `EspaceAcces`, incrémenté/décrémenté à
  chaque `Passage`, avec un seuil et un mode au dépassement (`ModeSeuil::Blocage` ou `Alerte`) qui
  **conditionne la décision d'accès elle-même**, en synchrone, dans le chemin critique du contrôle
  d'accès. Smart Flow ne prend jamais cette décision : sa capacité « affluence » (§4.4) est un **read
  model a posteriori**, construit après coup depuis `access.recorded`, pour du reporting/de l'alerte —
  jamais pour ouvrir ou fermer une porte.
- **`booking.reschedule_requested` n'est pas un nouvel événement à ajouter au catalogue** : il y figure
  déjà (CQ-5, RG-CQ5-08) et **est déjà publié** par `AnnulerReservationProcessor` et
  `BasculerNoShowCommand` — vérifié dans le code, pas seulement au contrat. Ce lot ne le crée pas ; il
  lui donne enfin un consommateur (D27).
- **Ce lot ne code rien.** Aucune entité, aucun `EventSubscriber`, aucune migration. Le livrable est ce
  fichier ; l'implémentation est `SF-2`.

## 1. Objectif

Que les places qui se libèrent (annulation, no-show, report) et les portes qui se remplissent
produisent une **réaction observable et testable**, orchestrée par événements, sans qu'aucun module ne
connaisse l'existence de Smart Flow ni Smart Flow ne connaisse le détail interne des autres — pour que
D27 cesse d'être une promesse à moitié tenue et que le catalogue cesse de compter des événements sans
personne pour les entendre (D22).

## 2. Périmètre

**Inclus (v0, livrable par SF-2)**
- Le module `App\SmartFlow`, transverse, activable par établissement (capacité `smart_flow`), abonné au
  bus, jamais appelé en dur par un autre module (RG-SF-01).
- **Créneaux libérés** : réaction à `booking.cancelled` / `booking.no_show` **après** que la file
  d'attente native de Réservation (RG-M5-06) a eu sa chance — publication de `slot.released` (déjà au
  catalogue) quand une place reste disponible sans candidat natif (RG-SF-03/04).
- **Liste d'attente Smart Flow** : `WaitlistEntry`, une demande **large** (créneau *quelconque*
  correspondant à des critères — activité/type de ressource/fenêtre de dates), distincte et
  complémentaire de `ListeAttente` (créneau *unique*, précis). Inscription, ordre par défaut FIFO,
  appariement à `slot.released`, offre, expiration (§4.3, RG-SF-05 à 09).
- **La boucle report → proposition** : consommation de `booking.reschedule_requested` (zéro consommateur
  aujourd'hui) pour produire une offre **prioritaire**, hors file, au client dont le crédit a été
  restitué avec report (D27) (§4.5, RG-SF-10).
- **Affluence — mesure seule** : `FootfallSnapshot`, agrégation a posteriori de `access.recorded` par
  zone/période, exposée en lecture. Un seuil déclenche au plus une **alerte** informative
  (`footfall.threshold_reached`), jamais une action de blocage (§4.4, RG-SF-12).
- Idempotence des réactions (RG-SF-11) et cloisonnement établissement de bout en bout (D3/D6).

**Exclu (pour l'instant)**
- **Retards « en cours de créneau »** (client pas encore là, créneau pas encore terminé) : aucune
  source d'événement n'existe dans le code aujourd'hui (le seul signal existant, `booking.no_show`, est
  l'aboutissement **après** la marge de fin de créneau — cf. `BasculerNoShowCommand`, §7). Traité comme
  question ouverte, pas comme fonctionnalité v0 (§9, RG-SF-13).
- **Revente à un public externe au client** (bourse de dernière minute publique, marketing sortant) :
  n'a pas de trigger ni de canal identifiés dans le socle actuel (`Communication` est encore `~`,
  cf. `COORDINATION/CONTRACT/catalogue-modules.md` §B) ; hors v0, esquissée en §9.
- **Régulation active de l'affluence** (fermeture automatique de la vente, changement de seuil FMI
  depuis Smart Flow) : resterait un appel direct vers `Acces`, interdit par D2 sans un port explicite —
  hors v0 (§9).
- **Toute modification du code existant** (`ListeAttente`, `JaugeFmi`, `RegleAnnulation`…) : ce lot ne
  retranche aucune décision actée.

## 3. Acteurs & droits

Comme le noyau de plateforme (`specs/platform/spec-platform-core.md`), Smart Flow n'expose **aucune
action manuelle obligatoire** en v0 — c'est un réacteur à événements. Les seules surfaces humaines
prévues sont en lecture/consultation.

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Client final (canal notification) | Recevoir une offre de créneau, s'inscrire en liste d'attente élargie | `smart_flow.request_waitlist` *(⚠ HYPOTHÈSE, si un point d'entrée client existe — sinon l'inscription est faite par un agent)* |
| Agent d'accueil | Consulter les offres en cours, la liste d'attente, les snapshots d'affluence d'un établissement | `smart_flow.read` |
| Responsable établissement | Paramétrer la capacité (`smart_flow`) : politique d'ordre, fenêtre d'offre, seuils d'affluence | `smart_flow.configure` |
| Module (code serveur) | Publier/consommer les événements déclarés à son manifeste | — (interne, RG-PLAT-06) |

## 4. Comportements & règles

### 4.1 Frontière et activation

- **RG-SF-01 — Module transverse, jamais couplé en dur.** `App\SmartFlow` ne s'appelle jamais depuis
  `App\Reservation` ou `App\Acces`, et n'appelle jamais leurs services. Toute interaction passe par le
  bus (D2). Le module déclare son manifeste (`manifeste-module.md`) : `id: smart_flow`, `capability:
  smart_flow`, `dependencies: []` *(aucune dépendance dure — seulement des événements)*,
  `events_consumed`/`events_emitted` (§5), `permissions: ['smart_flow.read', 'smart_flow.configure']`.
- **RG-SF-02 — Aucune lecture cross-module.** Un abonné Smart Flow ne doit jamais charger une entité
  `App\Reservation\*` ou `App\Acces\*` pour compléter ce qu'un événement ne lui a pas donné (ce serait
  réintroduire le couplage que D2 interdit par la porte de derrière). Conséquence directe : le payload
  des événements consommés doit porter **tout le contexte nécessaire à l'appariement**. Aujourd'hui
  `booking.cancelled`/`booking.no_show` ne portent que `slotId` (+ montants/crédit) — **pas** la
  ressource, l'activité, ni les horaires du créneau. §5.3 propose l'extension additive nécessaire ;
  **⚠ HYPOTHÈSE / arbitrage A requis** avant `SF-2` : qui porte ce changement (Reservation/CQ-5 déjà
  livré, ou un lot dédié) et sous quelle étiquette.
- **RG-SF-03 — Priorité à la file native.** Sur `booking.cancelled`/`booking.no_show`, Smart Flow
  **suppose** que `PromotionListeAttenteHandler` a déjà eu l'occasion de promouvoir un candidat de
  `ListeAttente` (c'est le cas dans le code actuel, appelé avant la publication de l'événement). Une
  réaction Smart Flow qui, en relisant l'état, constaterait la place déjà reprise doit être un **no-op
  silencieux**, jamais une double offre. En v0, faute de lecture cross-module (RG-SF-02), ce constat
  s'appuie sur le payload : si l'événement futur venait à porter `seatsRemainingAfter` (§5.3), Smart
  Flow ne réagit que si `> 0`.
- **RG-SF-04 — Jamais de décision d'accès.** Le module ne modifie, ne lit en écriture, ni ne court-circuite
  jamais `JaugeFmi`/`ModeSeuil`. `FootfallSnapshot` est une projection **indépendante**, rejouable à
  partir du seul flux `access.recorded` — sa perte ou son retard n'a **aucun** effet sur le contrôle
  d'accès (RG-ACC-04 reste seul maître de la porte).
- **RG-SF-15 — Désactivation non destructive.** Désactiver `smart_flow` pour un établissement
  interrompt les nouvelles réactions (offres, snapshots) mais ne supprime aucune donnée existante
  (hérite RG-PLAT-09).

### 4.2 Créneaux libérés (slot recovery)

- **RG-SF-03bis — Émission de `slot.released`.** Sur `booking.cancelled` (branche libre **ou**
  tardive facturée — les deux libèrent la place, RG-M5-04/09 déjà actées) ou `booking.no_show`, si la
  place reste libre après la file native (RG-SF-03), Smart Flow publie `slot.released` (déjà au
  catalogue, `subject: Creneau`, `payload: { slot, resource }` — à préciser en `slotId`/`resourceId`
  string, §5.3).
- **RG-SF-06 — `slot.released` déclenche l'appariement waitlist.** Smart Flow, abonné à son propre
  `slot.released`, cherche la plus ancienne `WaitlistEntry` `active` du même établissement dont les
  critères (activité, type de ressource ou ressource, fenêtre de dates, capacité requise) couvrent le
  créneau libéré. Ordre par défaut : **FIFO par `createdAt`** — ⚠ **arbitrage A requis** (priorité par
  ancienneté client / abonnement / statut VIP envisageable, aucune règle de gestion ne le tranche
  aujourd'hui).

### 4.3 Liste d'attente élargie (`WaitlistEntry`)

- **RG-SF-05 — Objet et portée.** `WaitlistEntry` est une demande **par client**, **par
  établissement**, exprimée en critères (pas en `Creneau` unique) : c'est ce qui la distingue de
  `ListeAttente` (RG-M5-06, §0). Un client peut être simultanément inscrit sur une `ListeAttente` (créneau
  précis, complet au moment de sa demande) et une `WaitlistEntry` Smart Flow (n'importe quel autre
  créneau qui conviendrait) sans conflit — ce sont deux mécanismes indépendants qui peuvent l'un et
  l'autre lui trouver une place.
- **RG-SF-07 — Cycle de vie de l'offre (`SlotOffer`).** Un appariement (§4.2) crée un `SlotOffer` en
  statut `pending`, avec une fenêtre d'acceptation (`expiresAt`, défaut **⚠ HYPOTHÈSE non chiffrée par
  les sources — 15 minutes proposées par cohérence avec `PromotionListeAttenteHandler::
  DELAI_CONFIRMATION_MINUTES`**, paramétrable par établissement). `waitlist.entry_added` est publié à
  l'inscription, `slot.offered` à la création de l'offre.
- **RG-SF-08 — Politique d'offre.** Défaut proposé : **séquentielle** (une seule offre active à la
  fois par créneau libéré, au candidat le mieux classé) — cohérent avec RG-M5-06 et évite qu'un même
  siège soit promis deux fois. **⚠ arbitrage A requis** : une politique de diffusion (« broadcast » à
  plusieurs candidats, premier arrivé gagne) accélère l'occupation mais déplace le risque de
  sur-booking sur la garde de capacité de Réservation (`JaugeCreneauGuard`), qui reste la seule source
  de vérité — acceptable seulement si assumé explicitement.
- **RG-SF-09 — Détection de l'acceptation.** Smart Flow ne possède **aucun** point d'entrée API pour
  qu'un client « accepte » une offre en v0 (créerait une dépendance vers `App\Reservation` interdite
  par RG-SF-01/02, ou nécessiterait un nouvel endpoint côté Réservation, hors périmètre de ce lot). La
  détection proposée : le client réserve **normalement** via l'API Réservation existante ; Smart Flow,
  abonné à `booking.created`, **corrèle** `customerId` + `slotId` avec une `SlotOffer` `pending` du même
  établissement et, si elle correspond et que `expiresAt` n'est pas dépassé, la marque `accepted` et
  publie `slot.offer_accepted`. **⚠ arbitrage A requis** : cette corrélation implicite est simple et ne
  demande aucun changement à Réservation, mais elle ne distingue pas « le client a utilisé notre offre »
  de « le client a réservé au même moment par coïncidence » (faux positif rare mais possible, cf. §7) —
  l'alternative (lien de réservation tracé, token dans la notification) est plus fiable mais suppose un
  champ de traçabilité côté `Reservation` (hors périmètre SF-0/SF-2).
- Sur expiration (`expiresAt` dépassé sans `booking.created` correspondant), une tâche planifiée (même
  patron que `PromotionListeAttenteHandler::expirerPromotionsDepassees`) marque l'offre `expired`,
  publie `slot.offer_expired`, et relance RG-SF-06 pour le candidat suivant s'il en reste un et si le
  créneau n'a pas été repris entre-temps.

### 4.4 Affluence (footfall)

- **RG-SF-12 — Mesure a posteriori, jamais de régulation active en v0.** Sur `access.recorded`, Smart
  Flow incrémente/décrémente un `FootfallSnapshot` par zone (`accessAreaRef`, référence à
  `EspaceAcces.id`) et période (bucket configurable, défaut 15 minutes — ⚠ HYPOTHÈSE). Cette projection
  est **indépendante** de `JaugeFmi` (RG-SF-04) : mêmes entrées/sorties source, deux lectures
  distinctes, l'une temps réel et décisionnelle (Accès), l'autre a posteriori et analytique (Smart
  Flow). Un seuil paramétré par établissement peut déclencher `footfall.threshold_reached` — une
  **alerte**, jamais un blocage. **⚠ arbitrage A requis** : même l'alerte est-elle du périmètre v0, ou
  seule la mesure (snapshot consultable) l'est ? Le backlog dit « affluence » sans trancher mesure vs
  régulation.
- Ce capacité dépend intégralement de `access.recorded`, **non émis aujourd'hui** (§6, dépendance
  SF-1 non résolue) — sans cet émetteur, `FootfallSnapshot` reste vide, documenté comme tel plutôt que
  simulé (même principe que D27 pour le report : ne jamais laisser croire qu'une fonctionnalité marche
  quand son déclencheur n'existe pas).

### 4.5 Report (reschedule) — la boucle que D27 promet

- **RG-SF-10 — `booking.reschedule_requested` produit une offre prioritaire, hors file.** Sur réception,
  Smart Flow crée directement un `SlotOffer` (`candidateType: reschedule_request`,
  `sourceReservationRef` = `reservationRef` de l'événement, `customerId` = `customerId` de l'événement)
  — **sans** passer par `WaitlistEntry`, parce que ce client n'a exprimé aucun critère : il a droit à
  **un** report de la séance précise qu'il a manquée, pas à une inscription permanente. Le choix du
  créneau candidat (quel créneau proposer ?) est **hors périmètre de ce lot** : §9 pose la question
  ouverte (créneau suivant de même activité/ressource ? liste au client ? agent qui choisit ?) —
  ⚠ **arbitrage A requis**, c'est la pièce manquante pour que `SF-2` livre réellement le comportement
  promis par D27.
- Le reste du cycle (notification, fenêtre, expiration, détection d'acceptation) suit RG-SF-07/09
  identiquement à une offre issue de la file élargie.

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| `WaitlistEntry` | `id` | `Uuid` | PK | |
| | `establishment` | `Etablissement` (FK, noyau) | requis | cloisonnement (D3/D8) |
| | `customerId` | `string` (UUID) | requis | référence `Crm\Beneficiaire`, **pas** de FK (RG-SF-02 — le module ne dépend d'aucun autre module vertical) |
| | `resourceTypeCode` | `?string` | | reprend `Ressource.codeType` **par valeur**, jamais par FK |
| | `resourceId` | `?string` | | critère plus étroit, optionnel |
| | `activityId` | `?string` | | critère plus étroit, optionnel |
| | `windowStart` / `windowEnd` | `DateTimeImmutable` | `windowStart < windowEnd` | fenêtre de dates acceptable |
| | `participantCount` | `int` | `≥ 1`, défaut 1 | capacité minimale requise |
| | `status` | `WaitlistEntryStatus` | `active\|offered\|matched\|expired\|cancelled` | |
| | `createdAt` | `DateTimeImmutable` | | base de l'ordre FIFO (RG-SF-06) |
| `SlotOffer` | `id` | `Uuid` | PK | |
| | `establishment` | `Etablissement` (FK) | requis | |
| | `slotId` | `string` (UUID) | requis | référence `Reservation\Creneau.id`, **pas** de FK |
| | `candidateType` | `SlotOfferCandidateType` | `waitlist_entry\|reschedule_request` | |
| | `waitlistEntryRef` | `?Uuid` | FK interne à `WaitlistEntry` | `null` si `reschedule_request` |
| | `sourceReservationRef` | `?string` | | `null` si `waitlist_entry` ; traçabilité D27 sinon |
| | `customerId` | `string` (UUID) | requis | |
| | `status` | `SlotOfferStatus` | `pending\|accepted\|expired\|declined\|superseded` | |
| | `offeredAt` | `DateTimeImmutable` | | |
| | `expiresAt` | `DateTimeImmutable` | `> offeredAt` | défaut établissement (⚠ HYPOTHÈSE 15 min, RG-SF-07) |
| | `respondedAt` | `?DateTimeImmutable` | | horodatage `accepted`/`declined`/`expired` |
| `FootfallSnapshot` | `id` | `Uuid` | PK | |
| | `establishment` | `Etablissement` (FK) | requis | |
| | `accessAreaRef` | `string` (UUID) | requis | référence `Acces\EspaceAcces.id`, **pas** de FK |
| | `periodStart` / `periodEnd` | `DateTimeImmutable` | bucket fixe (défaut 15 min, ⚠ HYPOTHÈSE) | |
| | `entriesCount` / `exitsCount` | `int` | `≥ 0` | |
| | `netOccupancyAtEnd` | `int` | | solde en fin de bucket, propre à Smart Flow (RG-SF-04) |
| | `capacityThreshold` | `?int` | paramètre établissement | |
| | `thresholdReached` | `bool` | | pilote `footfall.threshold_reached` |

## 6. Événements — consommés, émis, à ajouter au catalogue

### 6.1 Déjà au catalogue, consommés par Smart Flow

| Événement | Émetteur réel (vérifié dans le code) | Statut d'émission | Usage Smart Flow |
|---|---|---|---|
| `booking.cancelled` | `AnnulerReservationProcessor` (`App\Reservation`) | **Émis** (les deux branches, libre et tardive) | §4.2 slot recovery |
| `booking.no_show` | `BasculerNoShowCommand` (`App\Reservation`) | **Émis** | §4.2 slot recovery |
| `booking.reschedule_requested` | `AnnulerReservationProcessor` + `BasculerNoShowCommand` (`App\Reservation`, RG-CQ5-08) | **Émis**, conditionnel à `IssueCreditNoShow::RestoredWithReschedule` | §4.5 report prioritaire |
| `access.recorded` | *(aucun — recherché dans `App\Acces\**`, aucune occurrence)* | **Non émis** — dépendance SF-1 non résolue | §4.4 footfall, **bloquant** tant que non livré |
| `booking.created` | `ReserverProcessor` (`App\Reservation`) *(à vérifier à l'implémentation — non exploré en détail ici, présumé émis puisque `booking.cancelled`/`no_show` le sont par le même module)* | présumé émis | §4.3 RG-SF-09, détection d'acceptation |

### 6.2 Déjà au catalogue, émis par Smart Flow

| Événement | Payload (catalogue actuel) | Usage |
|---|---|---|
| `slot.released` | `slot, resource` | §4.2 — à préciser en `slotId: string, resourceId: string` (scalaires, pas d'objets, RG-PLAT-04) |

### 6.3 Proposés — à ajouter au catalogue lors de `SF-2` (chaque ligne a un consommateur identifié, garde-fou n°6 respecté)

| Événement | Émis par | Payload minimal proposé | Consommateurs identifiés |
|---|---|---|---|
| `waitlist.entry_added` | Smart Flow | `entryId, customerId, resourceTypeCode?, activityId?, windowStart, windowEnd` | Reporting, CRM (signal d'intérêt) |
| `slot.offered` | Smart Flow | `offerId, slotId, candidateType, customerId, expiresAt` | Communication (notifier le client — service transverse §B du catalogue-modules, encore `~`), Reporting |
| `slot.offer_accepted` | Smart Flow | `offerId, slotId, customerId, candidateType` | Reporting (conversion), **Revenue Recovery** (suppression d'une relance en cours sur ce client — coordination utile, non bloquante) |
| `slot.offer_expired` | Smart Flow | `offerId, slotId, candidateType` | Reporting ; Smart Flow lui-même (relance RG-SF-06 sur le candidat suivant) |
| `footfall.threshold_reached` | Smart Flow | `accessAreaRef, periodStart, periodEnd, netOccupancyAtEnd, capacityThreshold` | Supervision/Communication (alerte staff), Reporting — **⚠ arbitrage A** : v0 ou v1 (§4.4) |

### 6.4 Extension additive proposée du payload existant (⚠ HYPOTHÈSE, arbitrage A requis avant `SF-2`)

Pour respecter RG-SF-02 (aucune lecture cross-module), `booking.cancelled` et `booking.no_show`
devraient porter, en plus de leurs champs actuels (préservés à l'identique — extension **additive**,
même discipline que `creditIssue`/`creditRestoredAmount` ajoutés par CQ-5) :

```
resourceId: string          // Creneau.ressource.id
resourceTypeCode: string    // Ressource.codeType
activityId: string | null   // Creneau.activite.id
slotStart: string (ISO8601) // Creneau.debut
slotEnd: string (ISO8601)   // Creneau.fin
seatsRemainingAfter: int    // capacité restante après cet événement, calculée par Reservation
```

Sans cette extension, Smart Flow ne peut apparier une `WaitlistEntry` (dont les critères portent sur
la ressource/l'activité/l'horaire) qu'en lisant directement `App\Reservation\Entity\Creneau` — ce que
RG-SF-02 interdit. C'est la pièce de contrat la plus importante que ce lot laisse ouverte.

## 7. Critères d'acceptation

- **CA-1** — *Étant donné* un établissement où `smart_flow` n'est pas activé, *quand* `booking.cancelled`
  est publié, *alors* aucun `SlotOffer` ni `FootfallSnapshot` n'est créé (RG-PLAT-08 côté Smart Flow).
- **CA-2** — *Étant donné* un `Creneau` complet avec un candidat `ListeAttente` `en_attente`, *quand*
  une réservation est annulée dans le délai franc, *alors* la promotion native (RG-M5-06) a lieu en
  premier et Smart Flow, en réagissant à `booking.cancelled` ensuite, **ne crée aucune offre** (place
  déjà reprise, RG-SF-03).
- **CA-3** — *Étant donné* un `Creneau` qui se libère sans aucun candidat `ListeAttente`, et une
  `WaitlistEntry` `active` dont les critères couvrent ce créneau, *quand* `slot.released` est publié,
  *alors* un `SlotOffer` `pending` est créé pour le candidat le plus ancien (FIFO, RG-SF-06) et
  `slot.offered` est publié.
- **CA-4** — *Étant donné* un `SlotOffer` `pending` et un `booking.created` ultérieur portant le même
  `customerId` et le même `slotId` avant `expiresAt`, *quand* Smart Flow le consomme, *alors* l'offre
  passe `accepted` et `slot.offer_accepted` est publié (RG-SF-09).
- **CA-5** — *Étant donné* un `SlotOffer` `pending` dont `expiresAt` est dépassé sans `booking.created`
  correspondant, *quand* la tâche planifiée d'expiration s'exécute, *alors* l'offre passe `expired`,
  `slot.offer_expired` est publié, et le candidat suivant (s'il existe et si le créneau est toujours
  libre) reçoit une nouvelle offre.
- **CA-6** — *Étant donné* `booking.reschedule_requested` publié pour une réservation dont l'issue de
  crédit est `RestoredWithReschedule`, *quand* Smart Flow le consomme, *alors* un `SlotOffer`
  `candidateType: reschedule_request` est créé, hors file d'attente, sans `WaitlistEntry` associée
  (RG-SF-10).
- **CA-7** — *Étant donné* `access.recorded` **non émis** (état actuel du code), *quand* on interroge
  `FootfallSnapshot` pour un établissement, *alors* la réponse est un état vide **documenté comme
  bloqué par une dépendance non livrée**, jamais une donnée simulée ou une erreur silencieuse (même
  discipline que D27 pour le report, §0).
- **CA-8** — *Étant donné* deux établissements A et B avec chacun une `WaitlistEntry` aux critères
  identiques, *quand* un créneau se libère chez A, *alors* seule la `WaitlistEntry` de A peut recevoir
  une offre (cloisonnement D3/D6, jamais de fuite cross-tenant).
- **CA-9** — *Étant donné* un même `booking.cancelled` publié deux fois (rejeu, synchronisation hors
  ligne différée), *quand* Smart Flow le consomme la seconde fois, *alors* aucune offre en double n'est
  créée (RG-SF-11, idempotence — clé proposée : `(establishmentId, slotId, candidateType,
  sourceReservationRef|waitlistEntryRef)` unique sur `SlotOffer` `pending`/`accepted`).
- **CA-10** — *Étant donné* le manifeste `App\SmartFlow`, *quand* la suite de tests s'exécute, *alors*
  chaque événement de `eventsEmitted()` figure au catalogue (RG-PLAT-06, même garde-fou que
  `ManifestCatalogueTest`).

## 8. Cas limites

- **Plus d'une place libérée, une seule offre active.** Politique séquentielle par défaut (RG-SF-08) :
  la deuxième place libérée par le même `booking.cancelled` (créneau à capacité > 1) déclenche une
  offre **distincte**, indépendante, potentiellement au même ou à un autre candidat — jamais fusionnée
  en une offre à deux places sans que `participantCount` de la `WaitlistEntry` le couvre.
- **Le créneau redevient complet entre l'offre et la tentative de réservation.** Si un tiers réserve la
  place entre-temps (hors Smart Flow), la réservation du candidat offert échouera côté Réservation
  (`JaugeCreneauGuard`, source de vérité) — Smart Flow ne le saura qu'à l'expiration de l'offre (pas de
  `booking.created` correspondant), et relance RG-SF-06 avec un léger retard. Assumé en v0 : la garde
  de capacité reste chez Réservation, jamais dupliquée chez Smart Flow (RG-SF-02).
- **Faux positif de corrélation `booking.created`.** Un client qui réserve indépendamment, par
  coïncidence, le créneau exact qu'on venait de lui offrir, sera compté comme ayant accepté l'offre
  (§4.3, RG-SF-09). Sans conséquence fonctionnelle grave (il a la place, l'offre est correctement
  soldée) mais fausse légèrement la mesure de conversion — signalé, pas corrigé en v0.
- **`WaitlistEntry` aux critères trop larges** (toute activité, toute ressource, fenêtre d'un an) :
  peut recevoir une offre sur un créneau qui ne l'intéresse pas vraiment. Pas de garde-fou v0 au-delà
  de rendre les critères obligatoires a minima (`windowStart`/`windowEnd`) — ⚠ HYPOTHÈSE, affinage
  laissé à `SF-2`.
- **Désactivation de `smart_flow` avec des `SlotOffer` `pending` en cours.** RG-SF-15 : aucune donnée
  supprimée, mais aucune notification ni expiration automatique ne doit continuer à s'exécuter — la
  tâche planifiée doit vérifier l'activation avant de traiter chaque établissement, pas une fois
  globalement au démarrage.
- **Réentrance du bus (D7).** `slot.released` → appariement → `slot.offered` → (Communication, future)
  ne doit jamais reboucler vers un événement déjà en cours de publication ; profondeur bornée à 8
  (`SymfonyEventBus::DEFAULT_MAX_DEPTH`) déjà garantie par le noyau, aucune action spécifique requise
  ici au-delà de ne pas republier un événement déclencheur depuis son propre traitement.
- **Volume de `access.recorded`.** Un établissement à fort trafic peut produire un flux dense de
  passages ; agréger un `FootfallSnapshot` par événement (plutôt que par lot) est simple mais peut être
  coûteux à l'échelle — question de performance explicitement **différée à `SF-2`**, non tranchée ici.

## 9. Questions ouvertes / arbitrages A (à trancher avant ou pendant `SF-2`)

1. **Ordre de la liste d'attente** (RG-SF-06) : FIFO strict, ou priorité (ancienneté client,
   abonnement, statut) ? Défaut proposé : FIFO.
2. **Politique d'offre** (RG-SF-08) : séquentielle (1 candidat à la fois, proposé par défaut) ou
   diffusion à plusieurs candidats simultanément ? La diffusion accélère l'occupation mais déplace le
   risque de sur-booking sur `JaugeCreneauGuard`.
3. **Détection de l'acceptation** (RG-SF-09) : corrélation implicite via `booking.created` (proposé,
   ne nécessite aucun changement à Réservation) vs lien de réservation tracé (plus fiable, suppose un
   nouveau champ côté `Reservation`, hors périmètre de ce lot).
4. **Quel créneau proposer sur un report** (RG-SF-10, D27) : le prochain créneau de même
   activité/ressource ? une liste au client ? un agent qui choisit manuellement ? **C'est la question
   qui manque le plus pour que `SF-2` livre le comportement promis par D27** — aucune règle de gestion
   ne la tranche aujourd'hui.
5. **Affluence : mesure seule ou alerte active en v0** (RG-SF-12, `footfall.threshold_reached`) ? Le
   backlog dit « affluence » sans trancher entre lecture et régulation.
6. **Retards « en cours de créneau »** (RG-SF-13, §2 exclu) : aucun événement source n'existe. Fait-il
   l'objet d'un nouveau `PREALABLE` (type SF-1-bis, un scan planifié analogue à
   `BasculerNoShowCommand` mais avant la fin du créneau) ? Question posée, non tranchée — pas
   d'événement inventé sans plan d'émetteur concret (garde-fou n°6).
7. **Revente à un public externe** (§2 exclu) : dépend du service transverse `Communication` (encore
   `~`) et d'un canal (app client, boutique en ligne) non identifié dans ce lot — reporté après `SF-2`.
8. **Extension du payload `booking.cancelled`/`booking.no_show`** (§6.4) : qui la porte, et
   sous quelle étiquette de tâche ? Bloquant pour tout appariement par critères (RG-SF-02/06).

## 10. Dépendances

- **Dépend de :**
  - `App\Platform` (bus d'événements, manifeste, RG-PLAT-01 à 09 — `specs/platform/spec-platform-core.md`).
  - `App\Fonctionnalite` (activation par établissement, `FonctionnaliteEtablissement`, même patron que
    les autres capacités du catalogue).
  - `App\Organisation\Entity\Etablissement` (tenant, noyau commun).
  - **`booking.cancelled`, `booking.no_show`, `booking.reschedule_requested`** — ✅ **déjà émis**,
    vérifié dans `App\Reservation\State\AnnulerReservationProcessor` et
    `App\Reservation\Command\BasculerNoShowCommand` (livré avec CQ-5, 24/08). La tâche `SF-1` telle que
    formulée aujourd'hui (`COORDINATION/TASKS.md`, toujours `CLAIM`) est donc **déjà à moitié faite** —
    à corriger/refermer partiellement lors de sa prise en charge.
  - **`access.recorded`** — ❌ **non émis**, aucune occurrence dans `App\Acces\**`. Dépendance **réelle
    et bloquante** pour la seule capacité affluence (§4.4). Reste le vrai périmètre de `SF-1`.
  - **Extension additive du payload `booking.cancelled`/`booking.no_show`** (§6.4) — non livrée, à
    trancher (§9 point 8) avant que l'appariement par critères (RG-SF-06) soit implémentable sans
    violer RG-SF-02.
  - Service transverse `Communication` (état `~`, catalogue-modules.md §B) pour un canal de
    notification réel — un adaptateur de repli (journalisation seule) est acceptable pour `SF-2`, même
    précédent que `NotificationReservationInterface` côté Réservation.
- **Débloque :**
  - `SF-2` (implémentation : `App\SmartFlow\**`).
  - Le comportement par défaut promis par D27 (no-show → crédit restitué **et** report réellement
    proposé) — actuellement dégradé, documenté comme incomplet plutôt que cassé.
  - Une coordination possible, non bloquante, avec Revenue Recovery (`slot.offer_accepted` peut
    éteindre une relance en cours sur le même client).

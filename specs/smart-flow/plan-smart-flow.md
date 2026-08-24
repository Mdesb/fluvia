# Plan technique — Smart Flow (`SF-0`, module `App\SmartFlow`)

- **Spec source :** specs/smart-flow/spec-smart-flow.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Périmètre :** claude-E. Module **greenfield**, branché sur `App\Reservation`/`App\Acces`
  **uniquement par le bus d'événements** (D2) — aucun fichier `App\Reservation\*`/`App\Acces\*` n'est
  modifié par ce plan.
- **Couvre :** RG-SF-01 à RG-SF-17 (aucune `US-SF-nn` chiffrée — module hors backlog initial, ⚠ à
  faire valider avant développement, cf. spec §0 note de statut).
- **Priorité imposée (ordre claude-A, 24/08) :** SF-2 (report de no-show, RG-SF-08..12) livré en
  premier — son déclencheur `booking.reschedule_requested` est déjà émis (CQ-5). Voir §0.1 découpage
  en incréments.

---

## 0. Décisions d'architecture

### 0.1 Découpage en incréments (ordre imposé, chacun livrable/testable seul)

| Incrément | Contenu | RG couvertes | Bloqué par |
|---|---|---|---|
| **I1 — Report de no-show (SF-2, prioritaire)** | Abonnement `booking.reschedule_requested`, `RescheduleProposal`, recherche de créneau compatible, notification, clôture (`accept`/`decline`) | RG-SF-08 à RG-SF-12, RG-SF-15/16/17 | Rien — déclencheur déjà en production |
| **I2 — Créneaux libérés + liste d'attente Smart Flow** | Abonnement `booking.cancelled`/`booking.no_show`, relecture de la disponibilité réelle, publication `slot.released` (idempotente), `SlotWaitlistEntry`, promotion FIFO | RG-SF-01 à RG-SF-07 | Rien — déclencheurs déjà en production ; **dépend techniquement de I1** pour le squelette du module (manifeste, extension de cloisonnement, port de notification) mais pas fonctionnellement |
| **I3 — Affluence (footfall)** | Abonnement `access.recorded`/`access.card_recharged`, `FootfallAggregate`, API de lecture | RG-SF-14 | **Bloqué** : `access.recorded` n'est pas émis (SF-1, hors périmètre claude-E). Le sous-abonné est écrit et testé (mock d'événement), mais ne produira aucun agrégat réel avant que `App\Acces` publie l'événement. Livré en dernier, cf. §7 point 1. |

Chaque incrément ajoute son propre jeu d'entités/migrations/tests sans toucher aux incréments
précédents — un déploiement partiel (I1 seul, par exemple) est un état stable, pas un état
intermédiaire fragile.

### 0.2 Namespace et rattachement du module

`App\SmartFlow\{Entity,Enum,Dto,Service,EventListener,Doctrine,Port,Security,State}`,
`App\SmartFlow\SmartFlowModule` (manifeste, racine — détail §0.11). Aucune entité, aucun repository,
aucun service de ce module n'est ajouté dans `App\Reservation`/`App\Acces`.

### 0.3 Lecture croisée avec `Reservation` (RG-SF-17) — constat et décision

**Constat vérifié dans le code (24/08) :** contrairement à ce que RG-SF-17 envisageait comme risque
(« si aucune route de lecture adéquate n'existe côté Reservation »), une route **existe déjà** :
`Creneau` et `Ressource` sont des `#[ApiResource]` complets avec `GetCollection`/`Get` sous
`security: "is_granted('PERM', 'reservation.lire')"`, filtrés en lecture par
`App\Reservation\Doctrine\PerimetreReservationExtension` (`app/src/Reservation/Entity/Creneau.php:35-77`,
`Ressource.php:32-42`). Le brief demandait, si NON, de ne rien écrire côté `Reservation` et de proposer
un couplage documenté ; ici c'est un OUI, donc rien à demander à l'intégrateur de ce côté.

**Le problème qui reste : le mécanisme d'appel depuis un abonné synchrone in-process.** Les
`EventListener` de Smart Flow s'exécutent **dans la même transaction PHP/Doctrine** que
`AnnulerReservationProcessor`/`BasculerNoShowCommand` (bus synchrone, D7). Passer par la pile HTTP
complète (sous-requête `HttpKernelInterface::handle()`, seule façon de « consommer » littéralement la
route API depuis du code serveur) n'a **aucun précédent** dans ce dépôt, ajoute une jointure
routing/sérialisation/désérialisation coûteuse pour une lecture interne, et risque des effets de bord
sur le token de sécurité courant et sur la transaction Doctrine déjà ouverte.

**Décision retenue (⚠ à confirmer par claude-A, déroge à la lettre de RG-SF-17) :** un service
anti-corruption local, `App\SmartFlow\Service\ReservationSlotReader`, lit **en lecture seule**
`Creneau`/`Ressource`/`Reservation` (Stock) directement via `EntityManagerInterface` — jamais
d'écriture, jamais de retour d'entité Doctrine hors de ce service (il rend des DTO immuables
`SlotSnapshot`/`ReservationSnapshot`, `App\SmartFlow\Dto`). Justification :
1. La donnée est **déjà exposée en lecture** au même niveau de permission (`reservation.lire`) par
   l'`ApiResource` existant — ce service ne crée aucune fuite de périmètre nouvelle, il évite juste le
   coût d'une sous-requête HTTP pour la même donnée.
2. Précédent direct dans ce dépôt : `plan-supplier-invoices.md` §0.2/§1 documente `App\Finance`
   référençant directement `Fournisseur`/`CommandeAchat`/`ArticleStock` (Stock) et `TauxTva`/
   `MoyenPaiement` (Compta) via relation Doctrine — la lecture croisée directe entre modules matures
   est déjà une pratique acceptée du dépôt, malgré D2.
3. `App\SmartFlow` ne **modifie jamais** rien dans `Reservation` par ce chemin (RG-SF-17 reste respecté
   sur l'écriture, qui est le risque que CQ-5 documente comme compromis assumé).

`ReservationSlotReader` revérifie systématiquement `establishment` (RG-SF-16) : un `slotId`/
`resourceId` qui ne résout à rien dans le même établissement que le `tenant` de l'événement en cours
est traité comme une donnée absente (retour `null`), jamais une exception — cohérent avec l'échec
fermé silencieux exigé par RG-SF-16 pour ne pas casser la transaction de l'émetteur.

**Alternative si claude-A refuse cette dérogation :** demander au propriétaire de `Reservation`
d'exposer un service de lecture dédié (`App\Reservation\Contract\SlotLookupInterface`, même signature
que `ReservationSlotReader`) dans un namespace neutre — déplacement mécanique, aucun changement de
logique, mais hors périmètre de ce plan (fichier dans `App\Reservation`).

### 0.4 Idempotence de `slot.released` (RG-SF-04)

`App\Platform\Event\DomainEvent` **ne porte pas d'identifiant propre** (vérifié : `name`, `occurredAt`,
`tenant`, `actor`, `subject`, `payload` — aucun `id`/`eventId`). La clé `(slotId, causeEventId)`
évoquée par la spec n'a donc pas de porteur technique littéral. Décision : la clé d'idempotence
devient **`(slotId, subject.type, subject.id)`** — `subject` porte déjà `{Reservation, <id>}` dans
`booking.cancelled`/`booking.no_show` (`EventSubject`), et chaque réservation ne déclenche ces
événements **qu'une fois** par construction (un seul point d'appel par branche, pas de nouvelle
tentative/redélivrance côté bus synchrone). Nouvelle table technique **`SlotReleaseTrace`** (§1),
contrainte `UNIQUE (slot_id, trigger_subject_id)` : avant de publier `slot.released`, le listener
tente un `INSERT` ; une violation de contrainte unique signale que cette libération précise a déjà été
traitée, et le traitement s'arrête silencieusement (pas de republication, pas d'exception). Ce
mécanisme protège aussi contre une future évolution du bus (D7 précise que l'asynchrone n'est « pas un
objectif de la v0 », pas « jamais ») sans dépendre d'un identifiant que l'enveloppe ne fournit pas
aujourd'hui.

### 0.5 Format du payload `slot.released` — écart avec le catalogue signalé

`catalogue-evenements.md:41` documente `slot.released` avec les colonnes `slot`, `resource` (objets),
alors que tous les événements déjà en production (`booking.cancelled`, `booking.no_show`) utilisent un
payload **plat** (`slotId`). Décision : ce plan émet un payload plat `{ slotId, resourceId }`,
cohérent avec l'existant et RG-PLAT-04 (références, pas de document imbriqué) — **⚠ à corriger au
catalogue partagé** (même remarque de forme que `plan-supplier-invoices.md` §7 point 2, à faire
remonter à l'intégrateur avant merge, pas une hypothèse à valider en silence).

### 0.6 Degré de tolérance « compatible » (RG-SF-09, §10 question 1)

Décision la plus simple, marquée ⚠ à confirmer par claude-A : un créneau est compatible s'il a
**même ressource OU même `codeType`** que le créneau d'origine (union, pas intersection — maximise le
taux de report réussi, cohérent avec l'objectif de D27). Configurable par établissement via
`settingsSchema()` (`toleranceLevel: same_resource | same_type`, défaut `same_type`) si claude-A
préfère resserrer par défaut.

### 0.7 Fenêtres/délais par défaut (§10 question 2) — rendus paramétrables dès ce lot

Plutôt que de figer des constantes PHP (comme `PromotionListeAttenteHandler::DELAI_CONFIRMATION_MINUTES`
côté `Reservation`), ce plan expose ces valeurs dans `SmartFlowModule::settingsSchema()` avec les
mêmes défauts que la spec (⚠ hypothèses non chiffrées par une source produit) :
`compatibleSlotSearchWindowDays` (14), `rescheduleProposalExpirationDays` (30),
`slotWaitlistPromotionExpirationMinutes` (15). Réponse directe à la question ouverte : oui,
paramétrable par établissement dès ce lot, pas seulement une constante de code.

### 0.8 Canal de notification client (RG-SF-10, §10 question 4)

Aucun port transverse de notification trouvé au-delà de `App\Reservation\Port\NotificationReservationInterface`
(propre à `Reservation`, non réutilisable sans coupler les deux modules). Décision (D19, même patron
que `NotificationReservationLogAdapter`) : Smart Flow définit son **propre port**,
`App\SmartFlow\Port\ClientNotificationInterface` (`notifierPropositionReport(RescheduleProposal)`,
`notifierPromotionListeAttente(SlotWaitlistEntry)`), implémenté par défaut par
`App\SmartFlow\Port\Adapter\ClientNotificationLogAdapter` (journalise uniquement). ⚠ À confirmer par
claude-A : si un port transverse `App\Notification` apparaît un jour, cette interface et celle de
`Reservation` en deviennent des consommateurs, pas des remplacements l'une de l'autre.

### 0.9 `POST .../accept` (§10 question 5) — fermeture, pas orchestration

Retenu : Smart Flow **n'appelle jamais** l'API d'écriture de `Reservation` (cohérent avec l'exclusion
explicite de la spec §2 — « jamais par un accès direct de Smart Flow dans Reservation »). Le client
crée sa nouvelle réservation par le chemin normal, `POST /reservation/reservations` (existant, non
modifié), **puis** appelle `POST /smart-flow/reschedule-proposals/{id}/accept` avec le corps
`{ confirmedReservationRef: <iri|uuid> }` pour **clore** la proposition. Le processor Smart Flow
revérifie, via `ReservationSlotReader` (§0.3, lecture seule), que la réservation référencée existe,
appartient au même établissement et au même `customerId` que la proposition — sinon 422 (IDOR, même
garde que partout ailleurs dans le dépôt). Aucune écriture dans `Reservation`. `POST .../decline` est
symétrique et ne prend aucun corps (statut → `expired` immédiat, RG-SF-12). ⚠ Choix d'orchestration, à
confirmer par claude-A — n'affecte aucun comportement observable côté client au-delà du nombre
d'appels API.

### 0.10 Cloisonnement (D3/D8) — un seul mécanisme, cohérent avec `Dms`/`Finance`

Toutes les entités de ce module portent un **`establishment` direct** (`ManyToOne Etablissement`,
comme `SupplierInvoice`/`Document`, pas seulement un `establishmentId` texte comme suggéré par la
notation informelle de la spec §5) : c'est l'ancre de cloisonnement **et** la source du tenant
d'événement pour les entités créées en réaction à un événement (D6 : dérivé de `EventTenant`, jamais
de `ContexteEtablissement`). `App\SmartFlow\Doctrine\PerimetreSmartFlowExtension` (copie stricte du
patron `DmsScopeExtension`/`PerimetreFinanceExtension`) protège toute lecture standard
(`GetCollection`/`Get`, y compris les endpoints `read: true` comme `/accept`/`/decline`).

**Cas particulier `RescheduleProposal` en lecture « own » (`smart_flow.reschedule_read_own`) :** en
plus du filtre établissement, `PerimetreSmartFlowExtension` ajoute, quand l'utilisateur connecté ne
porte **que** `smart_flow.reschedule_read_own` (pas `reschedule_manage`/`read`), une restriction
`customerId = :clientLie` (même lecture de `Utilisateur::getClientLie()` que
`ReserverProcessor::process()` pour la garde `reservation.reserver_soi`, réutilisée sans modification
de `Reservation`).

**Objets créés par action utilisateur** (`POST /smart-flow/waitlist-entries`,
`POST .../reschedule-proposals/{id}/accept|decline`) : `establishment` résolu depuis
`ContexteEtablissement::idActif()` (jamais un champ du corps), revérifié dans le périmètre de
l'appelant — même patron que tout `Processor` déjà en production (RG-SF-15).

### 0.11 Manifeste `ModuleManifest` (`App\SmartFlow\SmartFlowModule`)

- `id()` → `'smart_flow'`, `version()` → `'0.1.0'`.
- `capability()` → `null` (service réactif transverse : Smart Flow ne se vend pas, il réagit à des
  événements déjà produits par des modules payants, D22 « Smart Flow ne vend rien et ne facture rien
  lui-même » — ⚠ à trancher, alternative triviale documentée §7 point 6).
- `dependencies()` → `[]` (transitoire, §7 point 7 — `reservation`/`acces` n'implémentent pas encore
  `ModuleManifest`, les déclarer ferait échouer `ModuleRegistry::assertDependenciesAreResolved()`).
- `permissions()` → `['smart_flow.read', 'smart_flow.manage', 'smart_flow.reschedule_manage',
  'smart_flow.reschedule_read_own']` (§3).
- `eventsEmitted()` → `['slot.released']` (posé en I2 ; absent du manifeste tant que I2 n'est pas
  livré, cohérent avec « le manifeste est une déclaration de ce qui existe réellement »).
- `eventsConsumed()` → `['booking.reschedule_requested', 'booking.cancelled', 'booking.no_show',
  'access.recorded', 'access.card_recharged']` — les cinq figurent déjà au catalogue partagé (vérifié
  par la spec §0), donc `ManifestCatalogueTest::testLesEvenementsDeclaresFigurentAuCatalogue` passe dès
  I1 sans attendre que I3 devienne fonctionnel.
- `features()` → `['no_show_reschedule', 'slot_recovery', 'footfall_reporting']` — une feature par
  incrément (§0.1), pour permettre d'activer/masquer l'UI par incrément livré sans attendre les trois.
- `routes()` → `[]` (D13 : aucun écran dédié — modales ouvertes depuis l'espace de travail `Reservation`
  existant côté agent, et depuis la notification reçue côté client ; même choix qu'`OcrModule`).
- `settingsSchema()` → `compatibleSlotSearchWindowDays` (int, défaut 14),
  `rescheduleProposalExpirationDays` (int, défaut 30), `slotWaitlistPromotionExpirationMinutes`
  (int, défaut 15), `toleranceLevel` (enum `same_resource|same_type`, défaut `same_type`) — §0.6/§0.7.

Le manifeste est un fichier unique qui évolue au fil des incréments (T6 pose I1, T11 ajoute I2, T13
ajoute I3) — pas une déclaration figée à l'avance sur des fonctionnalités qui n'existent pas encore.

---

## 1. Entités & schéma

| Entité (`App\SmartFlow\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **`RescheduleProposal`** *(I1)* | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | index (`establishment`, `status`) | `Etablissement` (Organisation) — ancre D6/D8 |
| | originReservationRef | uuid | non | **unique** (idempotence consumer, §0.4 esprit) | référence, pas de relation Doctrine vers `Reservation` |
| | originSlotId | uuid | non | index | référence — résolue via `ReservationSlotReader` |
| | customerId | uuid | non | index | référence `Beneficiaire`/`Client` (Crm) — jamais résolue en relation Doctrine |
| | droitId | uuid | non | — | traçabilité crédit restitué (CQ-5) |
| | status | `string(10)` enum `RescheduleProposalStatus` | non | défaut `searching` | `searching`\|`proposed`\|`confirmed`\|`expired` (valeurs anglaises déjà D5-conformes dans la spec) |
| | proposedSlotId | uuid | **oui** | — | rempli seulement en statut `proposed` |
| | expiresAt | datetime_immutable | non | — | fenêtre globale (RG-SF-11), calculée à la création depuis `rescheduleProposalExpirationDays` |
| | confirmedReservationRef | uuid | **oui** | — | rempli à `accept` (§0.9) |
| | createdAt | datetime_immutable | non | — | — |
| | lastSearchAttemptAt | datetime_immutable | **oui** | — | horodatage de la dernière tentative de recherche (RG-SF-11, relance à chaque `slot.released` sur la même ressource) |
| **`SlotWaitlistEntry`** *(I2)* | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | index | `Etablissement` |
| | resourceId | uuid | non | index | référence `Ressource` (Reservation) — jamais de relation Doctrine |
| | beneficiaryId | uuid | non | — | référence `Beneficiaire` (Crm) |
| | searchWindowStart / searchWindowEnd | datetime_immutable | non | — | fenêtre demandée (RG-SF-05) |
| | rank | int (smallint) | non | — | FIFO (RG-SF-06), attribué à la création = `max(rank)+1` par `resourceId` |
| | status | `string(10)` enum `SlotWaitlistEntryStatus` | non | défaut `waiting` | `waiting`\|`promoted`\|`expired`\|`cancelled` |
| | promotedProposalRef | uuid | **oui** | — | vers une future proposition matérialisée à la promotion (même mécanique que `RescheduleProposal`, réutilisée : une promotion Smart Flow **est** une `RescheduleProposal` avec `originReservationRef` nul-substitut — voir note ci-dessous) |
| | createdAt | datetime_immutable | non | — | — |
| **`SlotReleaseTrace`** *(I2, technique, §0.4)* | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | index | `Etablissement` |
| | slotId | uuid | non | **unique composite** avec `triggerSubjectId` | référence `Creneau` |
| | resourceId | uuid | non | — | référence `Ressource` |
| | triggerEventName | `string(32)` | non | — | `booking.cancelled` \| `booking.no_show` |
| | triggerSubjectId | uuid | non | **unique composite** avec `slotId` | = `EventSubject.id` (id de la `Reservation` à l'origine) |
| | released | bool | non | défaut `false` | `true` si `slot.released` a effectivement été publié (peut être `false` si la liste d'attente **interne** à `Reservation` avait déjà repris la place, RG-SF-02/03) |
| | processedAt | datetime_immutable | non | — | — |
| **`FootfallAggregate`** *(I3, bloqué)* | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | **unique composite** avec `periodStart` | `Etablissement` — clé d'agrégation |
| | periodStart | datetime_immutable | non | — | tranche horaire (RG-SF-14, granularité heure — ⚠ hypothèse §0.7-like, non re-tranchée) |
| | grantedCount | int | non | défaut `0`, `>=0` | `access.recorded` avec `result = autorisé` |
| | deniedCount | int | non | défaut `0`, `>=0` | `access.recorded` avec `result = refusé` |
| | rechargeCount | int | non | défaut `0`, `>=0` | `access.card_recharged`, indicateur **distinct** (RG-SF-14, jamais fusionné) |
| | updatedAt | datetime_immutable | non | — | dernière écriture de l'agrégat (upsert) |

> id = UUID (`symfony/uid`). Rattachement multi-entités : `establishment` **direct** sur toutes les
> entités (ancre D6/D8, cohérent avec `Dms`/`Finance`, pas de dépendance à un `businessProfile`
> puisque Smart Flow n'a pas de notion comptable). Aucune relation Doctrine vers `App\Reservation`/
> `App\Acces`/`App\Crm` : toutes les références externes sont des colonnes `uuid` opaques (RG-SF-17,
> §0.3), résolues à la volée par `ReservationSlotReader` quand nécessaire, jamais persistées comme
> relation.

**Note de conception `SlotWaitlistEntry.promotedProposalRef` :** une promotion Smart Flow (RG-SF-07)
matérialise « une proposition avec délai d'expiration que le client confirme via le chemin normal de
réservation » — c'est exactement la définition de `RescheduleProposal`. Ce plan **réutilise**
`RescheduleProposal` comme le porteur unique de toute proposition de créneau adressée à un client,
qu'elle vienne d'un no-show reporté (I1, `originReservationRef` renseigné) ou d'une promotion de liste
d'attente Smart Flow (I2, `originReservationRef` **absent** — colonne rendue nullable ci-dessous,
corrige le tableau §5 de la spec qui la marquait `NOT NULL`, ⚠ à valider avec claude-A, aucun autre
champ affecté) — évite de dupliquer le statut/l'expiration/la notification/l'API `accept`/`decline`
dans une seconde entité.

**Correction au tableau §1 :** `RescheduleProposal.originReservationRef` doit donc être **nullable**,
avec la contrainte « au moins un de `originReservationRef` ou une inscription `SlotWaitlistEntry`
source doit être renseigné » validée applicativement (pas en base) — ajout d'une colonne
`sourceWaitlistEntryRef` (uuid, nullable) sur `RescheduleProposal` pour tracer l'origine liste
d'attente symétriquement à `originReservationRef`.

**Enums** (`App\SmartFlow\Enum`, valeurs anglaises D5, identiques à la spec) :
`RescheduleProposalStatus` (`Searching = 'searching'`, `Proposed = 'proposed'`,
`Confirmed = 'confirmed'`, `Expired = 'expired'`), `SlotWaitlistEntryStatus`
(`Waiting = 'waiting'`, `Promoted = 'promoted'`, `Expired = 'expired'`, `Cancelled = 'cancelled'`).

---

## 2. API (API Platform)

| Ressource / route | Opération | `security:` | Processor/Provider | Groupes sérialisation |
|---|---|---|---|---|
| `RescheduleProposal` | `GetCollection`, `Get` | `smart_flow.reschedule_manage or smart_flow.reschedule_read_own` | — (filtré par `PerimetreSmartFlowExtension`, §0.10) | `reschedule_proposal:read` |
| `RescheduleProposal` | `POST /smart-flow/reschedule-proposals/{id}/accept` | `smart_flow.reschedule_manage or smart_flow.reschedule_read_own` | `AcceptRescheduleProposalProcessor` (§0.9), `read:true`, `input:false` | out: `reschedule_proposal:read` |
| `RescheduleProposal` | `POST /smart-flow/reschedule-proposals/{id}/decline` | `smart_flow.reschedule_manage or smart_flow.reschedule_read_own` | `DeclineRescheduleProposalProcessor`, `read:true`, `input:false` | idem |
| `SlotWaitlistEntry` | `GetCollection`, `Get` | `smart_flow.read` | — (filtré) | `slot_waitlist_entry:read` |
| `SlotWaitlistEntry` | `POST /smart-flow/waitlist-entries` | `smart_flow.reschedule_manage` | `CreateSlotWaitlistEntryProcessor` (`read:false`, `input:false` — corps brut, `establishment` résolu serveur, §0.10) | in: `slot_waitlist_entry:write`, out: `slot_waitlist_entry:read` |
| `FootfallAggregate` *(I3, bloqué)* | `GetCollection` | `smart_flow.read` | — (filtré) | `footfall_aggregate:read` |

**Piège POST + `uriVariables` (rappel constitution/plateforme) :** `accept`/`decline` sont déclarées
avec `read: true` (elles opèrent sur une ressource existante identifiée par `{id}`, comme
`AnnulerCreneauProcessor`/`ArbitrerConflitRecurrenceProcessor` côté `Reservation` — même patron
exact). **`CreateSlotWaitlistEntryProcessor`** est au contraire `read: false` (création par corps
brut, pas d'`{id}` dans l'URI) : c'est le cas qui, mal déclaré, sortirait du filet de
`PerimetreSmartFlowExtension` (l'extension ne s'applique qu'aux providers standard, pas aux
`Processor` à corps brut) — d'où la revérification explicite d'établissement dans le processor lui-même
(§0.10), jamais une hypothèse silencieuse.

**Filtres** (`ApiFilter(SearchFilter::class, ...)`) : `RescheduleProposal` → `customerId` exact,
`status` exact ; `SlotWaitlistEntry` → `resourceId` exact, `status` exact ; `FootfallAggregate` →
`establishment` exact, `ApiFilter(DateFilter::class, properties: ['periodStart'])`.

**Non exposé par ce lot :** suppression d'aucune entité (aucun `Delete`) ; création manuelle d'une
`RescheduleProposal` par un agent (RG-SF-10 : « l'agent voit, ne crée pas manuellement dans ce lot »).

**Intégration `api_platform.yaml` — non modifiée par ce plan.** Comme pour `Finance` (précédent
signalé), l'intégrateur doit ajouter `src/SmartFlow/Entity` (et `.../Dto` si des DTO API Platform
hors-entité sont introduits) à `mapping.paths` — sans cela, aucune ressource de ce lot n'est
enregistrée.

---

## 3. Sécurité & droits

- **Permissions déclarées** (reprises telles quelles de la spec §3, ⚠ noms proposés par analogie,
  comme tous les modules déjà livrés, à arbitrer avec M8 avant figement) :
  `smart_flow.read`, `smart_flow.manage`, `smart_flow.reschedule_manage`,
  `smart_flow.reschedule_read_own`. **Mapping retenu** (non explicite dans la spec, tranché ici) :
  `POST /smart-flow/waitlist-entries` → `smart_flow.reschedule_manage` (action opérationnelle
  d'agent, même famille que la gestion de proposition), `smart_flow.manage` réservé au paramétrage
  d'établissement (§0.7, futur écran de configuration, hors périmètre de ce lot — aucune route
  `PATCH` de settings n'est ajoutée ici, `settingsSchema()` suffit au socle `App\Fonctionnalite`).
- **Voters :** aucun voter dédié — `PermissionVoter` existant suffit ; le filtrage de périmètre
  (établissement + « own » client) est porté par `PerimetreSmartFlowExtension` (§0.10), pas par un
  voter, cohérent avec `Dms`/`Finance`.
- **Cloisonnement — gardes explicites, échec fermé (404, jamais 403), résumé des points déjà détaillés
  en §0.10/§0.3 :**
  1. Tout listener d'événement (I1/I2/I3) dérive `establishment` du `tenant` de l'enveloppe (D6),
     jamais du contexte HTTP courant (qui n'existe pas dans ce contexte — le listener s'exécute dans
     la requête HTTP de l'**émetteur**, laquelle peut être une commande console sans `X-Etablissement`
     du tout, cf. `BasculerNoShowCommand`).
  2. `ReservationSlotReader::snapshotCreneau($slotId, EventTenant $tenant)` (et toute méthode
     équivalente) revérifie que `Creneau.etablissement === $tenant->establishmentId` avant de rendre
     un résultat non-null — RG-SF-16 littéral.
  3. `AcceptRescheduleProposalProcessor` revérifie `confirmedReservationRef` → même établissement
     **et** même `customerId` que la proposition (IDOR, §0.9).
  4. `CreateSlotWaitlistEntryProcessor` : `resourceId` fourni par le client → revérifié dans le
     périmètre actif via `ReservationSlotReader` avant persistance (sinon 422, donnée absente traitée
     comme invalide côté écriture utilisateur — différent du cas événementiel RG-SF-16 où l'absence
     est silencieuse, ici c'est une requête utilisateur donc l'erreur est explicite).
- **Aucun secret manipulé par ce lot.**

---

## 4. Migrations

Quatre migrations additives (I1 : 1 table ; I2 : 2 tables ; I3 : 1 table), `CREATE TABLE` uniquement,
aucune table existante modifiée :

- **I1 — `VersionYYYYMMDDHHMMSS_1`** — `CREATE TABLE smart_flow_reschedule_proposal` (`id BINARY(16)
  PK`, `establishment_id BINARY(16) NOT NULL FK → org_etablissement`,
  `origin_reservation_ref BINARY(16) NULL`, `source_waitlist_entry_ref BINARY(16) NULL`,
  `origin_slot_id BINARY(16) NOT NULL`, `customer_id BINARY(16) NOT NULL`,
  `droit_id BINARY(16) NOT NULL`, `status VARCHAR(10) NOT NULL DEFAULT 'searching'`,
  `proposed_slot_id BINARY(16) NULL`, `expires_at DATETIME NOT NULL`,
  `confirmed_reservation_ref BINARY(16) NULL`, `created_at DATETIME NOT NULL`,
  `last_search_attempt_at DATETIME NULL`) + `INDEX idx_reschedule_proposal_establishment_status
  (establishment_id, status)` + `UNIQUE INDEX uniq_reschedule_proposal_origin_reservation
  (origin_reservation_ref)` (index unique **partiel** en pratique : NULL n'entre pas en conflit sous
  MariaDB, plusieurs lignes `NULL` autorisées) + index sur `origin_slot_id`, `customer_id`.
- **I2 — `VersionYYYYMMDDHHMMSS_2`** — `CREATE TABLE smart_flow_slot_waitlist_entry` (`id BINARY(16)
  PK`, `establishment_id BINARY(16) NOT NULL FK → org_etablissement`, `resource_id BINARY(16) NOT
  NULL`, `beneficiary_id BINARY(16) NOT NULL`, `search_window_start DATETIME NOT NULL`,
  `search_window_end DATETIME NOT NULL`, `rank SMALLINT NOT NULL`, `status VARCHAR(10) NOT NULL
  DEFAULT 'waiting'`, `promoted_proposal_ref BINARY(16) NULL`, `created_at DATETIME NOT NULL`) +
  `INDEX idx_slot_waitlist_resource_status (resource_id, status)`.
- **I2 — `VersionYYYYMMDDHHMMSS_3`** — `CREATE TABLE smart_flow_slot_release_trace` (`id BINARY(16)
  PK`, `establishment_id BINARY(16) NOT NULL FK → org_etablissement`, `slot_id BINARY(16) NOT NULL`,
  `resource_id BINARY(16) NOT NULL`, `trigger_event_name VARCHAR(32) NOT NULL`,
  `trigger_subject_id BINARY(16) NOT NULL`, `released TINYINT(1) NOT NULL DEFAULT 0`,
  `processed_at DATETIME NOT NULL`) + `UNIQUE INDEX uniq_slot_release_trace_slot_trigger (slot_id,
  trigger_subject_id)` (§0.4, l'idempotence **est** cette contrainte).
- **I3 — `VersionYYYYMMDDHHMMSS_4`** — `CREATE TABLE smart_flow_footfall_aggregate` (`id BINARY(16)
  PK`, `establishment_id BINARY(16) NOT NULL FK → org_etablissement`, `period_start DATETIME NOT
  NULL`, `granted_count INT NOT NULL DEFAULT 0`, `denied_count INT NOT NULL DEFAULT 0`,
  `recharge_count INT NOT NULL DEFAULT 0`, `updated_at DATETIME NOT NULL`) + `UNIQUE INDEX
  uniq_footfall_establishment_period (establishment_id, period_start)` (support de l'upsert
  d'agrégation).

**Down** : les quatre migrations sont réversibles (`DROP TABLE`), rejouables (constitution §7). Aucune
donnée existante affectée.

---

## 5. Tests

`tests/SmartFlow/{Api,Unit}` (patron `tests/Dms`), `tests/Platform` pour le contrat (manifeste +
catalogue, déjà couvert par `ManifestCatalogueTest`, aucun nouveau test de contrat requis).

| Test | Type | Couvre |
|---|---|---|
| **`CloisonnementSmartFlowTest::testPropositionDunAutreEtablissementInvisible404`** | Fonctionnel API | D8 — un exploitant de l'établissement A ne voit pas les `RescheduleProposal`/`SlotWaitlistEntry` de l'établissement B (test de cloisonnement explicitement demandé par la mission) |
| `CloisonnementSmartFlowTest::testClientNeVoitQueSesPropositions` | Fonctionnel API | §0.10 — `smart_flow.reschedule_read_own` filtre par `customerId` |
| `CloisonnementSmartFlowTest::testInscriptionListeAttenteRessourceHorsPerimetreRefusee422` | Fonctionnel API | §3 point 4 — IDOR sur `resourceId` |
| **`RescheduleFlowEndToEndTest::testNoShowRestitueDeclencheReportJusquaConfirmation`** | Fonctionnel API (bout en bout) | I1 : simule `booking.reschedule_requested` (publié par un test-double de `BasculerNoShowCommand`/directement via `EventBus`), vérifie `RescheduleProposal` créée en `searching`, un `Creneau` compatible existant fait passer en `proposed`, `POST .../accept` avec une réservation créée via l'API `Reservation` existante clôt en `confirmed` — flux demandé explicitement par la mission |
| `RescheduleFlowEndToEndTest::testAucunCreneauCompatibleResteEnAttente` | Fonctionnel/Unit | RG-SF-11 : reste `searching`, pas d'échec silencieux |
| `RescheduleFlowEndToEndTest::testDeclineFermeImmediatement` | Fonctionnel API | RG-SF-12 |
| `RescheduleFlowEndToEndTest::testAcceptAvecReservationDunAutreClientRefuse422` | Fonctionnel API | §0.9 IDOR |
| `CompatibleSlotFinderTest::testMemeRessourceOuMemeCodeTypeDansLaFenetre` | Unit | RG-SF-09, §0.6 |
| `CompatibleSlotFinderTest::testCapaciteResiduelleNulleExclue` | Unit | RG-SF-09 |
| `SlotReleaseListenerTest::testBookingCancelledPubliesSlotReleasedSiPlaceRestante` | Unit/Fonctionnel | RG-SF-01/RG-SF-03 |
| `SlotReleaseListenerTest::testPromotionListeAttenteInterneDejaConsommeeNePubliePas` | Unit | RG-SF-02 — relit la disponibilité réelle plutôt que de supposer |
| **`SlotReleaseListenerTest::testIdempotenceMemeTriggerNePublieQuUneFois`** | Fonctionnel/Unit | RG-SF-04 — idempotence explicitement demandée par la mission, deux traitements du même `(slotId, subject.id)` (ex. rejeu manuel) → un seul `slot.released` |
| `SlotWaitlistPromotionTest::testPromotionFifoSurRessourceALaReceptionSlotReleased` | Unit | RG-SF-05/RG-SF-06 |
| `SlotWaitlistPromotionTest::testExpirationSansConfirmationTenteLInscriptionSuivante` | Unit | RG-SF-07 |
| `SmartFlowListenerBestEffortTest::testExceptionDansLeListenerNeCasseraJamaisLaTransactionEmetteur` | Unit | Best-effort explicitement demandé par la mission (D7) — un listener qui lève est capturé, journalisé, ne remonte pas |
| `FootfallAggregationTest::testAccessRecordedIncrementeGrantedOuDenied` *(I3)* | Unit | RG-SF-14, testé par événement simulé (pas par un flux `Acces` réel, bloqué) |
| `FootfallAggregationTest::testCardRechargedAlimenteUnCompteurDistinct` *(I3)* | Unit | RG-SF-14 — jamais fusionné |
| `SmartFlowModuleManifestTest::testManifestConstructibleSansArgumentEtPermissionsValides` | Unit | contrat `ModuleManifest` (patron `ManifestCatalogueTest`) |

---

## 6. Tâches (voir tasks-smart-flow.md)

**Incrément I1 (livré en premier) :**
- **T1** — Enums (`RescheduleProposalStatus`), entité `RescheduleProposal`, migration
  `..._1`, `App\SmartFlow\Dto\SlotSnapshot`/`ReservationSnapshot`,
  `App\SmartFlow\Service\ReservationSlotReader` (§0.3, lecture seule + revérification établissement).
- **T2** — `App\SmartFlow\Doctrine\PerimetreSmartFlowExtension` (§0.10) + `#[ApiResource]` lecture
  seule sur `RescheduleProposal` + tests de cloisonnement.
- **T3** — `App\SmartFlow\Service\CompatibleSlotFinder` (§0.6) + tests unitaires (dépend de T1).
- **T4** — `App\SmartFlow\Port\ClientNotificationInterface` + adaptateur log (§0.8) +
  `App\SmartFlow\EventListener\RescheduleRequestedListener` (abonné `booking.reschedule_requested`,
  crée la `RescheduleProposal`, tente `CompatibleSlotFinder`, notifie si `proposed`) + best-effort
  try/catch + tests (dépend de T1, T3, T4).
- **T5** — `AcceptRescheduleProposalProcessor`/`DeclineRescheduleProposalProcessor` (§0.9) + tests bout
  en bout (dépend de T2, T4).
- **T6** — `App\SmartFlow\SmartFlowModule implements ModuleManifest` (§0.11, version 0.1.0,
  permissions/événements de I1 seulement à ce stade — étendu en I2/I3) + `SmartFlowModuleManifestTest`.

**Incrément I2 :**
- **T7** — Enum `SlotWaitlistEntryStatus`, entités `SlotWaitlistEntry`/`SlotReleaseTrace`, migrations
  `..._2`/`..._3` + colonnes additionnelles sur `RescheduleProposal` (`sourceWaitlistEntryRef`) via une
  migration `ALTER TABLE` séparée.
- **T8** — `App\SmartFlow\EventListener\SlotFreedListener` (abonné `booking.cancelled`/
  `booking.no_show`) : relecture disponibilité réelle (RG-SF-02), écriture `SlotReleaseTrace`
  idempotente (§0.4), publication `slot.released` (§0.5) — tests d'idempotence explicitement requis.
- **T9** — `App\SmartFlow\EventListener\SlotReleasedListener` (auto-consommé, RG-SF-06) : promotion
  FIFO sur `SlotWaitlistEntry`, matérialisation d'une `RescheduleProposal` liée
  (`sourceWaitlistEntryRef`), expiration (RG-SF-07) — tâche planifiée `smart-flow:waitlist:expirer`
  (patron `reservation:no-show:basculer`).
- **T10** — `CreateSlotWaitlistEntryProcessor` + `#[ApiResource]` `SlotWaitlistEntry` + tests
  cloisonnement/IDOR.
- **T11** — Mise à jour `SmartFlowModule` (permissions/événements I2) + revue de cohérence.

**Incrément I3 (bloqué, dernier) :**
- **T12** — Entité `FootfallAggregate`, migration `..._4`, `App\SmartFlow\EventListener\FootfallListener`
  (abonné `access.recorded`/`access.card_recharged`, upsert par `(establishment, periodStart)`) + tests
  par événement simulé (aucun flux `Acces` réel disponible).
- **T13** — `#[ApiResource]` lecture `FootfallAggregate` + mise à jour finale `SmartFlowModule`.

**Transverse :**
- **T14** — Revue de cohérence (constitution §8) : `GET /health`, non-régression
  `App\Tests\Reservation\*`/`App\Tests\Acces\*` (ce lot ne modifie aucun fichier de ces modules),
  i18n des libellés de modale (D13, `smart_flow.*` clés), intégration `mapping.paths` signalée mais
  non faite (§2).

---

## 7. Risques / à valider

1. **I3 (affluence) est une coquille tant que `access.recorded` n'est pas émis** — conforme au
   constat déjà fait par D22 (`ProjectionAccesReservation` cité comme précédent du même mode de
   défaillance). Ce plan écrit et teste `FootfallListener` par événement simulé, mais aucun agrégat
   réel n'existera avant SF-1 (émission côté `App\Acces`, hors périmètre claude-E). Ne bloque pas I1/I2.
2. **Dérogation à RG-SF-17 (§0.3)** — lecture directe de `Creneau`/`Ressource`/`Reservation` par
   Doctrine plutôt que par la route API Platform existante. Point de coordination explicite à faire
   trancher par claude-A avant merge (deux options équivalentes en sécurité, différentes en complexité
   d'implémentation, cf. §0.3).
3. **Écart catalogue `slot.released`** (§0.5) — colonnes `slot`/`resource` (objets) documentées, payload
   plat `slotId`/`resourceId` retenu. À corriger dans `catalogue-evenements.md` avant qu'un futur agent
   implémente un consommateur sur la forme actuellement écrite.
4. **`SlotWaitlistEntry`/`RescheduleProposal` fusion partielle** (§1, note de conception) — ce plan fait
   porter toute proposition de créneau (no-show reporté **et** promotion de liste d'attente Smart Flow)
   par la même entité `RescheduleProposal`, ce qui rend `originReservationRef` nullable contrairement au
   tableau §5 de la spec. Changement de contrainte, pas de comportement — à faire acter dans
   `spec-smart-flow.md` avant merge (même traitement que le renommage `correctedBy→correctsInvoice`
   documenté par FIN-2).
5. **Listener best-effort et perte silencieuse (§0.10, D7)** — un `RescheduleRequestedListener` qui
   échoue (log seul, jamais de propagation) signifie qu'un no-show avec crédit restitué **ne produit
   aucune proposition**, sans alerte visible ailleurs qu'un log. Étant donné que D27 qualifie ce
   comportement de « moitié du défaut plateforme sur le no-show », une supervision (alerte sur log
   d'échec `smart_flow.listener.failed`) est recommandée dès la mise en production — hors périmètre
   strict de ce plan (observabilité transverse), signalé pour arbitrage.
6. **`SmartFlowModule::capability()`** — aucun code `CapaciteCode` existant ne correspond à Smart Flow
   (`App\Fonctionnalite\Enum\CapaciteCode` ne liste ni `finance` ni `smart_flow` aujourd'hui — `finance`
   y est absent malgré `FinanceModule::capability(): 'finance'`, donc précédent déjà accepté d'un code
   déclaré avant catalogue). Ce plan retient **`null`** (service réactif transverse, cohérent avec
   D22 : « Smart Flow ne vend rien »), alternative triviale si claude-A préfère un module vendable :
   ajouter `CapaciteCode::SmartFlow` (une ligne, sans migration, le champ est une colonne texte libre).
   À trancher.
7. **`SmartFlowModule::dependencies()`** — comme `FinanceModule` (même risque déjà documenté par
   `plan-supplier-invoices.md` §7 point 7), `reservation`/`acces` n'implémentent pas encore
   `ModuleManifest` : `dependencies(): []` retenu ici aussi, transitoire, à revoir avec le rétrofit
   groupé des modules legacy.
8. **Fenêtres/délais par défaut non chiffrés par une source produit** (§0.7, §10 question 2 de la spec)
   — rendus paramétrables par établissement plutôt que figés en dur, mais les valeurs par défaut
   (14 j / 30 j / 15 min) restent des hypothèses d'architecture, pas des décisions produit validées.
9. **Retards (RG-SF-13)** — non couvert par ce plan, conformément à la spec (§4.4) : aucune donnée
   fiable n'existe avant SF-1. Rien à faire ici au-delà du constat déjà écrit par la spec.

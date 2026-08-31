# Plan technique — Revenue Recovery (`RR-0`)

- **Spec source :** specs/revenue-recovery/spec-revenue-recovery.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Contrat de plateforme :** `COORDINATION/CONTRACT/manifeste-module.md`, `COORDINATION/CONTRACT/catalogue-evenements.md`
  (5 événements `revenue_recovery.*` **absents à ce jour** — à ajouter avant implémentation, §8),
  `COORDINATION/CONTRACT/noyau-commun.md` (cloisonnement échec fermé), `App\Platform\Event`
  (`DomainEvent`/`EventBus`/`EventTenant`/`EventSubject`/`EventName`), `App\Platform\Module`
  (`ModuleManifest`)
- **Couvre :** US-RR-01 à US-RR-07 · RG-RR-01 à RG-RR-09 · CA associés (§4 de la spec)

> **Statut de la spec source : brouillon**, quatre arbitrages ouverts posés à claude-A (§0.5/§12 de la
> spec). Ce plan **procède sur la voie recommandée par la spec** pour chacun, marqué « ⚠ à confirmer par
> claude-A », sans bloquer la production du plan — même méthode que le plan Smart Flow.

---

## 0. Décisions d'architecture

### 0.1 Nouveau module `App\RevenueRecovery`, id de manifeste `revenue_recovery` — ⚠ à confirmer par claude-A

Reprend intégralement la recommandation §0.3 de la spec : module neuf, 100 % anglais (D5), namespace
`App\RevenueRecovery\{Entity,Enum,Dto,Service,State,Doctrine}` + `App\RevenueRecovery\RevenueRecoveryModule`
(manifeste, racine du module — même position que `App\Dms\DmsModule`/`App\Finance\FinanceModule`).
`App\Recouvrement` n'est **ni étendu ni modifié** par ce lot : aucune ligne de code n'y est ajoutée,
seul le **patron de conception** est répliqué (D2, aucun import cross-module) :

| Patron `Recouvrement` (lu, jamais importé) | Réplique `RevenueRecovery` |
|---|---|
| `PolitiqueRecouvrement` (politique par établissement) | `RecoverySequence` (politique par établissement × type de déclencheur) |
| `MoteurRecouvrementHandler` (machine à états) | `RecoveryEngine` |
| `Doctrine\PerimetreRecouvrementExtension` (cloisonnement, carte statique `CHAINES`) | `Doctrine\PerimetreRevenueRecoveryExtension` |
| `ResolutionImpayeHandler::forcerReouverture()` (motif obligatoire, RG-SOCLE-07) | `RecoveryEngine::stopManually()` (motif obligatoire, RG-RR-05) |

**Aucune association Doctrine** entre les deux modules (spec §7) : le seul lien est le nom d'événement
partagé (`payment.failed`, `payment.incident_reopened`), deux abonnés indépendants réagissant au même
fait — jamais un appel de code à code.

### 0.2 Invariant central RG-RR-06 : `RevenueRecovery` n'écrit jamais sur `DroitAcces` — ⚠ à confirmer par claude-A

`App\Recouvrement\Service\PropagationAccesHandler` se déclare (commentaire de code) « seul point
d'écriture du moteur de recouvrement sur `DroitAcces.statutProjection` ». Ce plan ne référence, n'injecte
ni n'appelle **aucun** service du domaine accès (`App\Securite\Entity\DroitAcces`,
`PropagationAccesHandler` ou équivalent) : c'est vérifiable par grep négatif à la revue (aucune classe
`App\RevenueRecovery\*` n'importe `DroitAcces`). Test dédié §5 (`RG-RR-06`).

### 0.3 Découpage en incréments — imposé par l'état réel des déclencheurs (constat spec §6/§10)

**I1 (livrable maintenant, périmètre de ce plan) :** moteur générique complet (entités, séquences
paramétrables, cloisonnement, RGPD, dashboard) câblé sur les **quatre déclencheurs déjà émis** au
24/08 :
- `booking.cancelled` (`AnnulerReservationProcessor`, `App\Reservation`)
- `booking.no_show` (`BasculerNoShowCommand`, `App\Reservation`)
- `payment.failed` (via `LegacyEventBridge`, pont transitoire — RR s'abonne au **nom de contrat**,
  jamais au pont)
- `payment.incident_reopened` (idem)

**I2 (hors périmètre claude-E, bloqué par RR-1) :** `cart.abandoned`, `invoice.overdue`,
`quote.sent`/`quote.expired`, `customer.inactive` — non émis par `Boutique`/`Facturation`/`Devis`/`Crm`
à ce jour. Le sous-abonné correspondant (`RevenueRecoveryEventSubscriber::onCartAbandoned()` etc.) est
**écrit et testé par événement simulé** (le test construit un `DomainEvent` à la main et le publie sur
le bus, sans dépendre d'une émission réelle côté Boutique/Facturation), mais reste **inerte en
production** tant que RR-1 (porté par l'intégrateur, D22) n'a pas livré l'émission réelle. Chaque
`RecoverySequence` pour un déclencheur d'I2 reste configurable en base (RG-RR-02 : inactive tant que rien
n'est paramétré de toute façon), simplement jamais déclenchée avant RR-1.

Le moteur (`RecoveryEngine`), les entités, l'API et le cloisonnement sont **communs** aux deux
incréments — aucune divergence structurelle entre I1 et I2, seule la présence réelle de l'émetteur change.

### 0.4 Doublon `RelancePanierExpireMailer` — documenté, non traité par ce lot — ⚠ à confirmer par claude-A

`App\Boutique\Notification\RelancePanierExpireMailer` (déclenché par
`LibererPaniersExpiresCommand`/`boutique:liberer-paniers-expires`) envoie déjà une relance e-mail unique,
non paramétrable, pour panier abandonné (`PanierEnLigne.relanceEnvoyee`, idempotent). Ce plan **ne
supprime pas ce mailer** (hors périmètre : `App\Boutique`) et **ne branche pas** `cart.abandoned` (I2,
bloqué par RR-1 de toute façon). Il documente, comme la spec, que RR-1 devra trancher entre (a)
remplacement, (b) coexistence assumée (double e-mail), ou (c) internalisation comme premier
`RecoveryStep` par défaut de la séquence « panier abandonné » — **avant** que `cart.abandoned` soit
émis et qu'une `RecoverySequence` I2 devienne active sur ce déclencheur.

### 0.5 Inactif par défaut (RG-RR-02) — ⚠ à confirmer par claude-A

Contrairement à `Recouvrement` (qui applique une politique par défaut faute de paramétrage explicite —
protection d'un revenu déjà engagé), `RevenueRecovery` ne crée **aucun** `RecoveryCase` pour un couple
(établissement, type de déclencheur) sans `RecoverySequence` active correspondante (RG-RR-02, CA-2 de
US-RR-02). `RecoveryEngine::handle()` (point d'entrée unique de tout abonné d'événement) implémente ce
garde-fou une seule fois, pas dupliqué par déclencheur.

### 0.6 Cloisonnement (D3/D8) — même patron que `Recouvrement`/Stock, deux mécanismes complémentaires

1. **Lecture** (`GetCollection`/`Get`, tout `read: true`) : `App\RevenueRecovery\Doctrine\PerimetreRevenueRecoveryExtension
   implements QueryCollectionExtensionInterface, QueryItemExtensionInterface` — copie stricte du patron
   `PerimetreRecouvrementExtension` (jointure jusqu'à `.establishment` + `Affectation` de l'utilisateur
   courant). Chaînes : `RecoverySequence => []`, `RecoveryCase => []`, `RecoveryAttempt => ['recoveryCase']`.
2. **Écriture par corps brut** (`read: false` — création de `RecoverySequence`, arrêt manuel d'un
   `RecoveryCase`) : revérification explicite de l'établissement dans le processor, échec fermé 404
   (jamais 403, cohérent noyau commun #2 — ne pas révéler l'existence hors périmètre).
3. **RG-RR-07** — toute entité résolue depuis un identifiant client (`RecoveryCase`, `RecoverySequence`)
   revérifie son établissement à **chaque accès**, pas seulement à la création — cas limite §11 de la
   spec (« un client change d'établissement de rattachement entre l'ouverture du dossier et l'envoi
   d'une tentative » → revérification à **chaque envoi**, pas seulement à la programmation).

### 0.7 Le déclenchement automatique n'est pas un `EventSubscriber` par déclencheur mais un point d'entrée unique

`App\RevenueRecovery\EventListener\RevenueRecoveryEventSubscriber implements EventSubscriberInterface`
s'abonne (par nom de chaîne du dispatcher Symfony, patron `LegacyEventBridge`/`SymfonyEventBus` : le bus
dispatche `DomainEvent` sous l'événement `$event->name->value`) aux huit noms consommés du catalogue
(quatre I1 branchés réellement, quatre I2 écrits mais inertes) et délègue systématiquement à
`RecoveryEngine::handle(DomainEvent $event, string $triggerType, ?int $amountCents, string $subjectRef)` —
un seul point qui applique RG-RR-02 (pas de séquence → rien), résout le `RecoverySequence` actif, ouvre le
`RecoveryCase`, programme la première `RecoveryAttempt`. **Best-effort obligatoire (D7)** : le bus est
synchrone et propage les exceptions à l'émetteur (RG-PLAT-05, ex. `AnnulerReservationProcessor`) — chaque
méthode de l'abonné capture ses propres erreurs et journalise (même patron que `LegacyEventBridge::publierEnveloppe()`),
jamais de propagation vers l'action métier d'origine.

La **résolution automatique** (RG-RR-04) suit le même principe : un second sous-ensemble de noms consommés
(`payment.succeeded`, `quote.accepted`, `sale.completed`, `booking.created` — table de correspondance
déclencheur → événement de résolution, §6 spec) route vers `RecoveryEngine::resolve(string $subjectRef,
string $triggerType)`, qui clôt tout `RecoveryCase` actif correspondant (`Active → Resolved`), annule les
`RecoveryAttempt` `Pending` restantes.

### 0.8 Corrélation sujet/déclencheur — `subjectType`/`subjectRef` opaques, même patron que `typeRedevable`/`referenceRedevable`

`RecoveryCase.subjectType` (ex. `Reservation`, `PaymentIncident`) et `subjectRef` (l'`id` de
`EventSubject`) sont dérivés **directement de l'enveloppe `DomainEvent`** (`$event->subject->type`,
`$event->subject->id`) — aucune résolution vers l'entité métier d'origine n'est nécessaire pour ouvrir
le dossier (RR ne connaît pas `Reservation`, `PaymentIncident`, etc., et ne les importe pas, D2). La
résolution automatique (§0.7) recherche un `RecoveryCase` actif par `(establishment, subjectType,
subjectRef)` — pas de FK Doctrine vers l'entité d'origine.

**⚠ HYPOTHÈSE reprise de la spec §11 (idempotence)** — un seul `RecoveryCase` actif à la fois par
`(establishment, triggerEvent, subjectRef)` : une seconde occurrence du même déclencheur pour le même
sujet pendant qu'un dossier est déjà `Active` ne rouvre pas un second dossier (contrainte applicative dans
`RecoveryEngine::handle()`, pas une contrainte d'unicité SQL — un `Resolved`/`Stopped`/`Exhausted`
antérieur ne bloque rien).

### 0.9 Événements produits — tenant dérivé de `RecoveryCase.establishment` (D6), jamais du contexte HTTP

Les cinq événements `revenue_recovery.*` (§8) sont émis par `RecoveryEngine`, **jamais** depuis
`ContexteEtablissement::idActif()` (RG-RR-09) — cohérent avec l'exigence D6 déjà appliquée par
`App\Finance\SupplierInvoice` et `App\Reservation`. Émis en best-effort côté `RevenueRecoveryEventSubscriber`
(capturés), mais **propagés normalement** côté `RecoveryEngine` lui-même quand ils sont le fait générateur
direct d'une commande synchrone (ex. arrêt manuel via l'API) — seule la réception d'un événement externe
est protégée en best-effort, pas l'émission depuis une action HTTP volontaire.

---

## 1. Entités & schéma

| Entité (`App\RevenueRecovery\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **`RecoverySequence`** | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | **unique** (`establishment`, `triggerType`) | `Etablissement` (Organisation, socle) — ancre D6/D8 |
| | triggerType | `string(32)` enum `RecoveryTriggerType` | non | — | `cart_abandoned`\|`invoice_overdue`\|`booking_cancelled`\|`booking_no_show`\|`payment_failed`\|`payment_incident_reopened`\|`quote_expired`\|`customer_inactive` |
| | active | `bool` | non | défaut `false` | RG-RR-02 : inactif tant que non explicitement activé |
| | maxAttempts | `smallint` | non | `> 0`, défaut `3` | RG-RR-08, analogue `nbRepresentationsMax` |
| | steps | `json` | non | — | liste ordonnée `{ delayDays: int, channel: 'email', templateCode: string }` — RG-RR-08, analogue `calendrierRepresentationJours` |
| | createdAt / updatedAt | `datetime_immutable` | non | — | — |
| **`RecoveryCase`** | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | index (`establishment`, `status`) | `Etablissement` — ancre D6/D8 |
| | triggerType | `string(32)` enum `RecoveryTriggerType` | non | index | même enum que `RecoverySequence` |
| | subjectType | `string(32)` | non | — | `EventSubject.type` d'origine, opaque (§0.8) |
| | subjectRef | `string(64)` | non | index (`establishment`, `triggerType`, `subjectType`, `subjectRef`) | `EventSubject.id` d'origine, opaque |
| | amountCents | `int` | **oui** | — | pas toujours une dette (spec §7) — `null` pour panier/devis/no-show sans montant à risque connu |
| | status | `string(16)` enum `RecoveryCaseStatus` | non | défaut `active` | `active`\|`resolved`\|`stopped`\|`exhausted` |
| | sequence | uuid (FK) | non | — | `RecoverySequence` appliquée à l'ouverture (figée, un changement de séquence n'affecte pas un dossier déjà ouvert) |
| | openedAt | `datetime_immutable` | non | — | — |
| | resolvedAt / stoppedAt | `datetime_immutable` | **oui** | — | — |
| | stopReason | `text` | **oui** | requis si `status = stopped` (RG-RR-05) | — |
| | stoppedBy | uuid (FK) | **oui** | — | `Utilisateur` (Securite) |
| **`RecoveryAttempt`** | id | uuid | non | PK | — |
| | recoveryCase | uuid (FK) | non | index | `RecoveryCase`, `inversedBy: attempts` |
| | stepIndex | `smallint` | non | — | position dans `RecoverySequence.steps` |
| | scheduledAt | `datetime_immutable` | non | — | — |
| | sentAt | `datetime_immutable` | **oui** | — | — |
| | channel | `string(8)` enum `RecoveryChannel` | non | défaut `email` | `email` seul en v1 (§9 spec, SMS hors périmètre RR-0) |
| | status | `string(10)` enum `RecoveryAttemptStatus` | non | défaut `pending` | `pending`\|`sent`\|`skipped`\|`failed`\|`cancelled` |
| | skipReason | `string(32)` | **oui** | — | ex. `skipped_no_consent` (RG-RR-03) |

> id = UUID (`symfony/uid`). Rattachement multi-entités : `establishment` **direct** sur
> `RecoverySequence`/`RecoveryCase` (ancre de cloisonnement, D6/D8, même choix que `SupplierInvoice`) ;
> `RecoveryAttempt` hérite du périmètre via `recoveryCase` (chaîne de jointure §0.6). Aucune entité ne
> référence `App\Crm\Entity\Consentement` par FK Doctrine : le consentement est **relu** à l'exécution
> via `ConsentementRepository`/le couple `(client, canal)` connu par ailleurs (App\Crm reste seul
> propriétaire du registre — lecture seule, spec §10).

**Enums** (`App\RevenueRecovery\Enum`, valeurs anglaises D5) :
- `RecoveryTriggerType` : `CartAbandoned = 'cart_abandoned'`, `InvoiceOverdue = 'invoice_overdue'`,
  `BookingCancelled = 'booking_cancelled'`, `BookingNoShow = 'booking_no_show'`,
  `PaymentFailed = 'payment_failed'`, `PaymentIncidentReopened = 'payment_incident_reopened'`,
  `QuoteExpired = 'quote_expired'`, `CustomerInactive = 'customer_inactive'`.
- `RecoveryCaseStatus` : `Active = 'active'`, `Resolved = 'resolved'`, `Stopped = 'stopped'`,
  `Exhausted = 'exhausted'`.
- `RecoveryChannel` : `Email = 'email'` (seule valeur v1).
- `RecoveryAttemptStatus` : `Pending = 'pending'`, `Sent = 'sent'`, `Skipped = 'skipped'`,
  `Failed = 'failed'`, `Cancelled = 'cancelled'`.

---

## 2. API (API Platform)

| Ressource / route | Opération | `security:` | Processor/Provider | Groupes sérialisation |
|---|---|---|---|---|
| `RecoverySequence` | `GetCollection`, `Get` | `revenue_recovery.read` | — (filtré par `PerimetreRevenueRecoveryExtension`) | `recovery_sequence:read` |
| `RecoverySequence` | `POST /revenue-recovery/sequences` | `revenue_recovery.configure` | `RecoverySequenceProcessor` (revérifie `establishment`, rejette si une séquence existe déjà pour `(establishment, triggerType)` — 409, RG-RR-01) | in: `:write`, out: `:read` |
| `RecoverySequence` | `PATCH /revenue-recovery/sequences/{id}` | `revenue_recovery.configure` | `RecoverySequenceProcessor` | idem |
| `RecoveryCase` | `GetCollection`, `Get` | `revenue_recovery.read` | — (filtré, dashboard §8 spec) | `recovery_case:read` |
| `RecoveryCase` | `POST /revenue-recovery/cases/{id}/stop` | `revenue_recovery.manage` | `StopRecoveryCaseProcessor` → `RecoveryEngine::stopManually()`, corps `{ reason }` (422 si vide, CA-2 US-RR-05), `read: true`, `input: false` | out: `recovery_case:read` |
| `RecoveryAttempt` | `GetCollection` | `revenue_recovery.read` | — (filtré via `recoveryCase`) | `recovery_attempt:read` |

**Piège POST+uriVariables→`read:false` (D8, déjà rencontré sur d'autres lots) :** `StopRecoveryCaseProcessor`
utilise `read: true` (pas `read: false`) précisément pour que la résolution de `{id}` passe par le provider
d'item standard, donc par `PerimetreRevenueRecoveryExtension` — cohérent avec le choix déjà documenté par
`plan-supplier-invoices.md` §0.2 pt.1 pour `/approve` etc. `RecoverySequenceProcessor` (création, corps
brut `POST /revenue-recovery/sequences`), lui, est `read: false` par nature (pas d'`{id}` en entrée) : la
revérification d'établissement y est **explicite dans le processor**, jamais couverte par l'extension
Doctrine — piège classique D8 si oublié.

**Filtres** (`ApiFilter(SearchFilter::class, ...)`) : `RecoverySequence` → `triggerType` exact, `active`
exact ; `RecoveryCase` → `status` exact, `triggerType` exact ; `RecoveryAttempt` → `recoveryCase` exact.

**Échec fermé cloisonnement (404, jamais 403)** : un `establishment` hors périmètre dans le corps de
création d'une `RecoverySequence`, ou un `{id}` de `RecoveryCase` hors périmètre sur `/stop`, renvoient
404 « introuvable » — jamais 403 qui révélerait l'existence hors périmètre (noyau commun #2, même choix
que FIN-2 §3).

**Non exposé par ce lot** : aucune route publique/tunnel (spec §8 — back-office exploitant uniquement) ;
aucune suppression de `RecoveryCase`/`RecoveryAttempt` (traçabilité append-only, utile en cas de
réclamation RGPD) ; le tableau de bord de taux de conversion (agrégats) est un `Provider` dédié non détaillé
ici (candidat naturel de suivi, hors coeur du moteur — cf. §7 risque).

---

## 3. Sécurité & droits

- **Permissions exposées** (`App\RevenueRecovery\RevenueRecoveryModule::permissions()`) :
  `revenue_recovery.read`, `revenue_recovery.configure` (exploitant/responsable établissement),
  `revenue_recovery.manage` (agent commercial/support — arrêt manuel). ⚠ Noms proposés par analogie
  avec `Recouvrement`/`Finance`, à arbitrer avec M8 avant figement (spec §3).
- **Voters** : aucun voter dédié — `PermissionVoter` existant (`is_granted('PERM', 'module.action')`)
  suffit ; le filtrage de périmètre est porté par `PerimetreRevenueRecoveryExtension` (lecture) et les
  processors dédiés (écriture par corps brut) — §0.6, même séparation que `Recouvrement`/`Finance`.
- **RG-RR-06 (invariant central, ⚠ à confirmer par claude-A)** — aucune classe `App\RevenueRecovery\*`
  n'importe `App\Securite\Entity\DroitAcces` ni un service de propagation d'accès. Test dédié §5.
- **RGPD (RG-RR-03)** — `RecoveryEngine::sendDueAttempts()` (tâche planifiée, patron `boutique:liberer-paniers-expires`)
  vérifie `App\Crm\Entity\Consentement::estExploitable()` du canal **à l'échéance de chaque tentative**,
  pas seulement à l'ouverture du dossier (cas limite §11 spec : révocation en cours de séquence) — dépendance
  **lecture seule**, aucun second registre de consentement créé par ce lot.
- **Vérification de l'état actif à l'exécution** (cas limite §11 spec) : une `RecoveryAttempt` `Pending`
  dont la `RecoverySequence` a été désactivée entre la programmation et l'échéance n'est **pas** envoyée
  (`status → cancelled`), vérifié à chaque exécution de la tâche planifiée, pas seulement à la
  programmation.

---

## 4. Migrations

Trois migrations additives, timestamps après la dernière migration existante, toutes `CREATE TABLE`
(aucune table existante modifiée — brique entièrement nouvelle, aucune dépendance d'écriture vers
`Recouvrement`/`Securite`) :

- **`Version20260825090000`** — `CREATE TABLE revenue_recovery_sequence` (`id BINARY(16) PK`,
  `establishment_id BINARY(16) NOT NULL FK → org_etablissement`, `trigger_type VARCHAR(32) NOT NULL`,
  `active TINYINT(1) NOT NULL DEFAULT 0`, `max_attempts SMALLINT NOT NULL DEFAULT 3`, `steps JSON NOT
  NULL`, `created_at DATETIME NOT NULL`, `updated_at DATETIME NOT NULL`) + `UNIQUE INDEX
  uniq_recovery_sequence_establishment_trigger (establishment_id, trigger_type)` (RG-RR-01).
- **`Version20260825090100`** — `CREATE TABLE revenue_recovery_case` (`id BINARY(16) PK`,
  `establishment_id BINARY(16) NOT NULL FK → org_etablissement`, `trigger_type VARCHAR(32) NOT NULL`,
  `subject_type VARCHAR(32) NOT NULL`, `subject_ref VARCHAR(64) NOT NULL`, `amount_cents INT NULL`,
  `status VARCHAR(16) NOT NULL DEFAULT 'active'`, `sequence_id BINARY(16) NOT NULL FK →
  revenue_recovery_sequence`, `opened_at DATETIME NOT NULL`, `resolved_at DATETIME NULL`, `stopped_at
  DATETIME NULL`, `stop_reason LONGTEXT NULL`, `stopped_by_id BINARY(16) NULL FK → sec_utilisateur`) +
  `INDEX idx_recovery_case_establishment_status (establishment_id, status)` + `INDEX
  idx_recovery_case_subject (establishment_id, trigger_type, subject_type, subject_ref)`.
- **`Version20260825090200`** — `CREATE TABLE revenue_recovery_attempt` (`id BINARY(16) PK`,
  `recovery_case_id BINARY(16) NOT NULL FK → revenue_recovery_case`, `step_index SMALLINT NOT NULL`,
  `scheduled_at DATETIME NOT NULL`, `sent_at DATETIME NULL`, `channel VARCHAR(8) NOT NULL DEFAULT
  'email'`, `status VARCHAR(10) NOT NULL DEFAULT 'pending'`, `skip_reason VARCHAR(32) NULL`) + `INDEX
  idx_recovery_attempt_case (recovery_case_id)` + `INDEX idx_recovery_attempt_scheduled (status,
  scheduled_at)` (utilisé par la tâche planifiée d'envoi).

**Down** : les trois migrations sont réversibles (`DROP TABLE`, ordre inverse pour respecter les FK),
rejouables (constitution §7). Aucune donnée existante affectée (tables entièrement nouvelles, aucune
colonne ajoutée à une table `Recouvrement`/`Securite`/`Reservation`/`Crm` existante).

---

## 5. Tests

| Test | Type | Couvre |
|---|---|---|
| `RecoverySequenceApiTest::testCreationSequenceCartAbandonedDeuxEtapes` | Fonctionnel API | CA-1 US-RR-01 |
| `RecoverySequenceApiTest::testUniciteSequenceParEtablissementEtDeclencheurRefuse409` | Fonctionnel API | RG-RR-01 |
| **`CloisonnementRevenueRecoveryTest::testEtablissementBNeVoitJamaisLesCasesDeA`** | Fonctionnel API | US-RR-06/CA-1, RG-RR-07 — cloisonnement explicitement demandé par la mission |
| `CloisonnementRevenueRecoveryTest::testSequenceHorsPerimetreCreationRefuse404` | Fonctionnel API | §0.6 pt.2 |
| `CloisonnementRevenueRecoveryTest::testArretManuelCaseHorsPerimetreRefuse404` | Fonctionnel API | §0.6 pt.2 |
| `RecoveryEngineTest::testEvenementSansSequenceConfigureeNouvreAucunCase` | Unit | US-RR-02/CA-2, RG-RR-02 — dégradation propre, jamais une erreur |
| `RecoveryEngineTest::testEvenementAvecSequenceActiveOuvreCaseEtProgrammePremiereAttempt` | Unit | US-RR-02/CA-1 |
| `RecoveryEngineTest::testSecondeOccurrenceMemeSujetPendantCaseActifNouvrePasSecondCase` | Unit | §11 spec, idempotence ⚠ HYPOTHÈSE |
| **`RecoveryEngineTest::testConsentementAbsentTentativeSauteeSkippedNoConsent`** | Unit | US-RR-03/CA-1, RG-RR-03 |
| `RecoveryEngineTest::testConsentementRevoqueEnCoursDeSequenceSauteTentativeSuivante` | Unit | §11 spec — révocation en cours de séquence |
| **`RecoveryEngineTest::testArretAutomatiqueSurEvenementDeResolutionAnnuleTentativesRestantes`** | Unit | US-RR-04/CA-1, RG-RR-04 |
| **`RecoveryEngineTest::testArretManuelSansMotifRefuse`** | Unit | US-RR-05/CA-2, RG-RR-05 |
| `StopRecoveryCaseApiTest::testArretManuelAvecMotifTraceAucuneTentativeFutureEnvoyee` | Fonctionnel API | US-RR-05/CA-1 |
| `RecoveryEngineTest::testSequenceDesactiveeEntreProgrammationEtEcheanceNenvoiePas` | Unit | §11 spec — vérification à l'exécution |
| **`RevenueRecoveryAccessInvariantTest::testAucuneClasseNimportDroitAccesOuPropagationAcces`** | Unit (analyse statique légère, grep/reflection) | US-RR-07/CA-1, **RG-RR-06** — invariant central |
| `RevenueRecoveryEventSubscriberI1Test::testBookingCancelledOuvreCaseSiWithinFreeWindow` | Fonctionnel/Unit | I1 — branchement réel `booking.cancelled` |
| `RevenueRecoveryEventSubscriberI1Test::testBookingNoShowOuvreCase` | Fonctionnel/Unit | I1 — branchement réel `booking.no_show` |
| `RevenueRecoveryEventSubscriberI1Test::testPaymentFailedViaLegacyBridgeOuvreCase` | Fonctionnel/Unit | I1 — branchement réel via `LegacyEventBridge` |
| `RevenueRecoveryEventSubscriberI1Test::testPaymentIncidentReopenedRelanceComplementaire` | Fonctionnel/Unit | I1 |
| ⛔ `RevenueRecoveryEventSubscriberI2Test::testCartAbandonedSimuleOuvreCase` | Unit (événement simulé, `DomainEvent` construit à la main) | I2 — écrit/testé mais inerte tant que RR-1 n'émet pas réellement |
| ⛔ `RevenueRecoveryEventSubscriberI2Test::testInvoiceOverdueSimuleOuvreCase` | Unit (simulé) | I2 |
| ⛔ `RevenueRecoveryEventSubscriberI2Test::testQuoteExpiredSimuleOuvreCase` | Unit (simulé) | I2 |
| ⛔ `RevenueRecoveryEventSubscriberI2Test::testCustomerInactiveSimuleOuvreCase` | Unit (simulé) | I2 |
| **`RevenueRecoveryEventTest::testCaseOpenedEmisAvecTenantDeriveDuCase`** | Unit | D6 — `tenant.establishmentId` = `RecoveryCase.establishment`, jamais du contexte HTTP (RG-RR-09) |
| `RevenueRecoveryEventTest::testAttemptSentAttemptSkippedCaseResolvedCaseStoppedEmisAuxTransitions` | Unit | §8 spec — 5 événements produits |
| `RevenueRecoveryModuleManifestTest::testManifestConstructibleSansArgumentEtPermissionsValides` | Unit | contrat `ModuleManifest` (patron `ManifestCatalogueTest` existant) |
| `RevenueRecoveryBestEffortTest::testExceptionDansAbonneNinterromptPasLactionMetierDorigine` | Unit | D7/RG-PLAT-05 — best-effort, patron `LegacyEventBridge` |

`tests/RevenueRecovery/*` (unitaires métier + API fonctionnels du module) + `tests/Platform/*`
(`RevenueRecoveryModuleManifestTest` rejoint `ManifestCatalogueTest` existant, `RevenueRecoveryEventTest`
vérifie la conformité au catalogue une fois les 5 événements ajoutés, §8).

---

## 6. Tâches (voir tasks-revenue-recovery.md)

- **T1** — Enums + entités `RecoverySequence`/`RecoveryCase`/`RecoveryAttempt` (sans API Platform) +
  migrations `…090000`/`…090100`/`…090200` + tests unitaires d'entité.
- **T2** — `PerimetreRevenueRecoveryExtension` + `#[ApiResource]` lecture seule + tests de cloisonnement
  en lecture.
- **T3** — `RecoveryEngine` (coeur : `handle()`, `resolve()`, RG-RR-02/04) + tests unitaires (sans
  branchement d'événement réel encore).
- **T4** — `RecoverySequenceProcessor` (création/édition, RG-RR-01, revérification établissement) + tests.
- **T5** — Consentement (RG-RR-03) + tâche planifiée `sendDueAttempts()` (patron
  `boutique:liberer-paniers-expires`) + tests.
- **T6** — Arrêt manuel : `RecoveryEngine::stopManually()` + `StopRecoveryCaseProcessor` (RG-RR-05) +
  tests.
- **T7** — `RevenueRecoveryEventSubscriber` I1 (4 déclencheurs réels) + tests fonctionnels I1 — dépend
  de T3.
- **T8** — `RevenueRecoveryEventSubscriber` I2 (4 déclencheurs simulés, ⛔ inertes) + tests par événement
  simulé — dépend de T3, ⛔ bloqué en production par RR-1 (hors périmètre claude-E).
- **T9** — Émission des 5 événements `revenue_recovery.*` (§8) dans T3/T4/T6 + tests d'événement — dépend
  de T3, T4, T6.
- **T10** — `RevenueRecoveryModule implements ModuleManifest` + tests manifeste — peut être fait tôt,
  listé en fin pour refléter que `permissions()`/`eventsEmitted()` ne sont figés qu'une fois T1-T9
  stabilisées.
- **T11** — Test d'invariant RG-RR-06 (`RevenueRecoveryAccessInvariantTest`) — à écrire dès T1, revérifié
  à chaque tâche suivante (garde-fou continu, pas une passe finale isolée).
- **T12** — Revue de cohérence (constitution §8) : rejeu `App\Tests\Recouvrement\*`/`App\Tests\Reservation\*`
  existants (non-régression, ce lot ne modifie aucun fichier de ces modules), i18n, ajout de
  `mapping.paths` **signalé mais non fait** (à la charge de l'intégrateur, même point que FIN-2 §7 pt.5).

---

## 7. Risques / à valider

1. **Quatre arbitrages ouverts de la spec, tous marqués « ⚠ à confirmer par claude-A »** (§0.1, §0.2,
   §0.4, §0.5 de ce plan = §0.5/§12 de la spec) : nom/namespace du module, invariant RG-RR-06, sort de
   `RelancePanierExpireMailer`, comportement inactif-par-défaut. Ce plan **procède** sur la
   recommandation de la spec pour chacun sans bloquer sa production, cohérent avec la méthode déjà
   appliquée au plan Smart Flow.
2. **5 événements `revenue_recovery.*` absents du catalogue partagé** (`catalogue-evenements.md`) — à
   ajouter formellement avant implémentation (même geste que la suite Finance §8 de son plan). Ce plan ne
   modifie pas le catalogue lui-même (hors périmètre d'un plan technique) mais le signale explicitement
   comme préalable à T9.
3. **I2 est du code mort en production tant que RR-1 n'est pas livré** — assumé et documenté (§0.3),
   conforme à l'avertissement D22 sur le risque de « coquille inerte » : les tests I2 protègent contre
   une régression de la logique de sous-abonnement le jour où RR-1 branchera les émissions réelles, mais
   aucune valeur métier n'est livrée par I2 avant RR-1. **Recommandation à l'intégrateur** : prioriser
   RR-1 (émission de `cart.abandoned`/`invoice.overdue`/`quote.expired`/`customer.inactive`) comme
   chantier séparé, hors périmètre claude-E.
4. **Doublon `RelancePanierExpireMailer`** (§0.4) — reste actif en parallèle sans arbitrage ; le risque
   ne se matérialise qu'à l'activation de `cart.abandoned` (I2), donc pas avant RR-1, mais l'arbitrage
   doit être tranché **avant** cette activation, pas après (sinon double relance immédiate au premier
   panier abandonné une fois RR-1 livré).
5. **Plafond anti-sur-sollicitation multi-déclencheurs** (§11 spec, ⚠ HYPOTHÈSE non retenue v1) — un
   client cumulant panier abandonné + facture échue reçoit une communication indépendante par
   `RecoveryCase`, sans fusion ni plafond. Différé sur constat (D26), pas construit par ce lot.
6. **Canal SMS** (§9/§12 spec) — aucun service transverse SMS identifié dans le dépôt ; `RecoveryChannel`
   n'expose que `Email` en v1. Si le SMS est requis, c'est un service transverse à créer séparément, hors
   périmètre RR-0/ce plan.
7. **Fenêtre d'envoi (ne jamais relancer la nuit)** — non spécifiée par la spec (§11, ⚠ HYPOTHÈSE), non
   traitée par ce plan ; `sendDueAttempts()` envoie dès l'échéance sans notion d'horaire. À trancher si
   le produit l'exige.
8. **Tableau de bord / taux de conversion** (§8 spec, écran dédié candidat D13 raison n°2) — ce plan
   couvre l'API de lecture (`GetCollection` sur `RecoveryCase`) mais ne détaille pas de `Provider`
   d'agrégats dédié (taux de conversion par séquence) : à affiner en tâche de suivi si le produit
   l'exige au-delà d'une liste filtrable brute.

# Spec — Revenue Recovery (`RR-0`)

- **Lot / module :** module transverse **hors backlog actuel** — se branche par événements (D2/D7)
  sur `App\Boutique`, `App\Facturation`, `App\Reservation`, `App\Crm`, `App\Devis`, et coexiste avec
  `App\Recouvrement` sans dépendance de code (D2).
- **Stories couvertes :** aucune `US-Lx` existante — nouvelle numérotation `US-RR-nn` (même patron que
  `spec-finance-suite.md`, `spec-stock.md`) : ⚠ **HORS BACKLOG — à faire valider et chiffrer avant
  développement.**
- **Règles de gestion :** nouvelle numérotation `RG-RR-nn`.
- **Statut :** **brouillon** — la question d'architecture du §0 doit être tranchée par claude-A avant
  tout plan technique (`sdd-architecte`).

## 0. Décision d'architecture (D22) — étend-on `Recouvrement`, ou crée-t-on `RevenueRecovery` ?

C'est la question posée par D22 avant toute autre : *« étend-on `Recouvrement` ou crée-t-on
`RevenueRecovery` ? »*, pas *« comment relancer un client ? »*. Cette section répond en lisant le code
existant, pas en le devinant.

### 0.1 Ce que `Recouvrement` fait réellement aujourd'hui (lecture du code)

| Fichier | Rôle constaté |
|---|---|
| `Entity/PolitiqueRecouvrement.php` | Une politique **par établissement** : `nbRepresentationsMax`, `calendrierRepresentationJours` (délais en jours), `momentRefusAcces` (enum `MomentRefusAcces`), `nReprAvantBlocage`, `delaiAvantSuspensionContratJours`. |
| `Entity/IncidentImpaye.php` | Un dossier de **dette réelle et chiffrée** : `montantCentimes`, `dateRejet`, `motifBancaire` (code retour bancaire 4 caractères), `rejetOrigine` (lien optionnel vers `App\Sepa\Entity\RejetSepa`), rattaché à un **contrat d'abonnement** via le couple opaque `typeRedevable`/`referenceRedevable` (résolu par `RedevablePort`/`RedevableRegistry`, fourni par une verticale — Sport aujourd'hui). Porte `accesBloque: bool`. |
| `Entity/RepresentationSepa.php` | Une **tentative réelle de re-présentation bancaire** programmée (`dateProgrammee`/`dateExecution`/`resultat` : `EnAttente`/`Reussie`/`Echouee`). |
| `Service/MoteurRecouvrementHandler.php` | Machine à états détection → programmation de représentations → résultat → recouvrement/résolution, pilotée par `PolitiqueRecouvrement`. |
| `Service/PropagationAccesHandler.php` | Se déclare lui-même, en commentaire de code, **« seul point d'écriture du moteur de recouvrement sur `DroitAcces.statutProjection` »**. C'est un invariant d'exclusivité déjà posé, pas une hypothèse de cette spec. |
| `Service/ResolutionImpayeHandler.php` | Résolution 1-clic (encaissement immédiat) + réouverture forcée **avec motif obligatoire** (`RG-SOCLE-07`). |
| `Doctrine/PerimetreRecouvrementExtension.php` | Cloisonnement établissement (`RG-SOCLE-05`) via une carte statique `CHAINES` par classe d'entité — le même patron que tout autre module cloisonné. |
| `Port/RedevablePort.php` / `Service/RedevableRegistry.php` | Point d'extension **pour verticales à abonnement uniquement** (`typeRedevable()` de la forme `sport.abonnement_fitness`) — pas un point d'extension générique pour « n'importe quel événement métier ». |

**Constat qui compte le plus pour cette décision :** aucun fichier de `App\Recouvrement` ne contient de
`Mailer`, `Notification` ou envoi e-mail/SMS (vérifié par recherche exhaustive dans le module). Le
module ne communique **jamais** directement avec le client — c'est un moteur d'état + accès, pas un
canal de sortie. La « résolution 1 clic » suppose une app cliente déjà informée, mais rien dans ce
module ne l'informe.

### 0.2 Pourquoi ce périmètre ne généralise pas aux cinq nouveaux déclencheurs

| Dimension | `Recouvrement` aujourd'hui | Besoin RR (panier abandonné / facture échue / devis expiré / client inactif / no-show) |
|---|---|---|
| Objet du dossier | Dette réelle et chiffrée (`montantCentimes`, code retour bancaire) | Pas toujours une dette — un panier abandonné, un devis expiré ou un client inactif n'ont **aucun montant dû** |
| Rattachement | Contrat d'abonnement typé, résolu par un port fourni par une verticale | Un événement métier ponctuel (une commande, un devis, une fiche client, une réservation) — pas un « contrat » |
| Conséquence pilotée | **Blocage d'accès** (`DroitAcces`, via le seul point d'écriture déclaré) | Aucune conséquence sur l'accès — uniquement une communication à visée commerciale |
| Canal de sortie | Aucun (zéro `Mailer` dans le module) | C'est justement l'objet du module : envoyer le message |
| Mécanique de relance | Re-présentation bancaire **réelle** (un retour SEPA effectif) | Pas de représentation bancaire pour 4 des 5 déclencheurs — un simple message programmé |

Forcer ces cinq déclencheurs dans le modèle `IncidentImpaye`/`RepresentationSepa`/`MomentRefusAcces`
reviendrait à faire porter à un moteur de blocage d'accès des cas qui n'ont **rien à bloquer**.

### 0.3 Recommandation : nouveau module `App\RevenueRecovery`, en anglais (D5)

**Sur les cinq axes demandés :**

1. **Nommage (D5).** `Recouvrement` est un module historique français, retrofit D5 prévu mais non
   priorisé. Un module neuf naît **100 % anglais dès aujourd'hui** (id, entités, colonnes, permissions,
   événements) — exactement ce que D5 exige pour tout nouveau code. Étendre `Recouvrement` forcerait
   soit un mélange de vocabulaire **à l'intérieur d'une même classe** (pire que le « vocabulaire mixte »
   déjà assumé par la suite Finance à l'échelle du dépôt, cf. `spec-finance-suite.md` §0), soit un
   renommage anticipé de `IncidentImpaye`/`PolitiqueRecouvrement` qui n'est pas le sujet de RR-0.
2. **Cloisonnement (D3/D8).** `PerimetreRecouvrementExtension` résout le périmètre via une carte statique
   `CHAINES` par classe d'entité : ajouter les entités RR à cette carte ne coûte **pas moins cher** que
   d'écrire une extension Doctrine dédiée dans un module neuf. Aucune économie réelle à étendre, et une
   surface de bug en moins sur le chemin argent + accès déjà identifié comme sensible par D3/D8 (37
   fichiers sans contrôle visible avant D8).
3. **Catalogue d'événements.** Un manifeste nommé « recouvrement » qui déclarerait `eventsConsumed =
   [cart.abandoned, quote.expired, customer.inactive, …]` serait trompeur pour quiconque le lit — or
   `manifeste-module.md` pose explicitement que « le manifeste est la seule surface qu'un autre module
   doit lire pour intégrer ». Un module nommé pour ce qu'il fait documente correctement son contrat.
4. **Migration incrémentale.** Un module neuf ne touche **aucune ligne** du moteur `Recouvrement` déjà
   testé et en production sur le chemin argent + accès. Étendre imposerait de modifier
   `MoteurRecouvrementHandler`/`PolitiqueRecouvrement`/`IncidentImpaye` — un risque de régression sur un
   mécanisme qui bloque réellement l'accès physique de clients payants, pour livrer une fonctionnalité
   qui, elle, n'a jamais rien à voir avec l'accès.
5. **Risque de rupture.** Le pire mode de défaillance serait qu'une généralisation mal cloisonnée des
   enums (`StatutIncidentImpaye`, `MomentRefusAcces`) finisse par laisser un « incident » de panier
   abandonné déclencher accidentellement un blocage d'accès — exactement la famille de défaut que D17 a
   corrigée pour les pilotes d'accès (une opération non prévue ne doit jamais s'exécuter en silence).
   Deux domaines distincts rendent cette classe de bug **structurellement impossible**, plutôt que
   dépendante d'une discipline de code à tenir indéfiniment.

**Ce qui est repris — un patron, jamais une ligne de code (D2).** D22 a raison de dire que Revenue
Recovery « ne part pas de zéro » : ce qui se réplique (sans import cross-module) est le **patron de
conception**, pas le code :
- politique par établissement + calendrier paramétrable de tentatives
  (`PolitiqueRecouvrement` → nouvelle entité `RecoverySequence`) ;
- machine à états détection → programmation → exécution → résolution
  (`MoteurRecouvrementHandler` → nouveau `RecoveryEngine`) ;
- arrêt manuel avec motif obligatoire, même exigence que `RG-SOCLE-07` ;
- cloisonnement établissement via une extension Doctrine dédiée, même patron que
  `PerimetreRecouvrementExtension`.

**Le point de recouvrement réel entre les deux modules.** `payment.failed`, `payment.incident_reopened`
et `invoice.overdue` (pour un redevable connu de `Recouvrement`) sont déjà entièrement pilotés côté
état + accès par `Recouvrement`. `RevenueRecovery` ne doit **pas** reproduire cette mécanique : il
s'abonne au **même nom de contrat** pour ajouter ce qui manque structurellement aujourd'hui — la
communication proactive au client (aucun `Mailer` dans `App\Recouvrement`, §0.1). Les deux modules
réagissent au même fait, chacun pour sa propre responsabilité, sans jamais s'appeler (D2).

⚠ **Cas particulier à trancher.** `invoice.overdue` peut concerner une facture qui **n'est pas** un
contrat d'abonnement connu de `RedevableRegistry` (ex. une facture ponctuelle `App\Facturation`) : dans
ce cas, `Recouvrement` ne peut structurellement rien faire (le registre ne résout que les types déclarés
par une verticale), et `RevenueRecovery` est **seul** à agir.

### 0.4 Découverte de code — un doublon déjà en production, à arbitrer avant RR-1

`App\Boutique\Notification\RelancePanierExpireMailer`, déclenché par la commande
`boutique:liberer-paniers-expires` (`LibererPaniersExpiresCommand`), envoie **déjà** une relance e-mail
au panier expiré — un envoi **unique**, non paramétrable (`PanierEnLigne.relanceEnvoyee`, idempotent,
un seul palier, aucun canal alternatif, aucune désinscription dédiée). C'est la preuve concrète que
« panier abandonné » a déjà un point-solution ad hoc — exactement ce que `Recouvrement` était pour Sport
avant son extraction en moteur générique.

RR-1 (préalable, hors périmètre de cette spec) devra répondre à cette question **avant** d'émettre
`cart.abandoned` : ce mailer est-il (a) remplacé par `RevenueRecovery`, (b) conservé comme filet de
sécurité minimal pendant que `RevenueRecovery` vient en complément (risque assumé de double e-mail au
même client), ou (c) internalisé tel quel comme premier `RecoveryStep` par défaut de la séquence
« panier abandonné » ? Sans arbitrage, un client abandonnant un panier recevra deux relances distinctes
pour le même fait.

### 0.5 Questions posées à claude-A

1. Confirmer le nom/namespace du nouveau module (`App\RevenueRecovery` proposé) et son id de manifeste
   (`revenue_recovery`).
2. Confirmer que `Recouvrement` garde son périmètre actuel (SEPA + accès, contrats d'abonnement) sans
   renommage ni déplacement à ce stade — seul le retrofit D5 global le concernera plus tard.
3. Arbitrer le sort de `RelancePanierExpireMailer`/`LibererPaniersExpiresCommand` (§0.4) avant que RR-1
   émette `cart.abandoned`.
4. Valider l'invariant central de cette recommandation : `RevenueRecovery` **n'écrit jamais** sur
   `DroitAcces` — `Recouvrement` en reste l'unique point d'écriture (§0.1), et c'est cet invariant qui
   justifie tout le choix « module neuf » plutôt qu'extension.

## 1. Objectif

Donner à la plateforme un moteur de relance **générique, piloté par événements et cloisonné par
établissement**, qui transforme cinq signaux de risque de perte de revenu (panier abandonné, facture
échue, devis expiré, client inactif, réservation en no-show/annulée) en séquences de communication
paramétrables — sans jamais toucher au contrôle d'accès, domaine réservé à `Recouvrement`.

## 2. Périmètre

**Inclus :**
- Réception des événements du catalogue (§6) et ouverture d'un dossier de relance (`RecoveryCase`) par
  occurrence.
- Séquences paramétrables **par établissement et par type de déclencheur** (canal, délai, nombre max de
  tentatives, condition d'arrêt).
- Envoi des communications (e-mail a minima, cf. §10 pour SMS).
- Respect du consentement RGPD existant (`App\Crm\Entity\Consentement`) avant tout envoi.
- Arrêt automatique dès que l'objectif est atteint (paiement reçu, devis accepté, panier finalisé,
  réservation reprise, client réengagé), détecté par un second événement.
- Arrêt manuel d'une campagne, avec motif obligatoire.
- Tableau de bord de pilotage des campagnes actives et de leur taux de conversion.
- Cloisonnement par établissement (D3/D8), permissions module × action.

**Exclu (pour l'instant) :**
- **RR-1** — l'émission des événements manquants (`cart.abandoned`, `invoice.overdue`, `quote.expired`,
  `customer.inactive`) depuis `Boutique`/`Facturation`/`Crm`/`Devis`. C'est un **préalable**, porté par
  l'intégrateur (D22), pas une dépendance optionnelle : cf. §9.
- Toute conséquence sur l'accès (blocage, `DroitAcces`) — domaine exclusif de `Recouvrement` (§0.1).
- Un éventuel canal SMS/push tant qu'aucun service transverse de ce type n'existe (§9, §11).
- Le sort de `RelancePanierExpireMailer` — arbitrage préalable requis (§0.4).
- La proposition effective d'un nouveau créneau sur no-show (D27) — `RevenueRecovery` **émet**
  seulement, la proposition revient à Smart Flow (SF-2, non livré).

## 3. Acteurs & droits

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Système (bus d'événements) | Ouvrir/résoudre un `RecoveryCase`, programmer/envoyer une `RecoveryAttempt` | — (aucune session utilisateur) |
| Exploitant / responsable établissement | Paramétrer les séquences de relance de son établissement | `revenue_recovery.configure` |
| Agent commercial / support | Consulter le tableau de bord, arrêter manuellement une campagne (motif requis) | `revenue_recovery.read`, `revenue_recovery.manage` |
| Client final | Destinataire uniquement — jamais un acteur qui interroge l'API RR | — |

## 4. User stories & critères d'acceptation

### US-RR-01 — Paramétrer une séquence de relance par déclencheur
- **CA-1** — *Étant donné* un exploitant avec `revenue_recovery.configure`, *quand* il crée une séquence
  pour `cart.abandoned` avec deux étapes (J+1 e-mail, J+3 e-mail), *alors* la séquence est enregistrée
  pour son établissement uniquement (RG-RR-01).

### US-RR-02 — Déclenchement automatique à réception d'un événement
- **CA-1** — *Étant donné* une séquence active pour `invoice.overdue`, *quand* le bus publie
  `invoice.overdue` pour l'établissement concerné, *alors* un `RecoveryCase` est ouvert et la première
  `RecoveryAttempt` est programmée au délai configuré.
- **CA-2** — *Étant donné* aucun événement de ce type n'a de séquence configurée, *quand* il survient,
  *alors* aucun `RecoveryCase` n'est créé — dégradation propre, jamais une erreur (RG-RR-02).

### US-RR-03 — Envoi respectant le consentement RGPD
- **CA-1** — *Étant donné* un client sans consentement `Email` accordé (`Consentement::estExploitable()`
  faux), *quand* une `RecoveryAttempt` e-mail arrive à échéance, *alors* elle n'est pas envoyée et le
  dossier trace `skipped_no_consent` (RG-RR-03).

### US-RR-04 — Arrêt automatique sur objectif atteint
- **CA-1** — *Étant donné* un `RecoveryCase` actif ouvert par `cart.abandoned`, *quand* le panier
  correspondant est finalisé (paiement confirmé), *alors* les tentatives restantes sont annulées et le
  dossier passe `Resolved` (RG-RR-04).

### US-RR-05 — Désinscription / arrêt manuel
- **CA-1** — *Étant donné* un agent avec `revenue_recovery.manage`, *quand* il arrête une campagne en
  saisissant un motif, *alors* aucune tentative future n'est envoyée et le motif est tracé — même
  exigence que `RG-SOCLE-07` (RG-RR-05).
- **CA-2** — *Étant donné* le même agent tente d'arrêter une campagne sans motif, *alors* la demande est
  refusée.

### US-RR-06 — Cloisonnement par établissement
- **CA-1** — *Étant donné* deux établissements distincts A et B, *quand* un agent de A consulte les
  campagnes, *alors* il ne voit jamais les `RecoveryCase` de B — échec fermé 403/404 (D3/D8, RG-RR-07).

### US-RR-07 — Aucune conséquence sur l'accès
- **CA-1** — *Étant donné* un `RecoveryCase` dont toutes les tentatives ont échoué (`Exhausted`),
  *alors* aucun `DroitAcces` n'est modifié — `RevenueRecovery` n'écrit jamais sur l'accès (RG-RR-06).

## 5. Règles de gestion

- **RG-RR-01** — Une séquence de relance (`RecoverySequence`) est unique par établissement × type de
  déclencheur, sur le modèle de l'unicité `PolitiqueRecouvrement` × établissement.
- **RG-RR-02** — Un déclencheur sans séquence configurée ne crée aucun `RecoveryCase` : contrairement à
  `Recouvrement` (qui applique une politique par défaut, protection d'un revenu déjà engagé),
  `RevenueRecovery` reste **inactif par défaut** pour tout établissement n'ayant rien paramétré — une
  relance commerciale intrusive doit être un choix explicite (⚠ HYPOTHÈSE, cf. §12.3).
- **RG-RR-03** — Chaque tentative respecte le consentement du canal
  (`App\Crm\Entity\Consentement::estExploitable()`) avant envoi ; à défaut, la tentative est marquée
  « sautée », jamais bloquante pour la suite de la séquence.
- **RG-RR-04** — Un `RecoveryCase` s'arrête automatiquement dès réception de l'événement de résolution
  correspondant à son déclencheur (table de correspondance §6).
- **RG-RR-05** — Un arrêt manuel exige un motif (même exigence que `RG-SOCLE-07` sur la réouverture
  forcée d'un incident `Recouvrement`).
- **RG-RR-06** — `RevenueRecovery` n'écrit jamais sur `DroitAcces` ni sur aucun état d'accès — invariant
  d'exclusivité de `Recouvrement` (§0.1), central à la recommandation de cette spec.
- **RG-RR-07** — Toute entité résolue depuis un identifiant client (`RecoveryCase`, `RecoverySequence`)
  revérifie son établissement de rattachement (D3/D8, échec fermé).
- **RG-RR-08** — Le nombre maximal de tentatives et les délais sont paramétrables par établissement et
  par déclencheur (par analogie à `PolitiqueRecouvrement.nbRepresentationsMax`/
  `calendrierRepresentationJours`).
- **RG-RR-09** — Le `tenant.establishmentId` d'un événement produit par RR est dérivé de l'établissement
  du `RecoveryCase` (D6) — jamais recalculé depuis un contexte HTTP.

## 6. Événements consommés / produits

| Événement | Sens | Émetteur pressenti | Statut d'émission constaté (24/08) | Payload clé (catalogue) | Action RR |
|---|---|---|---|---|---|
| `cart.abandoned` | consommé | Boutique | **NON ÉMIS** — seul un mailer ad hoc existe (§0.4) | `amount`, `customer` | Ouvre un `RecoveryCase` « panier » |
| `payment.failed` | consommé | Recouvrement (via `LegacyEventBridge`, pont transitoire) | **ÉMIS** | `amount_cents`, `cause`, `rejected_at` | Ouvre un `RecoveryCase` communication (aucune conséquence d'accès) |
| `payment.incident_reopened` | consommé | Recouvrement (via `LegacyEventBridge`) | **ÉMIS** | `amount_cents` | Relance complémentaire |
| `invoice.overdue` | consommé | Facturation | **NON ÉMIS** | `amount`, `days_late` | Ouvre un `RecoveryCase` « facture » |
| `booking.cancelled` | consommé | Reservation (`AnnulerReservationProcessor`) | **ÉMIS depuis le 24/08** (SF-1/D22) | `slotId`, `leadTimeMinutes`, `withinFreeWindow`, `creditIssue?`, `creditRestoredAmount?` | Relance conditionnée à `withinFreeWindow` |
| `booking.no_show` | consommé | Reservation (`BasculerNoShowCommand`) | **ÉMIS depuis le 24/08** | `customerId`, `amountAtRisk`, `hasBillingRule`, `slotId`, `creditIssue?`, `creditRestoredAmount?` | Relance/communication cohérente avec D27 (restitution/report) |
| `quote.sent` | consommé | Devis | **NON ÉMIS** | `amount`, `due_date` | Signal faible, pas d'ouverture systématique |
| `quote.expired` | consommé | Devis | **NON ÉMIS** | `amount` | Ouvre un `RecoveryCase` « devis » |
| `customer.inactive` | consommé | Crm | **NON ÉMIS** | `last_contact` | Ouvre un `RecoveryCase` win-back |
| `sale.completed` / `payment.succeeded` / `quote.accepted` / `booking.created` | consommé (résolution) | modules respectifs | ⚠ à vérifier au cas par cas | — | Ferme le `RecoveryCase` correspondant (RG-RR-04) |

Produits par RR (à ajouter au catalogue partagé avant implémentation, même geste que la suite Finance
§8) :

| Événement produit | Fait |
|---|---|
| `revenue_recovery.case_opened` | Un `RecoveryCase` a été ouvert |
| `revenue_recovery.attempt_sent` | Une tentative a été envoyée |
| `revenue_recovery.attempt_skipped` | Une tentative a été sautée (consentement absent) |
| `revenue_recovery.case_resolved` | Objectif atteint, séquence arrêtée automatiquement |
| `revenue_recovery.case_stopped` | Arrêt manuel, motif tracé |

⚠ HYPOTHÈSE — ces cinq noms ne figurent pas dans `catalogue-evenements.md` aujourd'hui ; à valider et
consigner formellement avant implémentation.

## 7. Entités / agrégats pressentis — et rapport à l'existant `Recouvrement`

| Entité (proposée) | Rôle | Analogue anglais généralisé de |
|---|---|---|
| `RecoverySequence` | Politique de relance par établissement × type de déclencheur : étapes ordonnées (délai, canal, gabarit), nombre max de tentatives, actif/inactif | `PolitiqueRecouvrement` (patron répliqué, aucun lien de code) |
| `RecoveryCase` | Dossier ouvert par occurrence d'un événement : `establishment`, `triggerEvent`, `subjectType`/`subjectRef` (opaques, même patron que `typeRedevable`/`referenceRedevable`), `amountCents` **nullable** (pas toujours une dette), `status` (`Active`/`Resolved`/`Stopped`/`Exhausted`) | `IncidentImpaye`, généralisé (montant optionnel, pas de `motifBancaire`) |
| `RecoveryAttempt` | Une tentative programmée/envoyée : `scheduledAt`, `sentAt`, `channel`, `status` (`Pending`/`Sent`/`Skipped`/`Failed`), `skipReason` | `RepresentationSepa`, généralisé (pas de résultat bancaire, un statut d'envoi) |
| *(réutilisé, non dupliqué)* `App\Crm\Entity\Consentement` | Registre RGPD par canal, déjà append-only | — |

**Aucune association Doctrine entre `RevenueRecovery` et `Recouvrement`** (D2) : le seul lien est le nom
d'événement partagé (`payment.failed`, `payment.incident_reopened`) au sens de deux abonnés
indépendants réagissant au même fait.

## 8. API & modales (D13)

- **Paramétrage d'une séquence** : modale « Régler la relance — [déclencheur] », ouverte depuis un
  panneau de configuration `Revenue Recovery` unique (pas un écran par déclencheur). Un paramétrage à
  plusieurs étapes (ajouter une étape, choisir un canal, un délai) reste **une modale à étapes** (D13).
- **Tableau de bord des campagnes actives** (liste des `RecoveryCase`, taux de conversion) est candidat
  à un **écran dédié** au sens de la raison n°2 de D13 (« contenu qui ne tient pas » — tableau large,
  filtres), sur le modèle de `TableauBordRecouvrement` déjà existant côté `Recouvrement`.
- **Arrêt manuel d'une campagne** : action depuis une ligne de liste → modale avec motif obligatoire
  (RG-RR-05), `Échap` ferme, focus restitué à la fermeture (D13).
- Aucune page publique ni tunnel : ce module ne concerne que le back-office exploitant ; les
  communications sortantes sont des e-mails (§9), pas des écrans.

## 9. Sécurité & cloisonnement

- **D3/D8** — une extension Doctrine dédiée (`PerimetreRevenueRecoveryExtension`, calquée sur
  `PerimetreRecouvrementExtension`) restreint `RecoverySequence`/`RecoveryCase`/`RecoveryAttempt` à
  l'établissement de la session serveur ; tout `Processor` résolvant depuis un id client revérifie
  l'établissement (RG-RR-07).
- **D6** — le `tenant.establishmentId` des événements consommés est déjà dérivé du sujet par l'émetteur ;
  RR ne le recalcule jamais. Les événements produits par RR dérivent leur tenant de
  `RecoveryCase.establishment` (RG-RR-09).
- **Permissions module × action** (§3), UI qui masque, pas seulement désactive.
- **RGPD** — consentement vérifié avant tout envoi (RG-RR-03), traçabilité append-only des tentatives
  envoyées (utile en cas de réclamation « je reçois trop de relances »).
- **RG-RR-06** — aucune écriture sur `DroitAcces` : invariant central de cette spec, à faire valider
  explicitement par claude-A (§0.5).

## 10. Dépendances & préalables (RR-1)

- **RR-1 est un préalable structurant, porté par l'intégrateur (D22), pas une dépendance optionnelle.**
  Sans l'émission de `cart.abandoned` (Boutique — arbitrage du doublon §0.4), `invoice.overdue`
  (Facturation), `quote.sent`/`quote.expired` (Devis) et `customer.inactive` (Crm), les séquences
  correspondantes de RR restent configurées mais **jamais déclenchées** — exactement l'avertissement de
  D22 sur le risque de coquille inerte.
- `booking.cancelled` et `booking.no_show` sont **déjà émis** (constat de code du 24/08,
  `AnnulerReservationProcessor`/`BasculerNoShowCommand`) : ces deux déclencheurs sont exploitables dès
  la livraison du moteur RR, sans attendre RR-1.
- `payment.failed`/`payment.incident_reopened` sont déjà émis, mais uniquement via `LegacyEventBridge`
  (pont transitoire explicitement marqué comme devant disparaître) : RR s'abonne au **nom de contrat**,
  jamais au pont lui-même — déjà garanti par le découplage du bus (D2).
- Dépend de `App\Crm\Entity\Consentement` en **lecture seule** — pas de second registre de consentement.
- Dépend du service `MailerInterface` (Symfony) + Twig déjà utilisé par `App\Boutique\Notification`
  comme canal par défaut. ⚠ HYPOTHÈSE — aucun canal SMS transverse identifié dans le dépôt à ce jour ;
  si le SMS est requis, c'est un service transverse à créer séparément, hors périmètre RR-0.
- Dépend de Smart Flow (SF-2, non livré) pour la moitié « proposition de créneau » de D27 sur no-show —
  RR émet seulement, ne propose rien tant que Smart Flow n'existe pas (déjà acté par D27).

## 11. Cas limites

- Un client change d'établissement de rattachement entre l'ouverture du `RecoveryCase` et l'envoi d'une
  tentative : la vérification de périmètre doit se refaire à **chaque envoi**, pas seulement à la
  création (D8).
- Deux occurrences successives du même déclencheur pour le même sujet (ex. un panier rouvert puis
  ré-abandonné) — ⚠ HYPOTHÈSE : idempotence par (établissement, `triggerEvent`, `subjectRef`), un seul
  `RecoveryCase` actif à la fois — à confirmer.
- Le consentement est révoqué **en cours de séquence** : la tentative suivante doit sauter (RG-RR-03),
  pas seulement au moment de l'ouverture du dossier.
- Un même client cumule plusieurs déclencheurs actifs (panier abandonné + facture échue) : pas de
  fusion automatique en RR-0, chaque `RecoveryCase` reste indépendant. ⚠ HYPOTHÈSE : un plafond
  anti-sur-sollicitation (N relances max/semaine tous déclencheurs confondus) pourrait s'avérer
  nécessaire, mais n'est pas spécifié ici, faute de retour d'usage (cf. D26 : « on ajoute les
  garde-fous sur constat, pas par précaution »).
- Tant que §0.4 n'est pas arbitré, `RelancePanierExpireMailer` continue d'envoyer en parallèle : double
  relance possible pour panier abandonné — risque documenté, pas silencieux.
- Une tentative programmée arrive à échéance après que l'établissement a désactivé la séquence : elle ne
  doit pas partir (vérification de l'état actif **à l'exécution**, pas seulement à la programmation).
- Fenêtre d'envoi (ex. ne jamais relancer la nuit) : ⚠ HYPOTHÈSE — non spécifiée ici, à trancher si le
  produit l'exige.

## 12. Questions ouvertes à arbitrer par claude-A

1. Nom/namespace définitif du module (`App\RevenueRecovery` proposé, id de manifeste
   `revenue_recovery`) — §0.5.
2. Sort de `RelancePanierExpireMailer`/`LibererPaniersExpiresCommand` avant que RR-1 émette
   `cart.abandoned` — §0.4.
3. `RevenueRecovery` doit-il rester **inactif par défaut** tant qu'aucune séquence n'est configurée
   (RG-RR-02, recommandation de cette spec), ou appliquer un comportement par défaut comme
   `Recouvrement` ?
4. Canal SMS : service transverse à créer, ou e-mail seul pour la V1 (§10) ?
5. Faut-il ajouter les cinq événements produits par RR (§6) au catalogue partagé avant implémentation ?
6. Le plafond anti-sur-sollicitation multi-déclencheurs (§11) est-il nécessaire dès la V1, ou différé
   sur constat (D26) ?
7. Confirmation explicite de RG-RR-06 (`RevenueRecovery` n'écrit jamais sur `DroitAcces`) — c'est
   l'invariant qui motive tout le choix « module neuf » plutôt qu'extension (§0.3).

**Prochaine étape : plan technique (`sdd-architecte`).**

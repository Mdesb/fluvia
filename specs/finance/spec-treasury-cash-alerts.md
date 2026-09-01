# Spec — Alertes de trésorerie proactives (`App\Finance\Treasury`, addendum FIN-4)

- **Lot / module :** extension du module **`finance`**, brique `App\Finance\Treasury` déjà livrée
  (`app/src/Finance/Treasury/`) — ne crée pas de nouveau module, étend `TreasurySettings` et ajoute une
  entité `TreasuryCashAlert`, une commande planifiée et un événement de domaine.
- **Stories couvertes :** **US-TRE-11** *(nouvelle)* — ⚠ **HORS BACKLOG**, comme le reste de la suite
  Treasury (`spec-treasury.md`, en-tête) : « En tant que Trésorier non-comptable d'une petite régie, je
  veux être **prévenu avant** que ma trésorerie ne passe sous un seuil critique, avant une échéance
  connue, pour pouvoir agir (relancer, décaler un paiement, prévenir la collectivité) plutôt que
  découvrir le découvert le jour où il se produit. »
- **Règles de gestion :** **RG-TRE-10 à RG-TRE-16** *(nouvelles, suite de `spec-treasury.md` qui
  s'arrête à RG-TRE-09 dans son corps — l'en-tête de cette dernière réservait jusqu'à RG-TRE-13 sans
  jamais les définir ; cette spec occupe cette plage puis la dépasse)*. Règles **réutilisées, non
  redéfinies** : RG-TRE-05 (position), RG-TRE-06/07 (échéancier), RG-TRE-08 (prévisionnel simple),
  RG-SOCLE-01/05 (cloisonnement), RG-PLAT-01/04/06 (enveloppe d'événement, catalogue).
- **Statut :** proposée.

## 1. Objectif

Transformer une **lecture qu'on doit penser à ouvrir** (position, échéancier, prévisionnel de
`spec-treasury.md` §4.4-4.6, déjà livrés) en une **alerte qu'on reçoit** : quand la trajectoire de
trésorerie projetée croise un seuil plancher configuré, avant une échéance connue, le système le
signale de lui-même — sans attendre que quelqu'un ouvre l'onglet « Position & prévision ». Pour un
exploitant municipal ou associatif non-comptable, le risque n°1 n'est pas de mal calculer sa
trésorerie (le calcul existe déjà, `CashflowForecastCalculator`) — c'est de ne pas le regarder à temps.

## 2. Périmètre

### Inclus
- **Configuration d'un seuil plancher** et d'une **fenêtre d'anticipation**, par établissement
  (RG-TRE-10).
- **Détection périodique** du franchissement projeté du seuil dans la fenêtre, par une commande
  planifiée qui réutilise telle quelle la position actuelle (`TreasuryPositionCalculator::position()`)
  et l'échéancier consolidé (`PaymentScheduleCalculator::echeancier()`) déjà livrés — aucun second
  moteur de calcul de solde ou d'échéancier (RG-TRE-11).
- **Identification de la cause principale** (la plus grosse sortie de la période qui précède le
  franchissement) pour que l'alerte dise *quoi* regarder, pas seulement *qu'il y a un problème*
  (RG-TRE-12).
- **Anti-répétition** : une situation déjà signalée ne re-sonne pas à chaque passage du cron tant
  qu'elle n'empire pas (RG-TRE-13).
- **Remise** via le mécanisme de notification interne déjà existant (la Cloche,
  `App\Platform\Entity\Notification` + `NotificationRule` + `NotifyOnDomainEvent`) — le **même**
  patron que `treasury.discrepancy_detected`, déjà câblé dans `NotificationRule` aujourd'hui
  (RG-TRE-14).
- Émission d'un événement de domaine `treasury.threshold_breached`, catalogué (RG-TRE-15).
- Enregistrement de la commande dans l'ordonnanceur (`App\Platform\Scheduling\ScheduleCatalog`),
  faute de quoi elle ne s'exécute **jamais**, comme actuellement `finance:treasury:detecter-ecarts` et
  `finance:treasury:suggerer-rapprochements` (RG-TRE-16, §8 point 4).

### Exclu (pour l'instant)
- **Un scénario pondéré ou probabiliste** — le prévisionnel reste une projection arithmétique brute
  (RG-TRE-08, non re-tranché) ; l'alerte porte sur *cette* projection, elle n'en ajoute pas une seconde
  plus sophistiquée.
- **Un seuil par compte bancaire** — décision explicite, §4.1 : le seuil est configuré au niveau de
  l'**établissement**, à la même granularité que la position/l'échéancier/le prévisionnel déjà livrés
  (qui agrègent déjà tous les `BankAccount` de l'établissement, RG-TRE-05).
- **Un canal courriel** — aucun abonné du bus d'événements de ce dépôt n'envoie de courriel aujourd'hui
  (`ExpediteurCourriel` sert des contrôleurs HTTP ponctuels — mot de passe oublié, invitation — pas un
  écouteur d'événement) ; ajouté ici serait une seconde tuyauterie de notification pour un seul type
  d'alerte. Hors périmètre v1, §8 point 5.
- **Une notification de « retour à la normale »** quand une situation qui menaçait se résorbe — l'alerte
  se résout silencieusement (§4.4) ; annoncer activement la résorption n'est pas demandé par la mission.
- **Un tableau de bord dédié** aux alertes passées — `TreasuryCashAlert` est persistée (nécessaire à
  l'anti-répétition, §4.4) et exposée en lecture (§6) mais aucun écran neuf n'est demandé ; l'onglet
  « Position & prévision » de `TresorerieDashboard.jsx` existant reste la vue de référence.

## 3. Acteurs & droits

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| **Trésorier / Comptable** | Recevoir l'alerte dans sa Cloche | `finance.read` *(réutilisée, aucune permission créée)* |
| **Direction / Responsable financier** | Recevoir l'alerte dans sa Cloche (même droit que `spec-treasury.md` §3, lecture de la position/échéancier) | `finance.read` |
| **Administrateur** | Configurer le seuil plancher et la fenêtre d'anticipation par établissement | `finance.manage` *(réutilisée — même permission que `TreasurySettingsProcessor` existant, aucune permission créée)* |
| **Système** | Détecter le franchissement, créer/mettre à jour/résoudre une `TreasuryCashAlert`, émettre l'événement | *(acteur technique, commande planifiée, `EventActor = null`)* |

Aucune permission nouvelle : `finance.manage` couvre déjà la configuration de `TreasurySettings`
(`app/src/Finance/Treasury/State/TreasurySettingsProcessor.php`) et `finance.read` couvre déjà la
consultation de la trésorerie (`spec-treasury.md` §3). Créer une permission dédiée pour un simple champ
de configuration supplémentaire irait contre le principe de simplicité (constitution §2).

## 4. Comportements & règles

### 4.1 Configuration du seuil — par établissement, pas par compte (RG-TRE-10)
- **RG-TRE-10** — `TreasurySettings` (`app/src/Finance/Treasury/Entity/TreasurySettings.php`, déjà
  livrée, un seul réglage par établissement) porte deux champs nouveaux :
  - `cashAlertThresholdCents` (`int`, **nullable**, défaut `null`) — le solde plancher, en centimes.
    `null` = alerte **désactivée** pour cet établissement (opt-in explicite, cohérent avec le défaut
    prudent déjà retenu par `NotificationBasis::Consentement`/`ExpediteurCourriel::estBranche()`
    ailleurs dans le dépôt : en l'absence de configuration, on ne suppose jamais qu'une alerte est
    voulue). **Valeur négative acceptée** — une régie peut disposer d'une autorisation de découvert et
    vouloir être alertée seulement en deçà de `-3000,00 €`, pas de `0,00 €` (⚠ HYPOTHÈSE : à confirmer
    avec le métier que le découvert autorisé est bien porté par ce seul champ et non par un second
    concept).
  - `cashAlertHorizonDays` (`int`, défaut `30`, `PositiveOrZero` — même contrainte que
    `matchingWindowDays` déjà présent sur la même entité) — la fenêtre d'anticipation : combien de
    jours à l'avance on regarde.
  - **Décision — granularité établissement, pas compte bancaire.** `TreasuryPositionCalculator` et
    `PaymentScheduleCalculator` (déjà livrés) agrègent **déjà** tous les `BankAccount` d'un
    établissement en un seul solde/une seule projection (RG-TRE-05/06) — il n'existe aucune notion de
    solde projeté *par compte* dans le dépôt aujourd'hui. Un seuil par compte obligerait à écrire un
    second moteur de projection mono-compte, dupliqué du premier, pour une distinction que la mission
    ne demande pas explicitement (« votre solde » — au singulier). Le seuil est donc porté par
    `TreasurySettings`, à la même granularité que le reste de la brique. Une extension future vers un
    seuil par compte resterait possible sans migration destructive (ajout d'un `bankAccount` nullable
    sur `TreasuryCashAlert`, non fait ici).
  - Modification via `POST`/`PATCH` sur `TreasurySettings` — **endpoint et processor déjà existants**
    (`TreasurySettingsProcessor`, `finance.manage`), aucune nouvelle route.

### 4.2 Détection — commande planifiée, réutilise les calculateurs existants (RG-TRE-11)
- **RG-TRE-11** — Une commande `finance:treasury:verifier-seuils` (patron identique à
  `finance:treasury:detecter-ecarts` : parcourt tous les établissements concernés, tenant dérivé des
  données jamais du contexte HTTP, D6) :
  1. Pour chaque `Etablissement` portant un `TreasurySettings.cashAlertThresholdCents` **non nul** et
     au moins un `BankAccount` (actif ou non — `TreasuryPositionCalculator` ne filtre pas sur `active`
     aujourd'hui, comportement hérité, non modifié ici) :
  2. Calcule la position actuelle : `TreasuryPositionCalculator::position([$etablissement], today())`
     (réutilisée telle quelle).
  3. Calcule l'échéancier sur `[today(), today() + cashAlertHorizonDays]` :
     `PaymentScheduleCalculator::echeancier(...)` (réutilisée telle quelle).
  4. **Projette le solde jour par jour** — nouveau calcul, mais qui ne réinvente ni la position ni
     l'échéancier : trie `entries[]`/`exits[]` par date croissante, cumule algébriquement à partir du
     solde actuel (`+entrée`, `-sortie`), et retient la **première date** où le solde cumulé devient
     strictement inférieur à `cashAlertThresholdCents`.
     - **Aucune date trouvée dans la fenêtre** → pas de franchissement projeté ; voir résolution
       (§4.4).
     - **Une date trouvée** → franchissement projeté, procède à §4.3.
  5. Un mouvement dont le montant ramènerait le solde **exactement** au seuil (égalité stricte) n'est
     **pas** un franchissement (RG-TRE-11 littéral : « strictement inférieur ») — cohérent avec la
     sémantique d'un plancher qu'on ne veut pas *sous*-passer, pas qu'on ne veut pas *toucher*.

### 4.3 Cause principale (RG-TRE-12)
- **RG-TRE-12** — Parmi les sorties (`exits[]` de l'échéancier) dont la `date` est **antérieure ou
  égale** à la date de franchissement projetée, celle dont `amountCents` est le **plus élevé** est
  retenue comme cause principale de l'alerte (`causeSource`, `causeSourceId`, `causeAmountCents` —
  mêmes clés `source`/`sourceId`/`amountCents` que `PaymentScheduleCalculator::echeancier()` renvoie
  déjà : `supplier_invoice`, `invoice` n'est jamais une sortie côté échéancier — seules `supplier_invoice`
  et, à l'avenir, d'autres sources de sortie qualifient ; en pratique aujourd'hui uniquement
  `supplier_invoice`, l'échéancier ne portant pas d'autre source de *sortie* que les factures
  fournisseurs, RG-TRE-06).
  - **Égalité de montant entre deux sorties candidates** — la plus **ancienne** par date est retenue
    (celle qui pèse depuis le plus longtemps) ; à date égale, l'ordre n'est pas garanti au-delà de la
    stabilité du tri (⚠ HYPOTHÈSE, cas rare, non critique — l'alerte reste correcte sur le montant et la
    date de franchissement même si la « cause » nommée est l'une des deux équivalentes).
  - **Aucune sortie avant la date de franchissement** (l'érosion vient d'une accumulation de petits
    montants ou de l'absence d'entrée attendue, pas d'une grosse échéance isolée) — `causeSource` est
    `null`. L'alerte reste émise, avec sa date et son montant projeté, simplement sans cause nommée
    (dégradation propre, cohérent avec RG-TRE-07).

### 4.4 Anti-répétition (RG-TRE-13)
- **RG-TRE-13** — Une nouvelle entité `TreasuryCashAlert` (§5) porte l'état nécessaire à ne **pas**
  ré-alerter chaque jour pour la même cause — même besoin que `BankStatementLine.discrepancyNotifiedAt`
  (§0.9 de `plan-treasury.md`), mais l'alerte de seuil est un **fait agrégé** (la trajectoire projetée
  de tout l'établissement), pas la propriété d'une ligne existante : elle a besoin de sa **propre**
  ligne d'état, pas d'un simple horodatage posé sur une entité déjà là.
  - **Au plus une alerte `open` par établissement** (contrainte d'unicité applicative, même patron que
    `TreasurySettings` — un réglage par établissement).
  - **Franchissement détecté (§4.2), aucune alerte `open` en cours** → création d'une
    `TreasuryCashAlert` (`status = open`), émission de `treasury.threshold_breached` (§4.5),
    `lastNotifiedAt = now`.
  - **Franchissement détecté, une alerte `open` existe déjà** :
    - si la date de franchissement recalculée est **identique ou plus tardive** que celle de la
      dernière notification → mise à jour silencieuse des champs projetés (`projectedBreachDate`,
      `projectedBalanceCents`, cause), `lastCheckedAt = now`, **aucun** nouvel événement, **aucune**
      nouvelle notification (c'est le cœur de l'anti-répétition : la situation est connue et stable ou
      s'améliore légèrement, inutile de re-sonner) ;
    - si la date de franchissement recalculée **avance strictement** (la situation s'aggrave — le
      découvert arriverait plus tôt qu'annoncé) → un **nouvel** événement `treasury.threshold_breached`
      est émis sur la **même** `TreasuryCashAlert` (mise à jour, pas de nouvelle ligne), `lastNotifiedAt
      = now` — même logique que `payment.incident_reopened` déjà présente dans `NotificationRule` pour
      un cas structurellement proche (une situation déjà signalée qui s'aggrave mérite un nouveau
      signal, une qui se stabilise n'en mérite pas un second).
  - **Aucun franchissement détecté** (§4.2 point 5) **et une alerte `open` existe** → elle passe à
    `status = resolved`, `resolvedAt = now`, **sans** événement ni notification (§2, exclu — annoncer la
    résorption n'est pas demandé).
  - **Désactivation du seuil** (`TreasurySettingsProcessor` reçoit `cashAlertThresholdCents = null` en
    `PATCH`) — résout silencieusement, dans le même appel, toute `TreasuryCashAlert` `open` de cet
    établissement (sinon une alerte resterait `open` indéfiniment, orpheline d'un seuil qui n'existe
    plus, et la commande ne la reverrait jamais puisqu'elle ignore les établissements sans seuil
    configuré, §4.2 point 1).

### 4.5 Remise — la Cloche, patron déjà en place (RG-TRE-14/15)
- **RG-TRE-14** — L'événement `treasury.threshold_breached` (tenant = établissement de la
  `TreasuryCashAlert`, D6 — jamais le contexte HTTP, la commande n'en a d'ailleurs aucun ; sujet =
  `TreasuryCashAlert`/id ; acteur = `null`, système) alimente la Cloche par le **même mécanisme déjà
  câblé** pour `treasury.discrepancy_detected` : une entrée ajoutée à la table de
  `App\Platform\Notification\NotificationRule` (aucun nouveau code de tuyauterie — `NotifyOnDomainEvent`
  s'abonne déjà dynamiquement à `NotificationRule::admittedEvents()`), destinataires résolus par
  `NotificationRecipientResolver` sur le couple `finance`/`read` (ceux qui peuvent consulter la
  trésorerie de cet établissement, §3).
  - **Gravité** : `NotificationSeverity::Warning` (« se planifie ») — ⚠ HYPOTHÈSE à confirmer : un
    franchissement projeté laisse par construction au moins un jour d'anticipation (sinon il serait déjà
    survenu, auquel cas c'est `treasury.discrepancy_detected` ou simplement la position actuelle qui le
    montre) — c'est une situation à **planifier**, pas une interruption en cours. `Critical` réservé aux
    faits déjà là (cohérent avec le commentaire existant sur `treasury.discrepancy_detected`, « le seul
    niveau critique du tableau » — ce commentaire devient inexact dès qu'une seconde règle `Critical`
    apparaîtrait ; en choisissant `Warning` ici, cette spec évite de le rendre faux, §8 point 2).
  - **Titre/texte** : réutilise `NotificationRule::$anchorKey` (mécanisme déjà écrit,
    `NotifyOnDomainEvent::titreAncre()`) avec `anchorKey = 'projectedBreachDate'` — le titre affiché
    devient « Seuil de trésorerie bientôt franchi — 2026-09-15 », le texte nomme la cause si connue («
    Le solde projeté passerait sous le seuil avant l'échéance fournisseur de … »).
  - **Écran de destination** : `screen = 'finance'`, `paramName` porte l'id de la `TreasuryCashAlert`.
    ⚠ **Limitation connue, non corrigée par cette spec** : `frontend/src/pages/Finance.jsx` gère son
    onglet actif avec un `useState` local, pas encore avec `useEtatUrl` (contrairement à d'autres écrans
    du dépôt qui synchronisent leur état avec le hash, `frontend/src/api/url.js`) — un clic sur la
    notification ouvre la page Finance mais pas nécessairement l'onglet « Position & prévision ». Gap
    pré-existant (même famille que ce qui a justifié `TresorerieDashboard.jsx`, T26), hors périmètre de
    cette spec — signalé pour ne pas être découvert en test d'intégration comme une régression de cette
    fonctionnalité.
- **RG-TRE-15** — `treasury.threshold_breached` est ajouté au catalogue partagé
  (`COORDINATION/CONTRACT/catalogue-evenements.md`, ligne `Treasury`) et à
  `App\Finance\FinanceModule::eventsEmitted()` (4ᵉ extension du fichier partagé — même précaution que
  les trois précédentes, §0.11 de `plan-treasury.md` : relire l'état réel avant d'étendre, jamais un
  merge aveugle).

### 4.6 Ordonnancement — la commande doit réellement tourner (RG-TRE-16)
- **RG-TRE-16** — `finance:treasury:verifier-seuils` est déclarée dans
  `App\Platform\Scheduling\ScheduleCatalog::all()`, fréquence quotidienne (`everyMinutes: 1440`, même
  cadence que `sepa:preavis:annoncer`/`vente:cloture:journee` — la trajectoire de trésorerie ne bouge
  pas assez vite intrajournalier pour justifier plus fréquent), `critical: true` (une régie qui ne
  découvre son découvert que le jour où il survient est exactement le défaut que cette fonctionnalité
  corrige), **`safeOnFirstRun: false`** — la commande **notifie une personne réelle** (cas 3 de la
  classification déjà posée par `ScheduleCatalog` : « effet visible au dehors »), même famille que
  `sepa:preavis:annoncer`/`reporting:executer-rapports` : un premier passage sur un parc où le seuil
  serait activé après coup sur des établissements déjà en tension enverrait une salve d'alertes
  simultanées à superviser, pas à lancer en silence.
  - ⚠ **Constat additionnel, hors périmètre de correction par cette spec** : au moment de sa rédaction,
    `finance:treasury:detecter-ecarts` et `finance:treasury:suggerer-rapprochements` — **déjà codées et
    livrées** — ne figurent **pas** dans `ScheduleCatalog::all()` (vérifié : aucune occurrence de
    `treasury` dans ce fichier). Elles ne s'exécutent donc **jamais** aujourd'hui, exactement le défaut
    que le fichier lui-même documente en préambule (« vingt-deux commandes de domaine, aucun
    ordonnanceur »). Cette spec n'a pas vocation à réparer cet oubli préexistant, mais la nouvelle
    commande **ne doit pas le reproduire** : son enregistrement dans `ScheduleCatalog` fait partie de la
    définition de fait de cette fonctionnalité, pas d'une tâche annexe (§8 point 4).

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **`TreasurySettings`** *(existante, étendue)* | cashAlertThresholdCents | int? | nullable, défaut `null` | RG-TRE-10, `null` = désactivé, valeur négative acceptée (découvert) |
| | cashAlertHorizonDays | int | `PositiveOrZero`, défaut `30` | RG-TRE-10 |
| **`TreasuryCashAlert`** *(nouvelle, `App\Finance\Treasury\Entity`)* | id | uuid | PK | — |
| | establishment | ref Etablissement | requis, index | ancre de cloisonnement (D6/D8), une `open` au plus par établissement (unicité applicative) |
| | status | enum `CashAlertStatus` {open, resolved} | défaut `open` | RG-TRE-13 |
| | thresholdCentsAtDetection | int | requis | copie du seuil au moment de la détection — un seuil modifié après coup ne réécrit pas l'historique |
| | horizonDaysAtDetection | int | requis | idem, copie |
| | projectedBreachDate | date | requis | RG-TRE-11 |
| | projectedBalanceCents | int | requis | solde cumulé projeté à `projectedBreachDate`, `< thresholdCentsAtDetection` |
| | causeSource | string? | nullable, valeurs alignées sur `PaymentScheduleCalculator` (`supplier_invoice`, …) | RG-TRE-12 |
| | causeSourceId | uuid? | nullable, requis si `causeSource` non nul | RG-TRE-12 |
| | causeAmountCents | int? | nullable, requis si `causeSource` non nul | RG-TRE-12 |
| | detectedAt | datetime | requis, immuable | première détection de cette occurrence |
| | lastCheckedAt | datetime | requis | mis à jour à chaque passage tant que `open` |
| | lastNotifiedAt | datetime? | nullable | dernière émission de `treasury.threshold_breached` pour cette alerte, RG-TRE-13 |
| | resolvedAt | datetime? | nullable, requis si `status = resolved` | RG-TRE-13 |
| *(non persisté)* `ThresholdBreachProjection` | breachDate?, balanceCentsAtBreach?, cause? | date?, int?, {source, sourceId, amountCents}? | calculé | résultat intermédiaire du nouveau calculateur, §4.2 |

> id = UUID (`symfony/uid`), cohérent avec le reste de `App\Finance\Treasury`. `establishment` **direct**
> sur `TreasuryCashAlert` (même ancre que `BankAccount`/`TreasurySettings`, pas une chaîne de jointure —
> l'alerte est un fait de premier niveau, pas une sous-ressource).

## 6. API

| Ressource / route | Opération | `security:` | Provider/Processor | Notes |
|---|---|---|---|---|
| `TreasurySettings` | `PATCH /finance/treasury/treasury-settings/{id}` | `finance.manage` | `TreasurySettingsProcessor` *(existant, étendu — aucune nouvelle route)* | accepte désormais `cashAlertThresholdCents`/`cashAlertHorizonDays` ; un `PATCH` posant `cashAlertThresholdCents = null` résout les alertes `open` de l'établissement (§4.4) |
| `TreasuryCashAlert` | `GetCollection`, `Get` | `finance.read` | — (filtrée par établissement, extension `PerimetreFinanceExtension` étendue à cette 5ᵉ ressource, §7) | lecture seule — aucune écriture via l'API, l'entité n'est gérée que par la commande planifiée (même philosophie que `BankStatementLine.discrepancyNotifiedAt`, jamais exposée en écriture directe) |
| — | *(aucune nouvelle vue calculée)* | — | — | la projection jour par jour (§4.2) est un calcul **interne** à la commande, pas un nouvel endpoint public — la mission demande une alerte poussée, pas une nouvelle lecture à ouvrir |

**Filtres** (`ApiFilter(SearchFilter::class, …)`) : `TreasuryCashAlert` → `status` exact.

**Non exposé** : `Post`/`Patch`/`Delete` sur `TreasuryCashAlert` — comme `BankStatementLine`
(constitution, `RG-SOCLE-07`, jamais de suppression d'un fait qui a eu lieu).

## 7. Sécurité / cloisonnement

- **Cloisonnement en lecture** — `TreasuryCashAlert` rejoint `PerimetreFinanceExtension`
  (`App\Finance\Treasury\Doctrine\PerimetreFinanceExtension`, déjà livrée), chaîne à zéro saut
  (`establishment` direct) : **4ᵉ ressource** de cette extension (`BankAccount`, `BankStatementImport`,
  `BankStatementLine`, `TreasurySettings`, désormais `TreasuryCashAlert`) — même recommandation de
  promotion vers un patron partagé déjà réitérée trois fois par `plan-treasury.md` §7 points 1/3,
  toujours pas traitée : à noter une 4ᵉ fois plutôt qu'à ignorer.
- **Cloisonnement de la commande planifiée** — `finance:treasury:verifier-seuils` n'a **aucun** contexte
  HTTP (comme `finance:treasury:detecter-ecarts`) : elle parcourt les établissements par leurs données
  (`TreasurySettings.establishment`), jamais par `ContexteEtablissement::idActif()` (D6, échec fermé
  techniquement obligatoire, pas seulement conforme).
- **Cloisonnement de la notification** — hérité intégralement de `NotificationRecipientResolver` déjà
  livré : seuls les utilisateurs **affectés** à l'établissement de la `TreasuryCashAlert` et détenant
  `finance.read` sur ce périmètre reçoivent la notification (double cloisonnement — destinataire ET
  établissement — déjà documenté par `App\Platform\Entity\Notification`, non modifié ici).
- **Aucun secret dans l'événement** — le payload de `treasury.threshold_breached` ne porte que des
  identifiants et des montants (`thresholdCents`, `projectedBreachDate`, `projectedBalanceCents`,
  `horizonDays`, `causeSource`, `causeSourceId`, `causeAmountCents`) ; aucun IBAN, aucune donnée
  personnelle — `DomainEvent::FORBIDDEN_PAYLOAD_KEYS` le refuserait de toute façon (défense en
  profondeur déjà en place, RG-PLAT-04).

## 8. Risques / à valider

1. **Granularité établissement, pas compte bancaire** (§4.1) — décision justifiée par la cohérence avec
   `TreasuryPositionCalculator`/`PaymentScheduleCalculator` déjà agrégés à ce niveau ; à confirmer que
   c'est bien ce qu'attend le métier (« votre solde » au singulier dans l'exemple de la mission va dans
   ce sens).
2. **Gravité `Warning`, pas `Critical`** (§4.5) — hypothèse posée par cohérence avec la sémantique
   « Warning se planifie / Critical s'interrompt » déjà établie par `NotificationSeverity` ; le
   commentaire existant sur la règle `treasury.discrepancy_detected` (« le seul niveau critique du
   tableau ») devient à mettre à jour dès qu'une décision différente serait prise ici.
3. **`Finance.jsx` ne restitue pas encore son onglet actif depuis l'URL** (§4.5) — la notification mène
   à la bonne page, pas garanti au bon onglet ; gap pré-existant, non corrigé par cette spec, à traiter
   au plan technique si jugé bloquant pour l'utilité réelle de l'alerte.
4. **`finance:treasury:detecter-ecarts`/`finance:treasury:suggerer-rapprochements` absentes de
   `ScheduleCatalog` aujourd'hui** (§4.6) — constat fait en préparant cette spec, non corrigé ici (hors
   périmètre), mais la nouvelle commande ne doit pas rejoindre cette liste par omission.
5. **Aucun canal courriel** (§2) — si le métier juge la Cloche insuffisante pour une alerte de ce
   niveau d'importance (un exploitant qui ne se connecte pas un jour donné ne verrait rien), un canal
   courriel nécessiterait un premier abonné email sur le bus d'événements, qui n'existe pas encore dans
   ce dépôt — à chiffrer séparément si retenu.
6. **Seuil négatif (découvert autorisé)** (§4.1) — accepté sans contrainte de signe ; à confirmer que
   `cashAlertThresholdCents` est le bon endroit pour porter cette notion plutôt qu'un champ dédié
   `overdraftLimitCents` séparé du seuil d'alerte proprement dit (les deux pourraient diverger : vouloir
   être alerté *avant* d'atteindre le plafond de découvert, pas seulement en le dépassant).
7. **Ré-alerte dès que la date avance d'un seul jour** (§4.4) — pas de marge de tolérance ; si le calcul
   d'échéancier oscille d'un jour d'une exécution à l'autre en fin de fenêtre (un import de relevé
   modifie légèrement la position actuelle), l'anti-répétition pourrait re-notifier plus souvent que
   souhaité. Aucune marge n'est ajoutée par cette spec (simplicité) ; à surveiller en usage réel.
8. **⚠ HORS BACKLOG** — comme tout `spec-treasury.md`, à faire valider/chiffrer avant développement.

## 9. Tests

| Test | Type | Couvre |
|---|---|---|
| `TreasurySettingsApiTest::testSeuilNulDesactiveLaFonctionnalite` | Fonctionnel API | RG-TRE-10 — `cashAlertThresholdCents = null` (défaut) → la commande ignore l'établissement |
| `TreasurySettingsApiTest::testSeuilNegatifAccepte` | Fonctionnel API | §4.1 — découvert autorisé |
| `VerifierSeuilsCommandTest::testFranchissementDetecteCreeUneAlerte` | Fonctionnel (CLI) | **RG-TRE-11**, scénario de la mission (solde 5000 €, sortie 6000 € à J+12, fenêtre 30 j) → `projectedBreachDate = J+12` |
| `VerifierSeuilsCommandTest::testAucuneSortieAvantLeFranchissementNAlerteRienDeVide` | Unit | §4.2 point 5 — solde qui décroît sans grosse échéance isolée |
| `VerifierSeuilsCommandTest::testEgaliteAuSeuilNestPasUnFranchissement` | Unit | RG-TRE-11 point 5 — strictement inférieur |
| `CausePrincipaleTest::testPlusGrosseSortieAvantLaDateRetenue` | Unit | **RG-TRE-12** — deux sorties, la plus grosse antérieure ou égale à la date de franchissement l'emporte |
| `CausePrincipaleTest::testAucuneCauseSiAucuneSortieAvantLeFranchissement` | Unit | RG-TRE-12 — dégradation propre |
| **`VerifierSeuilsCommandTest::testDeuxPassagesMemeDateUneSeuleNotification`** | Fonctionnel (CLI) | **RG-TRE-13** — deux exécutions successives sans aggravation → une seule `TreasuryCashAlert`, un seul événement |
| `VerifierSeuilsCommandTest::testDateQuiAvanceReemetUnEvenement` | Fonctionnel (CLI) | RG-TRE-13 — aggravation → second événement sur la même alerte |
| `VerifierSeuilsCommandTest::testAbsenceDeFranchissementResoutLAlerteSansEvenement` | Fonctionnel (CLI) | RG-TRE-13 — résolution silencieuse |
| `TreasurySettingsProcessorTest::testDesactivationDuSeuilResoutLesAlertesOuvertes` | Fonctionnel API | §4.4 dernier point |
| **`NotificationTreasuryThresholdTest::testDestinatairesLimitesAFinanceRead`** | Fonctionnel API | RG-TRE-14 — la Cloche de chaque titulaire de `finance.read` sur l'établissement reçoit l'alerte, aucun autre |
| `NotificationTreasuryThresholdTest::testGraviteWarning` | Unit | §4.5 |
| `CloisonnementTreasuryCashAlertTest::testAlerteDunAutreEtablissementInvisible` | Fonctionnel API | §7 — 5ᵉ ressource de `PerimetreFinanceExtension` |
| `EventTenantTreasuryThresholdTest::testTenantDeriveDeLetablissementDeLalerte` | Unit | D6 — même patron que FIN-2/FIN-3/FIN-4 |
| `ScheduleCatalogTest::testVerifierSeuilsEnregistreeEtNonSurAutomatique` | Unit | RG-TRE-16 — présente dans `ScheduleCatalog::all()`, `safeOnFirstRun = false` |

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ HORS BACKLOG** — évolution non demandée par le backlog existant (en-tête).
2. Seuil par établissement plutôt que par compte bancaire — décision justifiée, pas une hypothèse
   ouverte, mais à confirmer avec le métier (§8 point 1).
3. Gravité `Warning` plutôt que `Critical` (§8 point 2).
4. `Finance.jsx` ne restitue pas encore l'onglet actif depuis l'URL — limitation héritée, non corrigée
   (§8 point 3).
5. `finance:treasury:detecter-ecarts`/`finance:treasury:suggerer-rapprochements` absentes de
   `ScheduleCatalog` — constat, non corrigé par cette spec (§8 point 4).
6. Canal courriel — non retenu v1, aucun abonné email sur le bus aujourd'hui (§8 point 5).
7. `cashAlertThresholdCents` négatif comme représentation du découvert autorisé — à confirmer (§8
   point 6).
8. Absence de marge de tolérance sur l'anti-répétition (ré-alerte dès -1 jour) — à surveiller (§8
   point 7).

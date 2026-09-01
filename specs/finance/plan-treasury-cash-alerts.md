# Plan technique — Alertes de trésorerie proactives (`App\Finance\Treasury`, addendum FIN-4)

- **Spec source :** specs/finance/spec-treasury-cash-alerts.md (+ specs/finance/spec-treasury.md pour le
  socle réutilisé)
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Statut :** proposé.
- **Contrat de plateforme :** COORDINATION/CONTRACT/catalogue-evenements.md (`treasury.*` y figure déjà
  pour `.reconciliation_completed`/`.discrepancy_detected` au moment de la rédaction — `.threshold_breached`
  **absent**, à ajouter, §4/§7 point 2), COORDINATION/CONTRACT/noyau-commun.md (invariants #1/#2/#3/#7),
  COORDINATION/DECISIONS.md **D5/D6/D7/D8**
- **Dépend de (déjà livré, réutilisé tel quel, aucune duplication) :**
  `App\Finance\Treasury\Service\TreasuryPositionCalculator::position()`,
  `App\Finance\Treasury\Service\PaymentScheduleCalculator::echeancier()` (les deux déjà factorisés hors
  des providers HTTP précisément pour être réutilisés par un second calculateur, doc-bloc de
  `CashflowForecastCalculator`), `App\Finance\Treasury\Entity\TreasurySettings`
  (`App\Finance\Treasury\State\TreasurySettingsProcessor`, `finance.manage`),
  `App\Finance\Treasury\Doctrine\PerimetreFinanceExtension` (étendue à une **5ᵉ** ressource),
  `App\Platform\Notification\{NotificationRule,NotifyOnDomainEvent,NotificationRecipientResolver}`,
  `App\Platform\Scheduling\{ScheduleCatalog,ScheduledTask}`, `App\Platform\Event\{EventBus,DomainEvent,
  EventTenant,EventSubject}`, `App\Finance\FinanceModule` (manifeste, **4ᵉ extension** du même fichier
  partagé — FIN-2/FIN-3/FIN-4 l'ont déjà étendu trois fois)
- **Couvre :** US-TRE-11 *(hors backlog)* · RG-TRE-10 à RG-TRE-16

> **Note de méthode.** Ce plan n'ouvre **aucun** nouveau module et ne crée **aucune** nouvelle permission :
> il étend une entité existante (`TreasurySettings`), ajoute une entité (`TreasuryCashAlert`), un
> calculateur, une commande planifiée et un événement, tous dans `App\Finance\Treasury` déjà livré (code
> lu directement dans `app/src/Finance/Treasury/`, pas supposé). Le code de FIN-4 existe déjà dans le
> dépôt au moment de la rédaction de ce plan (`TreasuryPositionCalculator`, `PaymentScheduleCalculator`,
> `CashflowForecastCalculator`, `DetecterEcartsCommand`, `PerimetreFinanceExtension`,
> `TreasurySettingsProcessor` — tous vérifiés) : ce plan calque son patron d'implémentation sur
> `DetecterEcartsCommand` (§0.9 de `plan-treasury.md`), le seul exemple déjà en place de « commande
> planifiée + garde d'idempotence + émission d'événement système ».

---

## 0. Décisions d'architecture

### 0.1 Portée — extension, pas de nouvelle brique

Tout le code nouveau vit dans `App\Finance\Treasury\{Entity,Enum,Dto,Service,Command}`, aux côtés du code
FIN-4 déjà livré. Aucune classe n'est ajoutée dans `App\Compta`/`App\Facturation`/`App\Sepa` (lus, jamais
modifiés — même règle que `plan-treasury.md` §0.1). `PerimetreFinanceExtension` (`App\Finance\Treasury\
Doctrine`) est **étendue** (une ligne de plus dans `self::CHAINES`), pas dupliquée.

### 0.2 `TreasurySettings` — deux colonnes additives, compatibles avec le code qui tourne encore

RG-TRE-10. Deux champs ajoutés à l'entité existante :
- `cashAlertThresholdCents` (`int`, nullable, colonne `cash_alert_threshold_cents INT DEFAULT NULL`,
  **aucun** `options: ['default' => ...]` côté mapping puisque `null` **est** le défaut — cohérent avec
  la leçon de `Version20260831210000`/`Version20260901020000` (colonne nouvelle nullable, compatible avec
  le code déployé avant elle qui ne la connaît pas).
- `cashAlertHorizonDays` (`int`, colonne `cash_alert_horizon_days INT NOT NULL DEFAULT 30`, mapping
  `options: ['default' => TreasurySettings::DEFAULT_CASH_ALERT_HORIZON_DAYS]` — même patron que
  `DEFAULT_UNMATCHED_ALERT_DELAY_DAYS`/`DEFAULT_MATCHING_WINDOW_DAYS` déjà sur la classe), contrainte
  `#[Assert\PositiveOrZero]` (même choix que les deux constantes existantes : `0` reste une valeur
  légitime, fenêtre d'anticipation nulle = alerte seulement si le seuil est *déjà* franchi aujourd'hui —
  couvert par le même calcul, pas un cas à exclure).

Aucune contrainte de signe sur `cashAlertThresholdCents` (§4.1/§8 point 6 de la spec, découvert autorisé
accepté tel quel — ce plan ne tranche pas la question ouverte d'un futur champ `overdraftLimitCents`
séparé, hors périmètre).

### 0.3 `TreasuryCashAlert` — entité nouvelle, jamais écrite par l'API

RG-TRE-13, §5 de la spec. Lecture seule côté API (`GetCollection`/`Get` uniquement, `finance.read`) : la
seule plume qui écrit cette entité est la commande planifiée (§0.5) et — pour la résolution silencieuse
sur désactivation — `TreasurySettingsProcessor` (§0.6). Même philosophie que
`BankStatementLine.discrepancyNotifiedAt` : un fait produit par le système, jamais par un appelant HTTP.

**Anti-répétition — contrainte élevée au niveau base, pas seulement applicative.** La spec (§5) qualifie
« au plus une alerte `open` par établissement » de « contrainte d'unicité **applicative** ». Ce plan va un
cran plus loin, dans l'esprit déjà retenu ailleurs dans ce dépôt pour ce type de garde (ex.
`BankStatementImport` : `UNIQUE(bank_account_id, content_hash)` — §0.5 de `plan-treasury.md`) : une colonne
générée virtuelle `open_establishment_id` (`GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN
establishment_id END) VIRTUAL`) portant une contrainte `UNIQUE` — MariaDB (comme MySQL) exclut les valeurs
`NULL` d'un index unique, donc seules les lignes `open` sont soumises à l'unicité, les lignes `resolved`
ne comptent jamais. Avantage : même si un futur appelant (bug, script, second worker du scheduler)
tentait de créer une seconde alerte `open` pour le même établissement, la base refuserait l'écriture au
lieu de dépendre uniquement d'un `findOneBy()` applicatif fait juste avant (fenêtre de course théorique
dans un `wrapInTransaction()` séquentiel, jamais concurrente en pratique puisqu'une seule commande CLI
traite les établissements l'un après l'autre — mais la garde ne coûte rien et documente l'invariant en
base, pas seulement en PHP). **À valider en CI** (comportement des colonnes générées virtuelles + index
unique sur MariaDB 11.4, §7 point 4).

`causeSource`/`causeSourceId`/`causeAmountCents` : **pas** de contrainte `FK` sur `causeSourceId` — comme
`PaymentScheduleCalculator::echeancier()['exits'][]['sourceId']` dont elle est la copie, c'est une
référence polymorphe (`supplier_invoice` aujourd'hui, une autre source de sortie demain sans migration),
jamais un pointeur Doctrine vers `SupplierInvoice` (qui obligerait cette entité de `App\Finance\Treasury`
à référencer directement `App\Finance\SupplierInvoice`, couplage que ni `BankStatementLine`, ni
`PaymentScheduleCalculator` ne font aujourd'hui — cohérence délibérée avec l'existant).

### 0.4 `ThresholdBreachProjectionCalculator` — nouveau calculateur, réutilise les deux existants

RG-TRE-11, §4.2/§4.3 de la spec. `App\Finance\Treasury\Service\ThresholdBreachProjectionCalculator` :

```php
final class ThresholdBreachProjectionCalculator
{
    public function __construct(
        private readonly TreasuryPositionCalculator $positionCalculator,
        private readonly PaymentScheduleCalculator $scheduleCalculator,
    ) {}

    public function projeter(
        Etablissement $etablissement,
        \DateTimeImmutable $aujourdhui,
        int $thresholdCents,
        int $horizonDays,
    ): ThresholdBreachProjection { /* ... */ }
}
```

Algorithme (aucun second calcul de solde/échéancier — mêmes deux services que `CashflowForecastCalculator`
réutilise déjà tels quels) :
1. `$solde = $this->positionCalculator->position([$etablissement->getId()->toBinary()], $aujourdhui)['balanceCents']`.
2. `$echeancier = $this->scheduleCalculator->echeancier([$etablissement->getId()->toBinary()], $aujourdhui,
   $aujourdhui->modify("+{$horizonDays} days"))`.
3. Fusionne `entries[]` (signe `+`) et `exits[]` (signe `-`) en une seule liste de mouvements datés, ignore
   tout mouvement dont `date` est `null` (cas limite déjà documenté par `PaymentScheduleCalculator`, un
   type de retour `?string`) — un mouvement sans date ne peut pas être placé dans la projection jour par
   jour, il ne participe donc ni à la trajectoire ni à la recherche de franchissement (dégradation propre,
   pas une exception).
4. Trie par date croissante (tri stable — PHP `usort` n'est **pas** garanti stable avant 8.0, stable depuis
   8.0+, le dépôt est en 8.4, acquis) ; regroupe les mouvements de même date pour cumuler en une seule
   étape par date (la granularité est le jour, pas l'événement — RG-TRE-11 parle de « première date »).
5. Cumule `$solde` mouvement de date en date ; dès que le cumul devient **strictement** inférieur à
   `$thresholdCents` (RG-TRE-11 point 5, égalité stricte non incluse), retient cette date comme
   `breachDate`, ce cumul comme `balanceCentsAtBreach`, s'arrête (pas la peine de poursuivre la
   projection au-delà de la première date trouvée).
6. Aucune date trouvée dans tout l'horizon → `ThresholdBreachProjection` vide (`breachDate = null`).
7. Cause principale (RG-TRE-12) : parmi `exits[]` (uniquement, jamais `entries[]` — l'échéancier ne classe
   que `SupplierInvoice` en sortie, RG-TRE-06, donc `causeSource` ne peut valoir que `supplier_invoice`
   aujourd'hui, cohérent avec la spec) dont `date <= breachDate`, retient celle au `amountCents` maximal ;
   égalité de montant → la plus ancienne par date (tri stable préservant l'ordre naturel des dates
   croissantes appliqué en amont couvre déjà ce cas — pas de second tri nécessaire). Aucune sortie
   éligible → `causeSource = null` (§4.3, dégradation propre).

`ThresholdBreachProjection` (`App\Finance\Treasury\Dto`, non persisté, `readonly`) :
`breachDate: ?\DateTimeImmutable`, `balanceCentsAtBreach: ?int`, `causeSource: ?string`,
`causeSourceId: ?string`, `causeAmountCents: ?int`.

### 0.5 `finance:treasury:verifier-seuils` — commande planifiée, patron `DetecterEcartsCommand`

RG-TRE-11/RG-TRE-13, §4.2/§4.4 de la spec. `App\Finance\Treasury\Command\VerifierSeuilsCommand` :

1. Requête `TreasurySettings` où `cashAlertThresholdCents IS NOT NULL` (jointure implicite vers
   `establishment`) — **jamais** `ContexteEtablissement::idActif()` (D6, la commande n'a d'ailleurs aucun
   contexte HTTP, même remarque que `DetecterEcartsCommand`).
2. Pour chaque réglage : `$projection = $calculator->projeter($etablissement, $aujourdhui,
   $settings->getCashAlertThresholdCents(), $settings->getCashAlertHorizonDays())`.
3. `$alerteOuverte = $repository->findOneBy(['establishment' => $etablissement, 'status' =>
   CashAlertStatus::Open])`.
4. **Aucun franchissement** (`$projection->breachDate === null`) :
   - `$alerteOuverte` existe → `status = Resolved`, `resolvedAt = $maintenant`, **aucun** événement
     (§4.4 dernier cas avant désactivation, résolution silencieuse).
   - sinon → rien à faire.
5. **Franchissement trouvé** :
   - `$alerteOuverte === null` → nouvelle `TreasuryCashAlert` : `status = Open`,
     `thresholdCentsAtDetection`/`horizonDaysAtDetection` = valeurs **actuelles** des réglages,
     `projectedBreachDate`/`projectedBalanceCents`/`causeSource*` = la projection, `detectedAt =
     lastCheckedAt = lastNotifiedAt = $maintenant` → persist, flush, **émet** `treasury.threshold_breached`
     (§0.7).
   - `$alerteOuverte !== null` → compare `$projection->breachDate` à la valeur **actuellement stockée**
     `$alerteOuverte->getProjectedBreachDate()` (c'est-à-dire la date telle qu'elle était **avant** la
     mise à jour de ce passage — voir note ci-dessous) :
     - `$projection->breachDate >= $ancienneDate` (identique ou plus tardive — situation stable ou qui
       s'améliore) → met à jour `projectedBreachDate`/`projectedBalanceCents`/`causeSource*`,
       `lastCheckedAt = $maintenant`, **ne touche pas** `lastNotifiedAt`, **aucun** événement.
     - `$projection->breachDate < $ancienneDate` (avance strictement — aggravation) → **mêmes** mises à
       jour de champs **+** `lastNotifiedAt = $maintenant`, **émet** un nouvel événement
       `treasury.threshold_breached` sur la **même** entité (pas de nouvelle ligne).
   - **Dans les deux branches**, `thresholdCentsAtDetection`/`horizonDaysAtDetection` restent **inchangés**
     (copiés une seule fois, à la création — « un seuil modifié après coup ne réécrit pas l'historique »,
     §5 de la spec, tableau).
6. Chaque établissement est traité dans son **propre** `wrapInTransaction()` (flush + publish réunis, même
   patron que `DetecterEcartsCommand` §0.9 de `plan-treasury.md`) : un abonné qui échoue sur un
   établissement n'empêche pas les suivants d'être traités.

> **Précision sur « date de la dernière notification » (§4.4 de la spec).** La spec compare la nouvelle
> date à « celle de la dernière notification ». En pratique, `projectedBreachDate` est mis à jour à
> **chaque** passage (silencieux ou notifiant) — la valeur stockée juste avant ce passage reflète donc
> toujours la dernière date **connue**, que ce soit via une mise à jour silencieuse ou une notification.
> Comparer à la valeur stockée avant mise à jour est donc **strictement équivalent** à comparer à « la
> date de la dernière notification » : les deux ne peuvent jamais diverger, puisque chaque passage aligne
> l'un sur l'autre. Ce plan documente cette lecture explicitement pour qu'un futur agent ne réintroduise
> pas un second champ (« date lors de la dernière notification » distinct de `projectedBreachDate`) qui
> serait redondant.

**Idempotence.** Deux passages consécutifs sans changement de situation ne créent jamais de seconde
`TreasuryCashAlert` (contrainte DB §0.3) et n'émettent jamais de second événement (branche « identique »).

### 0.6 `TreasurySettingsProcessor` — résolution silencieuse à la désactivation

§4.4 dernier point de la spec. Après la logique existante (vérification de périmètre, contrôle
d'unicité à la création), ajout :

```php
if ($data->getCashAlertThresholdCents() === null) {
    $ouvertes = $this->em->getRepository(TreasuryCashAlert::class)->findBy([
        'establishment' => $data->getEstablishment(),
        'status' => CashAlertStatus::Open,
    ]);
    foreach ($ouvertes as $alerte) {
        $alerte->setStatus(CashAlertStatus::Resolved)->setResolvedAt($maintenant);
    }
}
```

Exécuté **avant** le `flush()` final du processor existant (même transaction implicite que la sauvegarde
des réglages). Idempotent (aucune alerte ouverte → boucle vide) et sans effet de bord si le seuil était
déjà `null` (cas `POST` initial ou `PATCH` qui ne change rien). **Aucun** événement, cohérent avec §2
« exclu » de la spec (pas de notification de retour à la normale).

### 0.7 Événement `treasury.threshold_breached` — tenant `TreasuryCashAlert.establishment` (D6)

RG-TRE-14/15, §4.5 de la spec.

| Champ | Valeur |
|---|---|
| `name` | `treasury.threshold_breached` |
| `tenant` | `new EventTenant($etablissement->getId())` — **jamais** `ContexteEtablissement` |
| `subject` | `new EventSubject('TreasuryCashAlert', (string) $alerte->getId())` |
| `actor` | `null` (système, commande planifiée) |
| `payload` | `thresholdCents`, `projectedBreachDate` (`Y-m-d`), `projectedBalanceCents`, `horizonDays`, `causeSource`, `causeSourceId`, `causeAmountCents` |

Aucune clé interdite (`DomainEvent::FORBIDDEN_PAYLOAD_KEYS` refuserait `iban`/`bic` de toute façon —
défense en profondeur déjà en place, non re-testée par ce plan spécifiquement, déjà couverte par les tests
plateforme existants du bus).

**Catalogue** — `COORDINATION/CONTRACT/catalogue-evenements.md`, ligne `Treasury` : ajouter une ligne
`treasury.threshold_breached` (`Emitted by: Treasury`, `Key payload: threshold, breach_date,
projected_balance, cause`, `Likely consumers: Accounting, Supervision`), à côté des deux lignes
`treasury.*` déjà présentes.

**`FinanceModule::eventsEmitted()`** (`app/src/Finance/FinanceModule.php`) — **4ᵉ extension** de ce fichier
partagé (après FIN-2/FIN-3/FIN-4) : ajoute `'treasury.threshold_breached'` au tableau existant, sans
retirer ni modifier les 9 événements déjà déclarés. Relire l'état réel du fichier avant de merger (même
recommandation réitérée par chaque extension précédente, §0.11 de `plan-treasury.md`).

### 0.8 Notification — nouvelle entrée `NotificationRule`, gravité `Warning`

RG-TRE-14, §4.5 de la spec. `App\Platform\Notification\NotificationRule::table()` (`app/src/Platform/
Notification/NotificationRule.php`) — ajoute, dans le tableau `$regles` :

```php
new self(
    'treasury.threshold_breached',
    NotificationSeverity::Warning,
    'finance',
    'read',
    'finance',
    'alert',
    'Seuil de trésorerie bientôt franchi',
    'Le solde projeté passerait sous le seuil configuré avant l’échéance connue. Consultez la '
    . 'position et l’échéancier pour décider : relancer, décaler un paiement, ou prévenir la '
    . 'collectivité.',
    'projectedBreachDate',
),
```

- **`module: 'finance', action: 'read'`** — résolution des destinataires via `NotificationRecipientResolver
  ::resolve($establishmentId, 'finance', 'read')`, qui s'appuie sur `CalculateurDroits::autorise()` en
  découpant le code de permission sur le point (`finance.read` → module `finance`, action `read`) : c'est
  la **même** granularité que le `security:` déjà posé sur `TreasuryCashAlert`/`BankAccount`/etc.
  (`is_granted('PERM', 'finance.read')`, §2). ⚠ **Écart constaté, non corrigé par ce plan** : la règle
  voisine `treasury.discrepancy_detected`, déjà en place, cible `module: 'compta', action: 'lire'` — un
  couple différent, cohérent avec le module `App\Compta` (qui utilise ses propres codes `compta.lire`/
  `compta.gerer`, vérifié dans plusieurs `#[ApiResource]` de ce module) plutôt qu'avec `App\Finance`. Ce
  plan **ne réutilise pas** ce couple pour `treasury.threshold_breached` — il cible explicitement
  `finance.read`, comme le demande §4.5 de la spec (« couple `finance`/`read`, ceux qui peuvent consulter
  la trésorerie de cet établissement »). Signalé pour qu'un futur agent ne s'étonne pas de voir deux
  couples différents sur deux règles `treasury.*` voisines — ce n'est pas une incohérence de ce plan, mais
  un héritage de la règle existante à confirmer séparément (§7 point 3).
- **Gravité `Warning`** (§4.5, hypothèse explicite de la spec, §8 point 2) — **contredit** le commentaire
  actuel sur `treasury.discrepancy_detected` (« le seul niveau critique du tableau ») dès que cette règle
  est ajoutée avec `Warning` : ce commentaire reste correct tel quel (il ne parlait que du niveau
  `Critical`, qui reste unique), mais ce plan **ne** le touche **pas** — signalé comme point de cohérence
  à revoir si `Warning` devait devenir `Critical` après validation métier (§7 point 1).
- **`screen: 'finance'`, `paramName: 'alert'`** — mène à la page `Finance.jsx`, `params: { alert: '<uuid
  de la TreasuryCashAlert>' }`. **Limitation héritée, non corrigée par ce plan** (§4.5 de la spec,
  reprise telle quelle) : `Finance.jsx` gère son onglet actif en `useState` local, pas via `useEtatUrl`
  (`frontend/src/api/url.js`) — le clic ouvre la bonne page, pas garanti le bon onglet « Position &
  prévision ». Hors périmètre de ce plan (§7 point 5).
- **`anchorKey: 'projectedBreachDate'`** — le titre affiché devient « Seuil de trésorerie bientôt franchi
  — 2026-09-15 » (`NotifyOnDomainEvent::titreAncre()`, mécanisme déjà écrit, aucune modification requise).

Aucune modification de `NotifyOnDomainEvent`/`NotificationRecipientResolver`/`Notification` : l'ajout
d'une ligne à `NotificationRule::table()` suffit — `NotifyOnDomainEvent::getSubscribedEvents()` s'abonne
déjà dynamiquement à `NotificationRule::admittedEvents()`.

### 0.9 Ordonnancement — `ScheduleCatalog`, `safeOnFirstRun: false`

RG-TRE-16, §4.6 de la spec. `App\Platform\Scheduling\ScheduleCatalog::all()` — ajout dans la section
« Argent et engagement client : un retard se voit par le client », à côté de `sepa:preavis:annoncer`
(même cadence, même justification de supervision au premier passage) :

```php
new ScheduledTask(
    'finance:treasury:verifier-seuils',
    1440,
    "Un seuil de tresorerie configure n'est jamais verifie. Un exploitant qui active l'alerte "
    . "decouvre alors son decouvert le jour ou il survient, exactement le defaut que cette "
    . "alerte proactive existe pour corriger.",
    critical: true,
    // JAMAIS SÛR AU PREMIER PASSAGE — effet visible au dehors (cas 3, sepa:preavis:annoncer) : la
    // commande notifie une personne reelle. Un premier passage sur un parc ou le seuil serait
    // active apres coup, sur des etablissements deja en tension, enverrait une salve d'alertes
    // simultanees a superviser, pas a lancer en silence (RG-TRE-16).
    safeOnFirstRun: false,
),
```

⚠ **Constat repris de la spec (§4.6/§8 point 4), non corrigé par ce plan** : `finance:treasury:
detecter-ecarts`/`finance:treasury:suggerer-rapprochements` (déjà codées) sont **absentes** de ce
catalogue au moment de la rédaction — vérifié (aucune occurrence de `treasury` avant l'ajout ci-dessus).
Ce plan **enregistre la nouvelle commande** (condition explicite de la spec, §2 « Inclus », dernier
point) mais ne répare pas l'oubli des deux commandes sœurs, hors périmètre (§7 point 6).

---

## 1. Entités & schéma

| Entité (`App\Finance\Treasury\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Notes |
|---|---|---|---|---|---|
| **`TreasurySettings`** *(existante, étendue)* | cashAlertThresholdCents | `integer` | **oui**, défaut `null` | — | RG-TRE-10, `null` = désactivé, valeur négative acceptée (découvert, §0.2) |
| | cashAlertHorizonDays | `integer` | non, défaut `30` | `#[Assert\PositiveOrZero]` | RG-TRE-10 |
| **`TreasuryCashAlert`** *(nouvelle)* | id | `uuid` | non | PK | — |
| | establishment | `uuid` (FK) | non | index (`establishment_id`) | `Etablissement` — ancre D6/D8, chaîne à zéro saut |
| | status | `string(10)` enum `CashAlertStatus` | non, défaut `open` | index (`status`) | `open`\|`resolved` |
| | openEstablishment *(généré, non mappé PHP)* | `binary(16)` **virtuel** | oui (`NULL` si `status != open`) | **`UNIQUE`** | §0.3 — au plus une ligne `open` par établissement, garanti en base |
| | thresholdCentsAtDetection | `integer` | non | — | copie figée à la création, jamais réécrite (§0.5) |
| | horizonDaysAtDetection | `integer` | non | — | idem |
| | projectedBreachDate | `date_immutable` | non | — | mis à jour à chaque passage (silencieux ou notifiant) |
| | projectedBalanceCents | `integer` | non | — | idem, `< thresholdCentsAtDetection` par construction |
| | causeSource | `string(32)` | **oui** | — | RG-TRE-12, valeurs alignées `PaymentScheduleCalculator` (`supplier_invoice` aujourd'hui) |
| | causeSourceId | `uuid` (sans FK, référence polymorphe) | **oui**, requis si `causeSource` non nul | — | §0.3 |
| | causeAmountCents | `integer` | **oui**, requis si `causeSource` non nul | — | — |
| | detectedAt | `datetime_immutable` | non, immuable | — | première détection de cette occurrence |
| | lastCheckedAt | `datetime_immutable` | non | — | mis à jour à **chaque** passage tant que `open` |
| | lastNotifiedAt | `datetime_immutable` | **oui** | — | mis à jour seulement quand un événement est émis |
| | resolvedAt | `datetime_immutable` | **oui**, requis si `status = resolved` | — | RG-TRE-13 |
| *(non persisté)* `ThresholdBreachProjection` (`App\Finance\Treasury\Dto`) | breachDate, balanceCentsAtBreach, causeSource, causeSourceId, causeAmountCents | `?\DateTimeImmutable`/`?int`/`?string`/`?string`/`?int` | — | résultat de `ThresholdBreachProjectionCalculator::projeter()`, §0.4 |

**Enum** `App\Finance\Treasury\Enum\CashAlertStatus` (valeurs anglaises, D5) : `Open = 'open'`,
`Resolved = 'resolved'`.

> id = UUID (`symfony/uid`). `establishment` **direct** sur `TreasuryCashAlert` (même ancre que
> `BankAccount`/`TreasurySettings`, pas une chaîne de jointure — l'alerte est un fait de premier niveau).

---

## 2. API (API Platform)

| Ressource / route | Opération | `security:` | Provider/Processor | Groupes sérialisation |
|---|---|---|---|---|
| `TreasurySettings` | `PATCH /finance/treasury/treasury-settings/{id}` | `finance.manage` | `TreasurySettingsProcessor` *(existant, étendu — §0.6, aucune nouvelle route)* | accepte désormais `cashAlertThresholdCents`/`cashAlertHorizonDays` en entrée ; `cashAlertThresholdCents: null` résout silencieusement les alertes `open` de l'établissement |
| `TreasuryCashAlert` | `GetCollection`, `Get` | `finance.read` | — (filtrée par établissement, `PerimetreFinanceExtension`, **5ᵉ** ressource) | `treasury_cash_alert:read` — **aucune** écriture via l'API |
| — | *(aucune nouvelle vue calculée)* | — | — | la projection jour par jour est un calcul **interne** à la commande, pas un endpoint public (§2 « exclu » de la spec) |

**Filtres** (`ApiFilter(SearchFilter::class, …)`) : `TreasuryCashAlert` → `status` exact.

**Non exposé** : `Post`/`Patch`/`Delete` sur `TreasuryCashAlert` — même règle que `BankStatementLine`
(constitution, jamais de suppression d'un fait qui a eu lieu ; jamais d'écriture directe d'un objet
produit exclusivement par la commande planifiée).

---

## 3. Sécurité & droits

- **Permissions consommées, aucune créée** : `finance.read` (lecture `TreasuryCashAlert`, réception de la
  notification via `NotificationRecipientResolver`), `finance.manage` (configuration du seuil sur
  `TreasurySettings`, déjà en place). Conforme à §3 de la spec.
- **Voters** : aucun voter dédié — `PermissionVoter` existant suffit.
- **Cloisonnement en lecture** — `TreasuryCashAlert` rejoint `App\Finance\Treasury\Doctrine\
  PerimetreFinanceExtension::CHAINES` avec `TreasuryCashAlert::class => []` (établissement direct, chaîne
  à zéro saut, même patron que `BankAccount`/`TreasurySettings`) : **5ᵉ** ressource de cette extension
  (`BankAccount`, `BankStatementImport`, `BankStatementLine`, `TreasurySettings`, désormais
  `TreasuryCashAlert`) — même recommandation de promotion vers un patron partagé déjà réitérée quatre fois
  par `plan-treasury.md`/`plan-supplier-invoices.md`, toujours pas traitée : à noter une 5ᵉ fois (§7
  point 7).
- **Cloisonnement de la commande planifiée** — `finance:treasury:verifier-seuils` n'a **aucun** contexte
  HTTP (comme `finance:treasury:detecter-ecarts`) : elle parcourt les établissements par
  `TreasurySettings.establishment` (donnée), jamais par `ContexteEtablissement::idActif()` (D6, échec
  fermé techniquement obligatoire — la commande n'a pas de session HTTP dans laquelle lire un
  établissement actif).
- **Cloisonnement de la notification** — hérité intégralement de `NotificationRecipientResolver` déjà
  livré : seuls les utilisateurs **affectés** à l'établissement de la `TreasuryCashAlert` et détenant
  `finance.read` sur ce périmètre reçoivent la notification (double cloisonnement — destinataire ET
  établissement — déjà documenté par `App\Platform\Entity\Notification`, non modifié ici).
- **Aucun secret dans l'événement** — le payload de `treasury.threshold_breached` ne porte que des
  identifiants et des montants (§0.7) ; `DomainEvent::FORBIDDEN_PAYLOAD_KEYS` refuserait `iban`/`bic` de
  toute façon (défense en profondeur déjà en place).

---

## 4. Migrations

Deux migrations, timestamps **après** la dernière migration existante du dépôt (`Version20260901020000`,
dernière constatée dans `app/migrations/` au moment de la rédaction) :

- **`Version20260901090000`** — `ALTER TABLE finance_treasury_settings`:
  - `ADD cash_alert_threshold_cents INT DEFAULT NULL` (§0.2, compatible avec le code déployé avant elle,
    même leçon que `Version20260901020000`).
  - `ADD cash_alert_horizon_days INT NOT NULL DEFAULT 30`.
  - **Down** : `DROP cash_alert_threshold_cents`, `DROP cash_alert_horizon_days`.

- **`Version20260901090100`** — `CREATE TABLE finance_treasury_cash_alert` (`id BINARY(16) PK`,
  `establishment_id BINARY(16) NOT NULL FK → org_etablissement`, `status VARCHAR(10) NOT NULL DEFAULT
  'open'`, `threshold_cents_at_detection INT NOT NULL`, `horizon_days_at_detection INT NOT NULL`,
  `projected_breach_date DATE NOT NULL`, `projected_balance_cents INT NOT NULL`, `cause_source
  VARCHAR(32) NULL`, `cause_source_id BINARY(16) NULL`, `cause_amount_cents INT NULL`, `detected_at
  DATETIME NOT NULL`, `last_checked_at DATETIME NOT NULL`, `last_notified_at DATETIME NULL`, `resolved_at
  DATETIME NULL`, `open_establishment_id BINARY(16) GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN
  establishment_id END) VIRTUAL`) + `INDEX idx_treasury_cash_alert_establishment (establishment_id)` +
  `INDEX idx_treasury_cash_alert_status (status)` + `UNIQUE INDEX
  uniq_treasury_cash_alert_open_establishment (open_establishment_id)` (§0.3, anti-répétition garantie en
  base — MariaDB exclut les `NULL` d'un index unique, seules les lignes `open` sont donc contraintes).
  - **Down** : `DROP TABLE finance_treasury_cash_alert`.

Aucune table existante autre que `finance_treasury_settings` n'est modifiée. Aucune donnée existante
affectée (colonnes nouvelles nullables/avec défaut, table entièrement nouvelle). Migrations réversibles,
rejouables (constitution §7).

---

## 5. Tests

| Test | Type | Couvre |
|---|---|---|
| `App\Tests\Finance\Api\TreasurySettingsApiTest::testSeuilNulDesactiveLaFonctionnalite` | Fonctionnel API | RG-TRE-10 — `cashAlertThresholdCents = null` (défaut) → la commande ignore l'établissement |
| `App\Tests\Finance\Api\TreasurySettingsApiTest::testSeuilNegatifAccepte` | Fonctionnel API | §4.1 — découvert autorisé, aucune contrainte de signe |
| `App\Tests\Finance\Unit\ThresholdBreachProjectionCalculatorTest::testFranchissementDetecteRetientLaPremiereDate` | Unit | **RG-TRE-11** — scénario de la mission (solde 5000 €, sortie 6000 € à J+12, fenêtre 30 j) → `breachDate = J+12` |
| `App\Tests\Finance\Unit\ThresholdBreachProjectionCalculatorTest::testAucuneSortieAvantLeFranchissementCauseNulle` | Unit | §4.2 point 5/§4.3 — solde qui décroît sans grosse échéance isolée, `causeSource = null` |
| `App\Tests\Finance\Unit\ThresholdBreachProjectionCalculatorTest::testEgaliteAuSeuilNestPasUnFranchissement` | Unit | RG-TRE-11 point 5 — strictement inférieur |
| `App\Tests\Finance\Unit\ThresholdBreachProjectionCalculatorTest::testPlusGrosseSortieAvantLaDateRetenueCommeCause` | Unit | **RG-TRE-12** — deux sorties, la plus grosse antérieure ou égale à la date de franchissement l'emporte |
| `App\Tests\Finance\Unit\ThresholdBreachProjectionCalculatorTest::testAucunFranchissementDansLHorizonRenvoieProjectionVide` | Unit | §4.2 — aucune date trouvée dans la fenêtre |
| **`App\Tests\Finance\Api\VerifierSeuilsCommandTest::testFranchissementDetecteCreeUneAlerte`** | Fonctionnel (CLI) | RG-TRE-11/13 — première détection, `TreasuryCashAlert` créée, `treasury.threshold_breached` émis |
| **`App\Tests\Finance\Api\VerifierSeuilsCommandTest::testDeuxPassagesMemeDateUneSeuleNotification`** | Fonctionnel (CLI) | **RG-TRE-13** — deux exécutions successives sans aggravation → une seule `TreasuryCashAlert`, un seul événement |
| `App\Tests\Finance\Api\VerifierSeuilsCommandTest::testDateQuiAvanceReemetUnEvenement` | Fonctionnel (CLI) | RG-TRE-13 — aggravation (date plus proche) → second événement sur la **même** alerte |
| `App\Tests\Finance\Api\VerifierSeuilsCommandTest::testDateQuiRecouleNeReemetRien` | Fonctionnel (CLI) | RG-TRE-13 — amélioration (date plus tardive) → mise à jour silencieuse, aucun événement |
| `App\Tests\Finance\Api\VerifierSeuilsCommandTest::testAbsenceDeFranchissementResoutLAlerteSansEvenement` | Fonctionnel (CLI) | RG-TRE-13 — résolution silencieuse |
| `App\Tests\Finance\Api\VerifierSeuilsCommandTest::testThresholdCentsAtDetectionNestJamaisReecrit` | Fonctionnel (CLI) | §0.5/§5 — le seuil change en cours de vie de l'alerte, `thresholdCentsAtDetection` reste figé |
| **`App\Tests\Finance\Api\TreasurySettingsProcessorTest::testDesactivationDuSeuilResoutLesAlertesOuvertes`** | Fonctionnel API | §4.4 dernier point — `PATCH cashAlertThresholdCents: null` résout silencieusement, aucun événement |
| **`App\Tests\Finance\Unit\TreasuryCashAlertEntityTest::testUneSeuleAlerteOuvertePourUnEtablissement`** | Unit/Intégration DB | §0.3 — la contrainte `UNIQUE` sur la colonne générée refuse une seconde ligne `open` pour le même établissement |
| `App\Tests\Finance\Api\NotificationTreasuryThresholdTest::testDestinatairesLimitesAFinanceRead` | Fonctionnel API | RG-TRE-14 — la Cloche de chaque titulaire de `finance.read` sur l'établissement reçoit l'alerte, aucun autre |
| `App\Tests\Finance\Unit\NotificationTreasuryThresholdTest::testGraviteWarning` | Unit | §4.5 |
| `App\Tests\Finance\Api\CloisonnementTreasuryCashAlertTest::testAlerteDunAutreEtablissementInvisible` | Fonctionnel API | §3 — 5ᵉ ressource de `PerimetreFinanceExtension` |
| `App\Tests\Finance\Api\EventTenantTreasuryThresholdTest::testTenantDeriveDeLetablissementDeLalerte` | Fonctionnel/Unit | D6 — même patron que les tests `EventTenant*` déjà en place pour FIN-2/FIN-3/FIN-4 |
| `App\Tests\Finance\Unit\FinanceModuleExtensionTest::testThresholdBreachedPresentSansRegression` | Unit | §0.7 — l'événement figure dans `FinanceModule::eventsEmitted()`, **sans supprimer** les 9 déjà présents |
| `App\Tests\Platform\ScheduleCatalogTest::testVerifierSeuilsEnregistreeEtNonSurAutomatique` | Unit | RG-TRE-16 — présente dans `ScheduleCatalog::all()`, `safeOnFirstRun = false`, `critical = true` |
| `App\Tests\Finance\Api\DiscrepancyDetectedNonRegressionTest::testTreasuryDiscrepancyDetectedInchange` | Fonctionnel API/CLI | Non-régression — la règle `treasury.discrepancy_detected`/la commande `detecter-ecarts` ne sont pas affectées par cet ajout |

---

## 6. Tâches (voir tasks-treasury-cash-alerts.md)

- **T1** — Deux colonnes sur `TreasurySettings` (§0.2) + migration `Version20260901090000` + tests
  d'entité/API (`testSeuilNulDesactiveLaFonctionnalite`, `testSeuilNegatifAccepte`) — indépendant, peut
  démarrer immédiatement.
- **T2** — Enum `CashAlertStatus` + entité `TreasuryCashAlert` (§0.3, colonne générée + index unique) +
  migration `Version20260901090100` + `#[ApiResource]` lecture seule (`GetCollection`/`Get`,
  `finance.read`) + extension de `PerimetreFinanceExtension::CHAINES` (5ᵉ ressource) + tests de
  cloisonnement en lecture et de la contrainte d'unicité DB — dépend de T1 (référence `Etablissement`,
  aucune dépendance technique réelle sur T1 mais mérite d'être revu ensemble).
- **T3** — DTO `ThresholdBreachProjection` + `ThresholdBreachProjectionCalculator` (§0.4, réutilise
  `TreasuryPositionCalculator`/`PaymentScheduleCalculator` tels quels) + tests unitaires (franchissement,
  égalité stricte, cause principale, aucune sortie éligible, aucun franchissement) — dépend de T1
  (lit `cashAlertThresholdCents`/`cashAlertHorizonDays` dans ses tests), aucune dépendance sur T2.
- **T4** — `VerifierSeuilsCommand` (`finance:treasury:verifier-seuils`, §0.5 : algorithme complet,
  anti-répétition, idempotence) + tests fonctionnels CLI (création, non-répétition, aggravation,
  amélioration, résolution silencieuse, `thresholdCentsAtDetection` figé) — dépend de T2, T3.
- **T5** — Émission de `treasury.threshold_breached` dans T4 (§0.7, branché avec T4 en pratique, jalon de
  revue distinct) + ajout au catalogue partagé (`COORDINATION/CONTRACT/catalogue-evenements.md`) +
  extension de `FinanceModule::eventsEmitted()` + tests (D6, non-régression du fichier partagé) — dépend
  de T4.
- **T6** — Extension de `TreasurySettingsProcessor` (§0.6, résolution silencieuse à la désactivation) +
  test `testDesactivationDuSeuilResoutLesAlertesOuvertes` — dépend de T2 (lit/écrit `TreasuryCashAlert`),
  peut être fait en parallèle de T3/T4/T5.
- **T7** — Nouvelle entrée `NotificationRule` (§0.8, gravité `Warning`) + tests (destinataires limités à
  `finance.read`, gravité, non-régression de `treasury.discrepancy_detected`) — dépend de T5 (l'événement
  doit exister pour être admis par la règle).
- **T8** — Enregistrement dans `ScheduleCatalog::all()` (§0.9, `safeOnFirstRun: false`) + test
  `ScheduleCatalogTest` — indépendant du reste techniquement, mais listé en fin car il documente la
  commande T4 une fois stabilisée.
- **T9** — Revue de cohérence (constitution §8) : `GET /health`, rejeu complet des tests `App\Tests\
  Finance\*` existants (non-régression — ce lot ne modifie aucun fichier `Entity`/`Service` FIN-2/FIN-3),
  vérification i18n (clés `finance.treasury.cash_alert.*` si le frontal les consomme — hors périmètre
  strict de ce plan, signalé), confirmation que `catalogue-evenements.md`/`FinanceModule` sont cohérents
  après merge (§0.7).

---

## 7. Risques / à valider

1. **Gravité `Warning`, pas `Critical`** (§0.8, §4.5/§8 point 2 de la spec) — hypothèse explicitement
   posée par la spec elle-même, reprise telle quelle par ce plan ; à confirmer avec le métier avant merge.
2. **⚠ HORS BACKLOG** — comme le reste de la suite Treasury, cette fonctionnalité n'est couverte par
   aucune user story du backlog existant : à faire valider et chiffrer avant tout développement réel
   (rappel explicite, pas une nouveauté propre à ce plan).
3. **Couple `module`/`action` différent entre `treasury.discrepancy_detected` (`compta`/`lire`) et
   `treasury.threshold_breached` (`finance`/`read`, ce plan)** (§0.8) — constat fait en préparant ce
   plan, non corrigé : les deux règles voisines de la même brique `Treasury` ciblent des populations de
   destinataires potentiellement différentes (comptables `compta.lire` vs lecteurs Finance `finance.read`).
   Ce plan suit littéralement §4.5 de la spec (`finance`/`read`) plutôt que de copier la règle existante ;
   à confirmer que c'est bien le comportement voulu, ou si les deux règles devraient converger.
4. **Colonne générée virtuelle + index unique pour l'anti-répétition** (§0.3) — mécanisme MariaDB standard
   (colonnes `GENERATED ALWAYS AS (...) VIRTUAL` supportées depuis MariaDB 10.2, index unique excluant les
   `NULL` comportement MySQL/MariaDB standard), mais **à valider en CI** sur l'image MariaDB 11.4 réelle du
   dépôt avant de considérer la garde acquise — un test dédié (`TreasuryCashAlertEntityTest`) la rend
   exécutable plutôt qu'une hypothèse de plan.
5. **`Finance.jsx` ne restitue pas encore son onglet actif depuis l'URL** (§0.8, §4.5/§8 point 3 de la
   spec) — la notification mène à la bonne page, pas garanti au bon onglet ; gap pré-existant, non corrigé
   par ce plan.
6. **`finance:treasury:detecter-ecarts`/`finance:treasury:suggerer-rapprochements` toujours absentes de
   `ScheduleCatalog`** (§0.9, §4.6/§8 point 4 de la spec) — constat repris, non corrigé par ce plan (hors
   périmètre), mais la nouvelle commande **est** enregistrée, condition explicite de la spec.
7. **`PerimetreFinanceExtension` — 5ᵉ ressource du même patron dupliqué quatre fois avant elle**
   (`SupplierInvoice`, `ExpenseReport`, `BankAccount`/`BankStatementImport`/`BankStatementLine`/
   `TreasurySettings`, désormais `TreasuryCashAlert`) — même recommandation de promotion vers un service
   partagé paramétrable, réitérée une **cinquième** fois (FIN-2 §7 point 1, FIN-3 §7 point 4, FIN-4 §7
   point 3), jamais traitée.
8. **Seuil négatif (découvert autorisé) porté par `cashAlertThresholdCents` sans champ dédié** — hypothèse
   déjà actée et non retranchée par la spec elle-même (§4.1/§8 point 6) ; ce plan la reprend sans la
   retrancher, cohérent avec la consigne « ne pas re-trancher » de la constitution.
9. **Absence de marge de tolérance sur l'anti-répétition** (ré-alerte dès que la date avance d'un seul
   jour, §4.4/§8 point 7 de la spec) — non ajoutée par ce plan (simplicité, cohérent avec la spec), à
   surveiller en usage réel une fois le premier passage supervisé effectué.

---

## Récapitulatif pour l'intégrateur A

- **Entité étendue** : `TreasurySettings` (+2 colonnes). **Entité nouvelle** : `TreasuryCashAlert`
  (`App\Finance\Treasury\Entity`), lecture seule côté API.
- **Migrations** : `Version20260901090000` (ALTER), `Version20260901090100` (CREATE), après
  `Version20260901020000`, dernière du dépôt au moment de la rédaction.
- **Réutilisé, non dupliqué** : `TreasuryPositionCalculator::position()`,
  `PaymentScheduleCalculator::echeancier()` (aucun second moteur de calcul de solde/échéancier) ;
  `PerimetreFinanceExtension` (étendue, pas dupliquée) ; `NotifyOnDomainEvent`/`NotificationRecipientResolver`
  (aucune tuyauterie neuve, une ligne `NotificationRule` suffit).
- **Nouveau** : `ThresholdBreachProjectionCalculator` (projection jour par jour, cause principale) ;
  commande planifiée `finance:treasury:verifier-seuils` (patron `DetecterEcartsCommand`) ; événement
  `treasury.threshold_breached`.
- **Ordre des tâches** : T1 (`TreasurySettings`) ∥ démarrage T2 (`TreasuryCashAlert`) → T3 (calculateur) →
  T4 (commande) → T5 (événement + catalogue + manifeste) ∥ T6 (désactivation silencieuse) → T7
  (notification) → T8 (ordonnanceur) → T9 (revue de cohérence).
- **Coordination de merge** : `FinanceModule` (4ᵉ extension du même fichier partagé, relire l'état réel
  avant d'étendre) ; `catalogue-evenements.md` (1 ligne à ajouter) ; `NotificationRule::table()` (1 entrée
  à ajouter, ne rien retirer) ; `ScheduleCatalog::all()` (1 entrée à ajouter, ne rien retirer).
- **Point à valider avant tout développement réel** : ⚠ HORS BACKLOG (§7 point 2), comme tout
  `spec-treasury.md`/ses addenda.

# Spec — Treasury / Trésorerie (`App\Finance\Treasury`, lot `FIN-4`)

- **Lot / module :** module **nouveau**, `finance` (capacité) / features `treasury` +
  `bank_reconciliation` — voir `spec-finance-suite.md`. Convention de nommage : identifiants
  techniques en anglais, prose en français (`spec-finance-suite.md` §0).
- **Stories couvertes :** **US-TRE-01 à US-TRE-10** — ⚠ **HORS BACKLOG** : aucune source ne couvre la
  trésorerie. À faire valider/chiffrer avant développement.
- **Règles de gestion :** **RG-TRE-01 à RG-TRE-13** (nouvelles). Règles **réutilisées, non
  redéfinies** : `RG-SOCLE-01` à `07`, `RG-M6-04/13/14` (`spec-comptabilite-generale.md`), le
  chiffrement IBAN de `App\Sepa` (`spec-sepa`, plan-sepa.md §10).
- **Statut :** brouillon — **dernier lot** de la suite (agrège les autres, `spec-finance-suite.md` §4).

## 1. Objectif

Donner à l'établissement une **vision de sa position de trésorerie**, la capacité de **rapprocher**
ses relevés bancaires importés avec les écritures déjà comptabilisées, un **échéancier consolidé**
(ce qu'il doit encaisser et décaisser à court terme, toutes sources confondues) et un
**prévisionnel simple** — sans réémettre aucun flux bancaire réel (pas de PSP, pas de wallet, hors
périmètre explicite de cette suite).

## 2. Périmètre

### Inclus
- **`BankAccount`** : comptes bancaires de l'établissement, IBAN chiffré (réutilise le coffre SEPA),
  solde initial, rattachement au compte comptable de trésorerie (classe 512) (US-TRE-01, RG-TRE-01).
- **Import de relevé bancaire** (formats CSV, OFX, CAMT.053 — parsers **nouveaux**, aucun existant
  dans le dépôt) (US-TRE-02, RG-TRE-02).
- **Rapprochement bancaire** : appariement d'une `BankStatementLine` importée avec une écriture
  comptable déjà scellée (vente, facture directe, supplier invoice payment, expense report
  reimbursement, remise SEPA), manuel ou suggéré par heuristique (montant + date + référence)
  (US-TRE-03/04, RG-TRE-03/04).
- **Position de trésorerie** à une date : vue calculée, non stockée (US-TRE-05, RG-TRE-05).
- **Échéancier consolidé** : agrège les échéances fournisseurs (FIN-2), les factures clients à terme
  (`App\Facturation`), les remises SEPA prévues (`App\Sepa`) — vue calculée (US-TRE-06/07,
  RG-TRE-06/07).
- **Prévisionnel simple** : projection du solde sur N jours à partir de la position actuelle et de
  l'échéancier — vue calculée, hypothèses paramétrables (US-TRE-08, RG-TRE-08).
- **Écarts de rapprochement** : signalement d'une ligne de relevé sans correspondance trouvée après un
  délai (US-TRE-09, RG-TRE-09).
- Émission `treasury.reconciliation_completed`, `treasury.discrepancy_detected` (US-TRE-10).

### Exclu (pour l'instant)
- **Tout PSP réel, tout wallet, tout compte externe détenu par l'application** — hors périmètre
  explicite (consigne de la mission). `BankAccount` est une **représentation** du compte bancaire réel
  de l'établissement (IBAN, solde), **pas un compte opérant** de paiement.
- **L'émission de virements/prélèvements** — hors périmètre ; le prélèvement SEPA sortant
  (`App\Sepa\Pain008Generator`) existe déjà et n'est pas modifié ; cette brique **consomme** ses
  remises en lecture pour l'échéancier, **ne les déclenche pas**.
- **Le rapprochement automatique à 100 % sans validation humaine** — toute suggestion de
  rapprochement **reste proposée**, jamais appliquée sans confirmation, cohérent avec le principe
  « pas de dérogation automatique » déjà retenu ailleurs dans le dépôt.
- **Le moteur d'écritures, le lettrage lui-même** → `App\Compta`/`spec-comptabilite-generale.md`,
  réutilisés (le rapprochement bancaire **appelle** le lettrage groupé, il ne le redéfinit pas).

## 3. Acteurs & droits

| Acteur | Peut | Permission |
|---|---|---|
| **Trésorier / Comptable** | Créer/éditer un `BankAccount`, importer un relevé, rapprocher une ligne (manuel ou confirmer une suggestion), consulter position/échéancier/prévisionnel | `finance.treasury_manage_account`, `finance.treasury_import_statement`, `finance.treasury_reconcile`, `finance.read` |
| **Direction / Responsable financier** | Consulter position, échéancier, prévisionnel (lecture seule) | `finance.read` |
| **Administrateur** | Paramétrer les hypothèses du prévisionnel, le seuil de détection d'écart | `finance.manage` |
| **Système** | Suggérer un rapprochement (heuristique), détecter un écart après délai | *(acteur technique, pas de permission humaine)* |

## 4. Comportements & règles

### 4.1 Comptes bancaires (US-TRE-01, RG-TRE-01)
- **RG-TRE-01** — Un `BankAccount` porte `iban` (chiffré au repos, réutilise
  `App\Sepa\Service\ChiffreurIban` — **pas** un second coffre, `spec-finance-suite.md` §7 invariant
  #5), `bic`, `label`, `openingBalance`/`openingBalanceDate`, et un rattachement optionnel
  `ledgerAccount` (`CompteComptable`, classe 512 par convention). L'IBAN n'apparaît **jamais** en clair
  dans une réponse API JSON (même garde que `MandatSepa`/`ConfigCreancierSepa`, testée négativement).

### 4.2 Import de relevé (US-TRE-02, RG-TRE-02)
- **RG-TRE-02** — Un `BankStatementImport` (format `csv`/`ofx`/`camt053`, fichier, date d'import)
  produit une ou plusieurs `BankStatementLine` (date d'opération, libellé, montant signé, référence).
  L'import est **idempotent par format** : une même ligne déjà importée (même compte, même date, même
  montant, même référence) n'est **pas dupliquée** à un ré-import du même fichier — ⚠ HYPOTHÈSE : la
  clé de déduplication exacte (hash du fichier ? triplet date+montant+référence ?) n'est pas tranchée,
  à préciser au plan technique.
- ⚠ HYPOTHÈSE — **Aucun parseur CAMT.053/OFX/CSV n'existe dans le dépôt** (contrairement à pain.008
  sortant, déjà réel côté SEPA) : ce sont des **développements neufs**, non une extension d'un
  existant. Le format **CSV** (le plus simple à couvrir en premier) est recommandé comme **première
  cible d'implémentation**, `OFX`/`CAMT.053` en extension ultérieure — non tranché avec le client.

### 4.3 Rapprochement bancaire (US-TRE-03/04, RG-TRE-03/04)
- **RG-TRE-03** — Une `BankStatementLine` non rapprochée peut être **suggérée** par heuristique
  (montant identique à ± tolérance nulle, date dans une fenêtre paramétrable, référence textuelle
  partiellement corrélée) avec une **écriture comptable candidate** (ligne de banque 512 d'une écriture
  scellée : vente encaissée, facture directe payée, `SupplierPayment` [FIN-2], remboursement d'expense
  report [FIN-3], collecte SEPA). La suggestion **n'est jamais appliquée automatiquement**.
- **RG-TRE-04** — La **confirmation** d'un rapprochement (suggéré ou manuel) appelle
  `LettrageHandler::lettrerGroupe()` (`spec-comptabilite-generale.md` §4.4) sur la ligne d'écriture 512
  concernée et marque la `BankStatementLine.status = reconciled`, avec référence au
  `reconciliationCode` généré. Émission `treasury.reconciliation_completed`.

### 4.4 Position de trésorerie (US-TRE-05, RG-TRE-05)
- **RG-TRE-05** — La position à une date T (`TreasuryPosition`, **vue calculée, non persistée**, même
  patron que `ValorisationEtablissement`/`AlerteReappro` de `App\Stock`) = Σ (`openingBalance` de
  chaque `BankAccount` de l'établissement + Σ des lignes de relevé **rapprochées** jusqu'à T).
  Consultable à tout instant, filtrable par compte.

### 4.5 Échéancier consolidé (US-TRE-06/07, RG-TRE-06/07)
- **RG-TRE-06** — Un `PaymentSchedule` (**vue calculée, non persistée**) agrège, sur une fenêtre de
  dates :
  - **Sorties prévues** — `SupplierInvoice` en statut `to_pay`/`partially_paid` (FIN-2, `dueDate`,
    solde restant dû) ;
  - **Entrées prévues** — `Facture` (client, `App\Facturation`) en statut `en_attente_paiement`/
    `partiellement_reglee` (`dateEcheance`, solde restant dû), **réutilisée en lecture seule**, aucune
    modification de `App\Facturation` ;
  - **Prélèvements SEPA prévus** — `RemiseSepa` (`App\Sepa`) à venir, montant `ctrlSum`, date de
    collecte, **réutilisée en lecture seule**.
- **RG-TRE-07** — Chaque source manquante (ex. `App\Facturation` ou `App\Sepa` non activés/installés
  pour ce tenant) est **simplement absente** de l'agrégat — jamais une erreur bloquante (cohérent
  invariant « dégradation propre »).

### 4.6 Prévisionnel simple (US-TRE-08, RG-TRE-08)
- **RG-TRE-08** — Un `CashflowForecast` (**vue calculée**) = position actuelle (§4.4) + somme des
  entrées prévues − somme des sorties prévues de l'échéancier (§4.5), sur une fenêtre paramétrable (7/
  30/90 jours). ⚠ HYPOTHÈSE — « simple » signifie explicitement **aucune pondération de probabilité de
  paiement**, **aucun scénario** (optimiste/pessimiste) : c'est une projection **arithmétique brute**
  des échéances déclarées — à faire évoluer si le besoin d'un prévisionnel pondéré se confirme.

### 4.7 Détection d'écart (US-TRE-09, RG-TRE-09)
- **RG-TRE-09** — Une `BankStatementLine` restée **non rapprochée** au-delà d'un délai paramétrable
  (`unmatchedAlertDelayDays`) déclenche l'émission de `treasury.discrepancy_detected` (signalement,
  pas de blocage) — visibilité dans un tableau de bord dédié.

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **`BankAccount`** | id | uuid | PK | RG-TRE-01 |
| | establishment | ref Etablissement (socle) | requis | cloisonnement |
| | iban | string, chiffré | jamais exposé en clair via l'API | réutilise `ChiffreurIban` (Sepa) |
| | bic, label | string | requis | — |
| | ledgerAccount | ref CompteComptable (Compta, français)? | optionnel | classe 512 par convention |
| | openingBalance, openingBalanceDate | decimal, date | requis | — |
| | active | bool | défaut = true | — |
| **`BankStatementImport`** | id, bankAccount | uuid, ref | PK | RG-TRE-02 |
| | format | enum {csv, ofx, camt053} | requis | — |
| | file | fichier | requis | — |
| | importedAt | datetime | requis | — |
| | status | enum {imported, processed, error} | défaut = imported | — |
| **`BankStatementLine`** | id, statementImport | uuid, ref | PK | — |
| | operationDate | date | requis | — |
| | label | string | requis | — |
| | amount | decimal (signé) | requis | positif = crédit, négatif = débit |
| | reference | string? | optionnel | — |
| | status | enum {unmatched, suggested, reconciled, ignored} | défaut = unmatched | RG-TRE-03/04 |
| | matchedLedgerEntry | ref EcritureComptable (Compta, français)? | requis si `reconciled` | — |
| | reconciliationCode | string? | dérivé | RG-M6-14 réutilisée |
| *(non persisté)* `TreasuryPosition` | balance, asOfDate | decimal, date | calculé | §4.4 |
| *(non persisté)* `PaymentSchedule` | entries[], exits[] | (date, amount, source)[] | calculé | §4.5 |
| *(non persisté)* `CashflowForecast` | projectedBalance, horizonDays | decimal, int | calculé | §4.6 |

## 6. Critères d'acceptation

- **CA-1 (US-TRE-01, RG-TRE-01)** — *Étant donné* un `BankAccount` créé avec un IBAN, *quand* on
  consulte la ressource via l'API, *alors* l'IBAN **n'apparaît jamais en clair** dans la réponse JSON.
- **CA-2 (US-TRE-02, RG-TRE-02)** — *Étant donné* un relevé CSV importé une première fois, *quand* le
  **même fichier** est importé une seconde fois, *alors* aucune `BankStatementLine` n'est **dupliquée**.
- **CA-3 (US-TRE-03/04, RG-TRE-04)** — *Étant donné* une ligne de relevé de 500 € et une écriture
  bancaire scellée de 500 € à une date proche, *quand* le Trésorier **confirme** la suggestion,
  *alors* la ligne passe à `reconciled`, un `reconciliationCode` est créé, partagé avec la ligne
  d'écriture correspondante.
- **CA-4 (US-TRE-05, RG-TRE-05)** — *Étant donné* deux comptes bancaires avec des soldes d'ouverture et
  des lignes rapprochées, *quand* la position de trésorerie est consultée à une date T, *alors* elle
  reflète la **somme exacte** des soldes d'ouverture et des mouvements rapprochés jusqu'à T.
- **CA-5 (US-TRE-06/07, RG-TRE-07)** — *Étant donné* un tenant où `App\Sepa` n'est pas activé, *quand*
  l'échéancier est consulté, *alors* il **n'inclut simplement pas** de section prélèvements SEPA, sans
  erreur.
- **CA-6 (US-TRE-09, RG-TRE-09)** — *Étant donné* une ligne de relevé non rapprochée depuis plus que le
  délai paramétré, *alors* l'événement `treasury.discrepancy_detected` est émis et la ligne apparaît
  dans le tableau de bord des écarts.

## 7. Cas limites
- **Ligne de relevé sans aucune correspondance possible** (ex. frais bancaires, virement non identifié)
  — Reste `unmatched` indéfiniment ou peut être marquée `ignored` manuellement (justification requise,
  ⚠ HYPOTHÈSE non détaillée).
- **Deux écritures candidates au même montant/date** — La suggestion **liste les deux**, le Trésorier
  **choisit** ; aucune sélection automatique en cas d'ambiguïté.
- **Import d'un relevé sur un compte inactif** — ⚠ HYPOTHÈSE : accepté (l'import reste possible pour
  clôturer un historique), mais aucune nouvelle suggestion de rapprochement n'est proposée pour un
  compte inactif.
- **Prévisionnel avec échéances passées non soldées** (factures échues impayées) — ⚠ HYPOTHÈSE :
  incluses telles quelles dans le prévisionnel (elles restent dues), sans distinction visuelle
  « en retard » vs « à venir » dans cette version simple — amélioration possible non retenue v1.
- **Utilisateur sans affectation sur l'établissement du compte bancaire** — Aucun accès (hérité du
  socle, `RG-SOCLE-05`).

## 8. Dépendances
- **Dépend de : socle L0** — hiérarchie, permissions, cloisonnement, audit append-only.
- **Dépend de : General ledger extension (FIN-1)** — lettrage groupé (`reconciliationCode`) pour le
  rapprochement bancaire.
- **Dépend de : Supplier invoices (FIN-2)** — source de l'échéancier fournisseurs.
- **Dépend de : `App\Facturation`** — source de l'échéancier clients (`Facture`, lecture seule).
- **Dépend de : `App\Sepa`** — source de l'échéancier prélèvements (`RemiseSepa`), et **coffre IBAN**
  réutilisé (`ChiffreurIban`) pour `BankAccount.iban`.
- **Interagit avec (sans dépendance stricte) : Expense reports (FIN-3)** — source de l'échéancier
  remboursements salariés.

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ HORS BACKLOG** — module entièrement nouveau (en-tête).
2. Clé de déduplication exacte à l'import de relevé — non tranchée (§4.2).
3. Ordre de priorité des formats à implémenter (CSV en premier, recommandé) — non tranché avec le
   client (§4.2).
4. Marquage `ignored` d'une ligne sans correspondance — non détaillé (§7).
5. Prévisionnel sans pondération/scénario — limitation assumée v1, à faire évoluer si besoin (§4.6).
6. Distinction visuelle échéances en retard vs à venir dans le prévisionnel — non retenue v1 (§7).

# Plan technique — Treasury / Trésorerie (`App\Finance\Treasury`, lot `FIN-4`)

- **Spec source :** specs/finance/spec-treasury.md (+ specs/finance/spec-finance-suite.md §3/§5/§6)
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Contrat de plateforme :** COORDINATION/CONTRACT/manifeste-module.md (module `finance`, **partagé
  pour la 3ᵉ fois** — FIN-2 l'a introduit, FIN-3 l'a étendu, ce plan l'étend une troisième fois),
  COORDINATION/CONTRACT/catalogue-evenements.md (`treasury.*` **absent** du catalogue au moment de la
  rédaction — à ajouter, §7 point 5), COORDINATION/CONTRACT/noyau-commun.md (invariants #1/#2/#3/#7),
  COORDINATION/DECISIONS.md **D5/D6/D7/D8** (impératifs de la mission, traités explicitement
  §0.2/§0.8/§0.9/§0.7)
- **Dépend de (déjà livré, réutilisé tel quel, aucune duplication) :** `App\Sepa\Service\ChiffreurIban`
  (coffre IBAN réversible, libsodium — **seul** coffre réutilisé, pas un second mécanisme), `App\Sepa\
  Entity\RemiseSepa` (source échéancier, lecture seule), `App\Compta` L4 + extension FIN-1
  (`LettrageHandler::lettrer()`/`lettrerGroupe()`, `CompteComptable`, `LigneEcriture`,
  `LettrageEcriture`, `EcritureComptable::estScellee()`), `App\Finance\SupplierInvoice\Service\
  SupplierInvoiceBalanceCalculator::soldeCentimes()` (FIN-2, réutilisé tel quel pour le solde restant dû
  fournisseur de l'échéancier), `App\Facturation\Entity\Facture`/`ReglementFacture` (échéancier client,
  lecture seule, **aucune modification**), `App\Stock\Security\PerimetreEtablissementVerificateur`
  (réutilisé une **4ᵉ fois** hors de son module d'origine, même précédent que FIN-2/FIN-3), `App\Securite`
  (`ContexteEtablissement`, `CalculateurDroits`, `Utilisateur`), `App\Platform\Event`
  (`EventBus`/`DomainEvent`/`EventTenant`/`EventSubject`/`EventName`), `App\Platform\Module`
  (`ModuleManifest` — **étend** `App\Finance\FinanceModule`, ne crée pas de second manifeste)
- **Couvre :** US-TRE-01 à US-TRE-10 · RG-TRE-01 à RG-TRE-13 · CA-1 à CA-6

> **Note de méthode.** Ce lot est le **dernier** de la suite Finance (`spec-finance-suite.md` §4) : il
> **agrège** FIN-1/FIN-2/`App\Facturation`/`App\Sepa` sans jamais les modifier ni recomptabiliser quoi que
> ce soit — Treasury ne construit **aucune** `EcritureComptable` nouvelle (contrairement à FIN-2/FIN-3,
> qui utilisent `DirectLedgerEntryBuilder`). Sa seule écriture en base de données du noyau Compta est un
> **lettrage** de lignes déjà scellées (`LettrageHandler`), jamais une nouvelle pièce comptable. Au moment
> de la rédaction de ce plan, `app/src/Finance/{SupplierInvoice,ExpenseReport}` sont **déjà codés** dans le
> dépôt (vérifié) et `App\Finance\FinanceModule` porte déjà les permissions/événements/features de FIN-2 et
> FIN-3 — ce plan documente précisément l'état réel du fichier partagé à étendre (§0.10), pas une
> hypothèse.

---

## 0. Décisions d'architecture

### 0.1 Namespace et rattachement du module

`App\Finance\Treasury\{Entity,Enum,Dto,Service,State,Doctrine,Command}` (brique), même racine de module
que FIN-2/FIN-3 : `App\Finance\FinanceModule` (manifeste **partagé**, étendu une troisième fois, §0.10).
Aucune entité de ce lot n'est créée dans `App\Compta`/`App\Sepa`/`App\Facturation` : ils sont
**référencés**, jamais étendus.

### 0.2 Cloisonnement (D3/D8) — `establishment` direct, quatrième copie du patron d'extension Doctrine

`BankAccount` porte un **`establishment` direct** (même ancre que `SupplierInvoice`/`ExpenseReport`,
§0.2 des plans FIN-2/FIN-3) — source du tenant d'événement (D6, §0.9).

1. **Écriture par corps brut (`read: false`)** — `BankAccountProcessor` (création/édition d'un compte
   bancaire) : `establishment` du corps → `PerimetreEtablissementVerificateur::verifier()` (Stock,
   réutilisé **une 4ᵉ fois** hors de son module d'origine — §7 point 2, même risque déjà signalé deux
   fois par FIN-2/FIN-3, jamais corrigé) ; `ledgerAccount` (si fourni) → son
   `CompteComptable::getProfilExploitant()->couvre($establishment)` doit être vrai (422 sinon, IDOR
   inter-profils, même patron FIN-1/FIN-2). **Aucun champ `businessProfile` sur `BankAccount`** (choix
   délibéré, §1) : contrairement à FIN-2/FIN-3 qui doivent résoudre plusieurs comptes par préfixe
   (401/4456/512) et ont donc besoin d'un profil explicite, `BankAccount` référence **un seul** compte
   comptable déjà choisi par l'utilisateur (`ledgerAccount`) — son profil s'en déduit directement, aucune
   ambiguïté à lever.
2. **Lecture (`GetCollection`/`Get`, tout `read: true`)** — nouvelle extension Doctrine
   `App\Finance\Treasury\Doctrine\PerimetreFinanceExtension implements
   QueryCollectionExtensionInterface, QueryItemExtensionInterface` — **troisième exemplaire** du même
   patron déjà posé deux fois par FIN-2 (`SupplierInvoice`) et FIN-3 (`ExpenseReport`) — §7 point 1,
   recommandation de promotion réitérée une troisième fois. Chaînes : `BankAccount => []`,
   `BankStatementImport => ['bankAccount']`, `BankStatementLine => ['statementImport', 'bankAccount']`
   (chaîne à **deux sauts**, supportée nativement par le patron générique — vérifié dans le code de
   `PerimetreFinanceExtension` de FIN-2, boucle `foreach` sur la chaîne), `TreasurySettings => []`
   (établissement direct, **pas** le patron `RESOURCES_VIA_PROFIL` de FIN-2 — Treasury n'a pas de
   `businessProfile`).
3. **Écriture par corps brut sur sous-ressource existante** — `ImportBankStatementProcessor`
   (`bankAccount` résolu par le provider d'item standard depuis l'URL, donc déjà protégé par
   l'extension ci-dessus, `read: true`) ; `ConfirmReconciliationProcessor`/`IgnoreStatementLineProcessor`
   (`statementLine` idem, `read: true`) — **mais** `ledgerLineIds` fourni **dans le corps** de
   `POST …/reconcile` est un identifiant client non protégé par l'extension (D8 explicite, §0.6 point 3) :
   chaque `LigneEcriture` référencée doit appartenir au **même** `CompteComptable` que
   `bankStatementLine.statementImport.bankAccount.ledgerAccount` (404 sinon — la ligne n'existe pas dans
   ce périmètre fonctionnel), défense en profondeur indépendante du cloisonnement établissement.

### 0.3 IBAN — coffre SEPA réutilisé, jamais un second mécanisme (invariant #5 de la suite)

`BankAccount` suit **exactement** le patron déjà posé par `ConfigCreancierSepa` (`App\Sepa`, plan-sepa.md
§10) :
- `ibanCipher` (`text`, nullable) — sortie de `ChiffreurIbanInterface::chiffrer()` (libsodium
  `crypto_secretbox`, réversible) — **sans** `#[Groups]`, jamais sérialisé.
- `ibanLast4` (`string(4)`) — seul fragment lisible, `#[Groups(['bank_account:read'])]`.
- `ibanClear` — champ **transitoire**, jamais mappé Doctrine, `#[Groups(['bank_account:write'])]`
  uniquement : consommé par `BankAccountProcessor`, jamais persisté en clair, jamais loggué.
- **Contrairement à `ConfigCreancierSepa`**, ce plan **n'ajoute pas** de jeton HMAC non réversible
  (`creancierIbanToken`) : ce dernier sert à SEPA pour la **recherche/l'affichage** d'un IBAN débiteur
  parmi des milliers de mandats. `BankAccount` est un référentiel de quelques comptes par établissement,
  jamais recherché par IBAN — un token de recherche serait une capacité construite sans besoin identifié
  (§7 point 9, à confirmer que ce n'est pas un oubli plutôt qu'une simplification légitime).
- Le déchiffrement (`dechiffrer()`) n'a lieu **nulle part dans ce lot** : Treasury n'émet aucun flux
  bancaire réel (spec §2 « Exclu »), l'IBAN chiffré n'est donc jamais reconstruit en clair après sa
  saisie — il est stocké **au cas où** un futur usage (export, rapprochement par IBAN) en aurait besoin,
  cohérent avec le patron déjà retenu par SEPA (stocker chiffré, déchiffrer seulement au point d'usage
  strict qui n'existe pas encore ici).

### 0.4 Import de relevé — port branchable, CSV = lot minimal (mission explicite)

`App\Finance\Treasury\Port\BankStatementParserInterface` :

```php
interface BankStatementParserInterface
{
    public function supports(BankStatementImportFormat $format): bool;

    /** @return list<ParsedStatementLine> */
    public function parse(string $content, BankAccount $account): array;
}
```

- **`CsvBankStatementParser`** (**seul adaptateur construit par ce lot**, T4) — format minimal
  recommandé par la mission : colonnes `date;libelle;montant;reference` (délimiteur `;`, montant décimal
  point ou virgule normalisé), une ligne d'en-tête ignorée. Erreurs de ligne individuelles (montant
  illisible, date invalide) **n'interrompent pas** l'import du fichier entier : la ligne fautive est
  comptée dans `BankStatementImport.linesSkipped` avec le motif, le reste du fichier est traité
  (dégradation propre, cohérent avec le principe déjà appliqué par l'OCR §4.4 `spec-ocr.md`).
- **`OfxBankStatementParser`/`Camt053BankStatementParser`** — **non construits par ce lot**, la mission
  les classe explicitement en extension ultérieure. `BankStatementImportFormat` (enum) déclare déjà les
  trois valeurs `Csv`/`Ofx`/`Camt053` (§5 spec) : sélectionner `ofx`/`camt053` aujourd'hui renvoie 422
  (« format non encore supporté ») plutôt qu'une erreur 500 — testé explicitement (§5).
- **Mode manuel** (exigé par la mission, non détaillé littéralement par la spec) — ce plan ajoute une
  **quatrième valeur d'enum**, `BankStatementImportFormat::Manual = 'manual'` (additive, D5, à confirmer
  avec le propriétaire de la spec avant merge, §7 point 6) : un `BankStatementImport` en format `manual`
  n'a **pas** de fichier (`fileName`/`fileMimeType`/`fileSize`/`contentHash` tous `null`), sert seulement
  de **conteneur** pour des `BankStatementLine` créées **une par une** via
  `POST /finance/treasury/statement-imports/{id}/lines` (§2) — toujours disponible, y compris si l'import
  CSV échoue totalement (dégradation propre, même principe que l'OCR).

### 0.5 Idempotence de l'import — clé de déduplication à deux niveaux (point ouvert tranché, §4.2 spec)

La spec signale explicitement ce choix comme **non tranché**, à trancher au plan technique. Décision de
ce plan, à deux niveaux complémentaires :

1. **Niveau fichier (chemin rapide)** — `BankStatementImport.contentHash` (SHA-256 hexadécimal du
   contenu brut reçu, avant tout parsing) sous une contrainte `UNIQUE (bank_account_id, content_hash)`.
   Un ré-import du **même** fichier sur le **même** compte échoue tôt (409, CA-2 littéral : « le même
   fichier importé une seconde fois ») sans même parser son contenu.
2. **Niveau ligne (défense en profondeur, robuste à un fichier partiellement recouvrant)** — avant de
   persister une `BankStatementLine` issue du parsing, vérification applicative (pas de contrainte
   `UNIQUE` base — le triplet peut légitimement se répéter entre deux opérations distinctes, ex. deux
   prélèvements identiques le même jour) : si une ligne **déjà existante** sur le **même** `bankAccount`
   partage exactement `(operationDate, amount, reference)` **et** appartient à un import du **même**
   format, elle est considérée déjà importée → comptée dans `linesSkipped`, non recréée. Ce second niveau
   couvre le cas d'un fichier réexporté par la banque avec une plage de dates élargie (chevauchement
   partiel), que le hash de fichier seul ne détecterait pas.

### 0.6 Rapprochement bancaire — tension mécanique avec `LettrageHandler::lettrerGroupe()`, résolue explicitement

**Le point le plus délicat de ce plan (§7 point 1, risque majeur).** RG-TRE-04 dit littéralement que la
confirmation « appelle `LettrageHandler::lettrerGroupe()` ». Or `lettrerGroupe()` **exige au moins 2
lignes** (`\count($lignes) < 2` lève une exception, code vérifié) et une égalité stricte
`Σdébit === Σcrédit` **entre les lignes passées**. C'est exactement adapté au cas déjà traité par
FIN-2/FIN-3 (une ligne 401/421 de **création** de dette face à une ou plusieurs lignes 401/421 de
**règlement**, sens opposés qui s'annulent). Le cas courant de la Trésorerie est différent : **une seule**
`LigneEcriture` déjà scellée sur le compte 512 (celle déjà générée par la vente, le règlement fournisseur,
le remboursement, ou la collecte SEPA) doit être rapprochée d'**un fait externe non comptable**
(`BankStatementLine`, qui n'est **jamais** une `LigneEcriture` — Treasury ne recomptabilise rien). Avec
une seule ligne réelle et aucune seconde ligne à lui opposer, `lettrerGroupe()` échoue mécaniquement par
construction (`count < 2`).

**Résolution retenue par ce plan**, sans modifier `App\Compta` (module déjà livré, hors périmètre de ce
lot) :

- **Cas courant (1 seule ligne candidate confirmée)** — `BankReconciliationHandler::confirmer()` appelle
  `LettrageHandler::lettrer($ligne512, $auteur)` (méthode **simple**, existante, inchangée — accepte une
  seule ligne, ne vérifie aucun équilibre puisqu'il n'y a rien à équilibrer), **puis** appelle
  explicitement `$lettrage->setReconciliationCode(Uuid::v4()->toRfc4122())` sur l'objet
  `LettrageEcriture` retourné (setter public existant, aucune modification de `App\Compta`) avant un
  second `flush()`. Le même code est copié sur `BankStatementLine.reconciliationCode`. Résultat
  strictement conforme à CA-3 : « un `reconciliationCode` est créé, partagé avec la ligne d'écriture
  correspondante » — littéralement vrai, sans jamais invoquer une méthode conçue pour un cas structurel
  différent. **Garde explicite ajoutée par ce lot** (absente de `lettrer()`, qui ne la porte pas) :
  refus 409 si une `LettrageEcriture` existe déjà pour cette `LigneEcriture` (même contrôle que celui que
  `lettrerGroupe()` fait en interne, reproduit ici puisque `lettrer()` ne le fait pas).
- **Cas rare (plusieurs lignes 512 confirmées ensemble contre une seule ligne de relevé, ex. remise
  groupée)** — si l'utilisateur sélectionne 2+ candidates dont `Σdébit === Σcrédit` (rare en pratique
  côté banque, mais le seul cas où `lettrerGroupe()` s'applique tel quel), ce plan appelle
  `lettrerGroupe()` **littéralement comme le décrit RG-TRE-04**. Sinon (2+ lignes de même sens, somme ne
  s'équilibrant jamais) → 422 explicite (« ces lignes ne peuvent pas être lettrées ensemble, confirmez-les
  une par une »).
- **À confirmer avant merge avec le propriétaire de `App\Compta`/un expert-comptable** : cette lecture
  fait un usage de `lettrer()` (mono-ligne) **jamais rencontré jusqu'ici** dans le dépôt pour un
  rapprochement bancaire au sens strict — le docblock de `lettrerGroupe()` cite pourtant explicitement
  « écriture bancaire + ligne de relevé importé » comme exemple d'usage prévu, ce qui suggère que l'auteur
  de FIN-1 avait anticipé ce cas **sans** qu'aucune ligne de relevé importé ne soit elle-même une
  `LigneEcriture` — la seule lecture cohérente disponible avec le code réel est celle retenue ici.

### 0.7 Suggestion heuristique (RG-TRE-03) — jamais appliquée automatiquement, persistée seulement si non ambiguë

`App\Finance\Treasury\Service\BankReconciliationSuggestionCalculator::candidats(BankStatementLine $ligne):
list<LigneEcriture>` — requête en lecture seule, **aucune écriture** :
- Compte = `ligne.statementImport.bankAccount.ledgerAccount` (si `null`, aucune suggestion possible —
  compte bancaire non relié à un compte comptable, cas limite documenté §1).
- **Montant exact, tolérance nulle** (RG-TRE-03 littéral) : `ligne.amount > 0` (crédit relevé, entrée
  d'argent) → cherche `debitCentimes = |amount|` (le 512 augmente, convention débit = entrée d'actif
  bancaire) ; `amount < 0` → cherche `creditCentimes = |amount|`. **Table de correspondance des signes
  documentée explicitement ici** parce que c'est une source d'erreur classique (§7 point 8).
- Date dans une **fenêtre paramétrable** (`TreasurySettings.matchingWindowDays`, défaut 5 j).
- Écriture **scellée**, ligne **pas déjà lettrée** (aucune `LettrageEcriture` existante pour cette ligne).
- Corrélation textuelle référence/libellé (`similar_text()` normalisé) utilisée pour le **tri**
  seulement, jamais comme filtre d'exclusion (RG-TRE-03 : un des trois facteurs, pas un critère éliminatoire).

**Persistance du statut `suggested`** (le 4ᵉ statut de l'énumération §5 spec, sinon jamais atteint) — une
**commande planifiée** `finance:treasury:suggerer-rapprochements` (même patron que
`finance:expense-reports:resoudre-escalades`, FIN-3) parcourt les lignes `unmatched` d'un compte actif :
- **Exactement 1 candidate** → `status = suggested`, `suggestedLedgerLine` posé (nouveau champ, cache la
  suggestion pour ne pas la recalculer à chaque affichage).
- **0 ou 2+ candidates** → reste `unmatched` (spec §7 cas limite « deux candidates au même montant/date —
  la suggestion liste les deux, le Trésorier choisit » — l'endpoint `GET …/suggestions` reste **toujours**
  disponible en direct, indépendamment du statut persisté, pour afficher la liste complète y compris dans
  le cas ambigu).

### 0.8 Position, échéancier, prévisionnel — vues calculées, dégradation par absence de données

**Décision explicite sur la « dégradation propre » de CA-5/RG-TRE-07** : `App\Sepa` et `App\Facturation`
**n'implémentent pas `ModuleManifest`** aujourd'hui (vérifié — seuls `App\Ocr\OcrModule` et
`App\Finance\FinanceModule` le font, même constat transitoire déjà posé par FIN-2 §7 point 7/FIN-3 §7
point 12). `App\Platform\Module\ModuleAccess::hasFeature()` renverrait donc **toujours `false`** pour une
feature déclarée par un module sans manifeste — ce qui **casserait** l'échéancier pour **tout** tenant,
y compris ceux où SEPA est réellement configuré et utilisé en production. Ce plan **n'utilise donc pas**
`ModuleAccess` pour cette dégradation : chaque source est une requête défensive au niveau **données**
(absence de lignes = source vide, jamais une exception) — cohérent avec l'esprit de RG-TRE-07 sans
dépendre d'une infrastructure de manifeste que ces deux modules n'ont pas encore (§7 point 4, à revoir
une fois `sepa`/`facturation` rétrofités avec un manifeste, tâche de coordination C5).

- **`TreasuryPositionProvider`** (RG-TRE-05, CA-4) — `GET /finance/treasury/position?asOf=&bankAccount=`
  → pour chaque `BankAccount` actif de l'établissement (filtré si `bankAccount` fourni, D8 revérifié) :
  `openingBalance + Σ(BankStatementLine.amount)` où **seules** les lignes `status = reconciled` avec
  `operationDate <= asOfDate` comptent (RG-TRE-05 littéral : « lignes de relevé **rapprochées** jusqu'à T »
  — pas toutes les lignes importées).
- **`PaymentScheduleProvider`** (RG-TRE-06/07, CA-5) — `GET /finance/treasury/payment-schedule?from=&to=` :
  - `exits[]` — `SupplierInvoice` (FIN-2) en statut `ToPay`/`PartiallyPaid`, `dueDate` dans la fenêtre,
    montant = `SupplierInvoiceBalanceCalculator::soldeCentimes()` (**réutilisé tel quel**, FIN-2, pas de
    second calcul de solde réinventé).
  - `entries[]` — `Facture` (`App\Facturation`) en statut `EnAttentePaiement`/`PartiellementReglee`,
    `dateEcheance` dans la fenêtre, montant = `totalTTC − Σ(ReglementFacture.montant)` (calcul propre à
    ce lot, `App\Facturation` n'expose pas de solde déjà calculé — repository query simple, lecture
    seule) **+** `RemiseSepa` (`App\Sepa`) où `statut != Transmise`, `dateCollecte` dans la fenêtre,
    montant = `ctrlSumCentimes` (déjà en centimes, aucune conversion).
  - Chaque section est une requête indépendante : une base sans `Facture`/`RemiseSepa` correspondante
    renvoie simplement une liste vide pour cette section (CA-5 testé en vidant les données, pas en
    désactivant un module inexistant en tant que manifeste).
- **`CashflowForecastProvider`** (RG-TRE-08) — `GET /finance/treasury/cashflow-forecast?horizonDays=`
  (7/30/90 proposés côté UI, tout entier positif ≤ 365 accepté) : `position(aujourd'hui).balance +
  Σ entries[≤ horizon] − Σ exits[≤ horizon]` — **projection arithmétique brute**, aucune pondération
  (RG-TRE-08, hypothèse déjà actée par la spec elle-même).
- **`DiscrepancyDashboardProvider`** (US-TRE-09) — `GET /finance/treasury/discrepancies` : requête live,
  indépendante du drapeau d'idempotence d'émission (§0.9) — `status IN (unmatched, suggested)` **et**
  `createdAt < now − unmatchedAlertDelayDays`, quel que soit l'état de la notification déjà émise ou non.

### 0.9 Détection d'écart — commande planifiée, idempotente (RG-TRE-09, CA-6)

`finance:treasury:detecter-ecarts` (même patron que `finance:expense-reports:resoudre-escalades`, FIN-3
— « à planifier via cron externe ») : parcourt les `BankStatementLine` de comptes **actifs**, `status IN
(unmatched, suggested)`, `createdAt < now − TreasurySettings.unmatchedAlertDelayDays` (défaut 15 j,
paramétrable par établissement), `discrepancyNotifiedAt IS NULL` (nouveau champ, garantit l'idempotence
— **sans lui, chaque passage du cron réémettrait l'événement** pour la même ligne indéfiniment). Pour
chaque ligne : émission `treasury.discrepancy_detected`, `discrepancyNotifiedAt = now`, `flush()` en fin
de commande. **Comptes inactifs exclus** (§7 cas limite spec : pas de nouvelle suggestion/détection sur un
compte désactivé).

### 0.10 Événements — tenant dérivé de `BankAccount.establishment` (D6), jamais du contexte HTTP

| Événement | Émis par | `EventTenant` | `EventSubject` | `EventActor` | Payload |
|---|---|---|---|---|---|
| `treasury.reconciliation_completed` | `BankReconciliationHandler::confirmer()` | `new EventTenant($ligne->getStatementImport()->getBankAccount()->getEstablishment()->getId())` | `BankStatementLine`/id | l'utilisateur courant (le Trésorier) | `bankAccountId`, `ledgerLineId`, `amountCents`, `reconciliationCode` |
| `treasury.discrepancy_detected` | `finance:treasury:detecter-ecarts` (commande) | idem | `BankStatementLine`/id | `null` (système, comme `expense_report.approved` émis par la commande FIN-3) | `bankAccountId`, `amountCents`, `unmatchedSinceDays` |

**Jamais** `ContexteEtablissement::idActif()` (même règle D6 que FIN-2/FIN-3) : la commande planifiée n'a
d'ailleurs **aucun** contexte HTTP, rendant ce choix techniquement obligatoire, pas seulement conforme.
Aucun payload ne porte l'IBAN (`DomainEvent::FORBIDDEN_PAYLOAD_KEYS` refuserait `iban`/`bic` de toute
façon — défense en profondeur déjà posée au niveau du bus, RG-PLAT-04).

⚠ **`treasury.*` absent du catalogue partagé au moment de la rédaction** (`catalogue-evenements.md` liste
`supplier_invoice.*`/`expense_report.*` mais aucune ligne `treasury.*`, vérifié) — à ajouter par
l'intégrateur avant merge (§7 point 5, RG-PLAT-06).

### 0.11 Extension du manifeste `App\Finance\FinanceModule` — troisième modification du même fichier partagé

**État réel du fichier au moment de la rédaction de ce plan** (lu directement, pas supposé) :
`permissions()` porte déjà les 6 permissions FIN-2 + 4 permissions FIN-3 ; `eventsEmitted()` porte déjà
les 4 événements `supplier_invoice.*` + 3 `expense_report.*` ; `features()` ne porte **que**
`expense_reports`/`ocr_expense_reports` (FIN-3) — **`supplier_invoices`/`ocr_supplier_invoices` de FIN-2
en sont absents**, incohérence pré-existante non causée par ce lot, signalée sans être corrigée (hors
périmètre de ce plan, §7 point 10) ; `routes()` porte déjà `/finance/supplier-invoices` et
`/finance/expense-reports`. Ce plan **ajoute**, sans rien retirer :

- `permissions()` **+=** `finance.treasury_manage_account`, `finance.treasury_import_statement`,
  `finance.treasury_reconcile` (les 3 nommées par `spec-finance-suite.md` §5/§3.1 — aucune permission
  défensive supplémentaire nécessaire ici, contrairement à FIN-3 §0.4 : Treasury ne s'appuie pas sur
  `App\Autorisation`).
- `eventsEmitted()` **+=** `treasury.reconciliation_completed`, `treasury.discrepancy_detected`.
- `features()` **+=** `treasury`, `bank_reconciliation` (déjà nommées `spec-finance-suite.md` §2/§3.1).
- `routes()` **+=** `/finance/treasury`.
- `eventsConsumed()` inchangé (Treasury ne s'abonne à aucun événement — agrégation par lecture directe des
  entités FIN-2/`App\Facturation`/`App\Sepa`, jamais par le bus, cohérent avec FIN-2 §0.4).

⚠ **Coordination de merge** (3ᵉ modification du même fichier, §7 point 3) : ce plan **relit** l'état réel
du fichier avant d'étendre (fait ci-dessus), plutôt que de supposer son contenu — recommandation déjà
faite par FIN-3 §7 point 3, reconduite.

---

## 1. Entités & schéma

| Entité (`App\Finance\Treasury\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **`BankAccount`** | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | index | `Etablissement` (Organisation, socle) — ancre D6/D8 |
| | ledgerAccount | uuid (FK) | **oui** | — | `CompteComptable` (Compta), classe 512 par convention (non forcé en base — validé applicativement) |
| | ibanCipher | text | **oui** | jamais exposé (`#[Groups]` absent) | coffre `ChiffreurIbanInterface` (Sepa), §0.3 |
| | ibanLast4 | `string(4)` | non | défaut `''` | — |
| | bic | `string(11)` | non | — | — |
| | label | `string(140)` | non | — | — |
| | openingBalance | `decimal(12,2)` | non | — | — |
| | openingBalanceDate | `date_immutable` | non | — | — |
| | active | `bool` | non | défaut `true` | §0.7/§0.9, exclut des suggestions/détections |
| | createdAt | `datetime_immutable` | non | — | — |
| | createdBy | uuid (FK) | **oui** | — | `Utilisateur` (Securite) |
| **`BankStatementImport`** | id | uuid | non | PK | — |
| | bankAccount | uuid (FK) | non | index | `BankAccount` |
| | format | `string(8)` enum `BankStatementImportFormat` | non | — | `csv`\|`ofx`\|`camt053`\|`manual` (§0.4) |
| | fileName / fileMimeType / fileSize | `string(255)` / `string(100)` / `int` | **oui** | — | `null` si `format = manual` |
| | contentHash | `string(64)` | **oui** | `UNIQUE(bank_account_id, content_hash)` | §0.5, `null` si `format = manual` |
| | importedAt | `datetime` | non | — | — |
| | status | `string(10)` enum `BankStatementImportStatus` | non | défaut `imported` | `imported`\|`processed`\|`error` |
| | errorMessage | `text` | **oui** | — | erreur de parsing globale (fichier illisible) |
| | linesCreated / linesSkipped | `int` | non | défaut `0` | §0.4/§0.5, informatif |
| | createdBy | uuid (FK) | **oui** | — | `Utilisateur` |
| **`BankStatementLine`** | id | uuid | non | PK | — |
| | statementImport | uuid (FK) | non | index | `BankStatementImport` |
| | operationDate | `date` | non | index composite (`bank_account` via jointure, `operation_date`) | — |
| | label | `string(255)` | non | — | — |
| | amount | `decimal(12,2)` | non | — | signé : positif = crédit relevé (entrée), négatif = débit relevé (sortie), §0.7 |
| | reference | `string(140)` | **oui** | — | — |
| | status | `string(10)` enum `BankStatementLineStatus` | non | défaut `unmatched` | `unmatched`\|`suggested`\|`reconciled`\|`ignored` |
| | suggestedLedgerLine | uuid (FK) | **oui** | — | `LigneEcriture` (Compta) — cache de suggestion non ambiguë, §0.7 |
| | matchedLedgerLine *(renommé, §0.6/§7 point 7)* | uuid (FK) | **oui** | requis si `status = reconciled` | `LigneEcriture` (Compta), **pas** `EcritureComptable` — précision nécessaire à la ligne 512 exacte |
| | reconciliationCode | `string(36)` | **oui** | — | copié depuis `LettrageEcriture.reconciliationCode` à la confirmation |
| | ignoredReason | `text` | **oui** | requis si `status = ignored` (validé applicativement) | §7 cas limite spec |
| | discrepancyNotifiedAt | `datetime_immutable` | **oui** | — | garde d'idempotence de `treasury.discrepancy_detected`, §0.9 |
| | createdAt | `datetime_immutable` | non | — | — |
| **`TreasurySettings`** *(nouveau, admin)* | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | **unique** | `Etablissement` — 1 réglage par établissement, défaut applicatif si absent |
| | unmatchedAlertDelayDays | `int` | non | défaut `15` | RG-TRE-09, §0.9 |
| | matchingWindowDays | `int` | non | défaut `5` | RG-TRE-03, §0.7 |
| *(non persisté)* `TreasuryPosition` (`App\Finance\Treasury\Dto`) | balance, asOfDate, perAccount[] | decimal/date/list | — | calculé, §0.8 |
| *(non persisté)* `PaymentSchedule` | entries[], exits[] | list<{date, amountCents, source, sourceId}> | — | calculé, §0.8 |
| *(non persisté)* `CashflowForecast` | projectedBalance, horizonDays, asOfDate | decimal/int/date | — | calculé, §0.8 |
| *(non persisté)* `ReconciliationCandidate` | ledgerLineId, ecritureId, date, amountCents, label, textScore | uuid/uuid/date/int/string/float | — | calculé, §0.7 |

> id = UUID (`symfony/uid`). Rattachement multi-entités : `establishment` **direct** sur `BankAccount`/
> `TreasurySettings` (ancre de cloisonnement, D6/D8) ; `BankStatementImport`/`BankStatementLine` héritent
> du périmètre via leur parent (chaînes de jointure §0.2). Aucune écriture comptable n'est créée par ce
> lot (§0.6) — la seule table du noyau Compta touchée en écriture est `compta_lettrage_ecriture`, via
> les méthodes publiques déjà existantes de `LettrageHandler`.

**Enums** (`App\Finance\Treasury\Enum`, valeurs anglaises D5) : `BankStatementImportFormat` (`Csv =
'csv'`, `Ofx = 'ofx'`, `Camt053 = 'camt053'`, `Manual = 'manual'`), `BankStatementImportStatus`
(`Imported = 'imported'`, `Processed = 'processed'`, `Error = 'error'`), `BankStatementLineStatus`
(`Unmatched = 'unmatched'`, `Suggested = 'suggested'`, `Reconciled = 'reconciled'`, `Ignored =
'ignored'`).

---

## 2. API (API Platform)

| Ressource / route | Opération | `security:` | Processor/Provider | Groupes sérialisation |
|---|---|---|---|---|
| `BankAccount` | `GetCollection`, `Get` | `finance.read` | — (filtré) | `bank_account:read` (**jamais** `ibanCipher`) |
| `BankAccount` | `POST /finance/treasury/bank-accounts` | `finance.treasury_manage_account` | `BankAccountProcessor` (D8, §0.2) | in: `bank_account:write`, out: `bank_account:read` |
| `BankAccount` | `PATCH /finance/treasury/bank-accounts/{id}` | `finance.treasury_manage_account` | `BankAccountProcessor` (ré-chiffre si `ibanClear` fourni, sinon conserve) | idem |
| `BankStatementImport` | `GetCollection`, `Get` | `finance.read` | — (filtré) | `bank_statement_import:read` |
| `BankStatementImport` | `POST /finance/treasury/bank-accounts/{id}/statement-imports` | `finance.treasury_import_statement` | `ImportBankStatementProcessor` (§0.4/§0.5), `read:true`, corps `{ format, content?: base64, fileName?, mimeType? }` | out: `bank_statement_import:read` (+ `linesCreated`/`linesSkipped`) |
| `BankStatementLine` | `GetCollection`, `Get` | `finance.read` | — (filtré, chaîne 2 sauts) | `bank_statement_line:read` |
| `BankStatementLine` | `POST /finance/treasury/statement-imports/{id}/lines` | `finance.treasury_import_statement` | `AddManualStatementLineProcessor` (§0.4 mode manuel — rejette 409 si `statementImport.format != manual`), `read:true` | in: `bank_statement_line:write`, out: `:read` |
| `BankStatementLine` | `GET /finance/treasury/statement-lines/{id}/suggestions` | `finance.read` | `ReconciliationSuggestionProvider` → `BankReconciliationSuggestionCalculator` (§0.7, non persisté), `read:true` | — (JSON `list<ReconciliationCandidate>`) |
| `BankStatementLine` | `POST /finance/treasury/statement-lines/{id}/reconcile` | `finance.treasury_reconcile` | `ConfirmReconciliationProcessor` → `BankReconciliationHandler` (§0.6), `read:true`, corps `{ ledgerLineIds?: [uuid] }` (défaut = `suggestedLedgerLine` si `status = suggested`) | out: `:read` |
| `BankStatementLine` | `POST /finance/treasury/statement-lines/{id}/ignore` | `finance.treasury_reconcile` | `IgnoreStatementLineProcessor`, corps `{ reason }` (422 si vide), `read:true` | out: `:read` |
| — | `GET /finance/treasury/position` | `finance.read` | `TreasuryPositionProvider` (§0.8), query `asOf?`, `bankAccount?` | — (JSON `TreasuryPosition`) |
| — | `GET /finance/treasury/payment-schedule` | `finance.read` | `PaymentScheduleProvider` (§0.8), query `from`, `to` | — (JSON `PaymentSchedule`) |
| — | `GET /finance/treasury/cashflow-forecast` | `finance.read` | `CashflowForecastProvider` (§0.8), query `horizonDays` (7/30/90 conseillés) | — (JSON `CashflowForecast`) |
| — | `GET /finance/treasury/discrepancies` | `finance.read` | `DiscrepancyDashboardProvider` (§0.8, US-TRE-09) | — (JSON `list<BankStatementLine>` résumé) |
| `TreasurySettings` | `GetCollection`, `Get` | `finance.read` | — (filtré) | `treasury_settings:read` |
| `TreasurySettings` | `POST`, `Patch` | `finance.manage` | `TreasurySettingsProcessor` (vérifie `establishment` dans le périmètre, patron `ExpenseAccountMapping`/`ReconciliationSettings`) | `:read` / `:write` |

**Filtres** (`ApiFilter(SearchFilter::class, ...)`) : `BankAccount` → `active` exact ; `BankStatementImport`
→ `bankAccount` exact, `status` exact ; `BankStatementLine` → `status` exact, `statementImport` exact,
`statementImport.bankAccount` exact (propriété imbriquée, patron API Platform standard).

**Non exposé par ce lot** : `Delete` sur `BankAccount`/`BankStatementLine` (aucune suppression — un compte
se désactive, `RG-SOCLE-07`) ; les lignes ne sont **jamais** créées en masse via l'API standard (seulement
par le processor d'import ou l'ajout manuel unitaire) — pas de `POST` nested direct sur
`BankStatementLine` hors `AddManualStatementLineProcessor`.

> **Intégration `api_platform.yaml` — non modifiée par ce lot, même signalement que FIN-2/FIN-3** (§7
> point 5, **cumulatif** : `mapping.paths` ne contient **encore aucun** des trois répertoires
> `src/Finance/{SupplierInvoice,ExpenseReport,Treasury}/Entity` au moment de la rédaction de ce plan,
> vérifié dans `app/config/packages/api_platform.yaml`) — l'intégrateur A doit ajouter les **trois** en
> un seul passage, pas seulement celui de ce lot.

---

## 3. Sécurité & droits

- **Permissions déclarées par ce lot** (extension de `App\Finance\FinanceModule::permissions()`, §0.11) :
  `finance.treasury_manage_account`, `finance.treasury_import_statement`, `finance.treasury_reconcile`.
  Réutilisées, non redéclarées : `finance.read`, `finance.manage`. ⚠ Noms **proposés par analogie**,
  comme tous les modules déjà livrés, à arbitrer avec M8 avant figement (hérité de la spec §3/§9).
- **Voters** : aucun voter propre — `PermissionVoter` existant suffit ; le filtrage de périmètre est
  porté par `PerimetreFinanceExtension` (lecture) et les processors dédiés (écriture par corps brut),
  même séparation de responsabilités que FIN-1/FIN-2/FIN-3.
- **Cloisonnement — gardes explicites, tous échec fermé** :
  1. `BankAccountProcessor` : `establishment` → `PerimetreEtablissementVerificateur::verifier()` (403) ;
     `ledgerAccount` (si fourni) → `profilExploitant.couvre($establishment)` (422 sinon).
  2. `ImportBankStatementProcessor`/`ConfirmReconciliationProcessor`/`IgnoreStatementLineProcessor` :
     l'objet parent (`BankAccount`/`BankStatementLine`) est déjà résolu par le provider d'item standard,
     donc déjà filtré par `PerimetreFinanceExtension` (§0.2 point 2).
  3. `ConfirmReconciliationProcessor` : chaque `ledgerLineId` du corps → doit référencer une
     `LigneEcriture` dont `compte === bankAccount.ledgerAccount` (404 sinon, D8 explicite, §0.2 point 3)
     — un identifiant de ligne d'écriture appartenant à un autre compte, même du même établissement,
     est refusé (pas seulement un contrôle inter-établissement, un contrôle inter-comptes).
  4. `TreasurySettingsProcessor` : `establishment` du corps → `PerimetreEtablissementVerificateur::verifier()`.
- **Aucun secret manipulé en clair par l'API** — `ibanClear` est un champ d'entrée transitoire jamais
  renvoyé, jamais loggué (§0.3) ; le contenu de fichier reçu par `ImportBankStatementProcessor` (`content`
  base64) est **traité en mémoire, jamais persisté tel quel** (seul `contentHash` est stocké, même
  principe que l'attachement `SupplierInvoice`/`ExpenseReport` : référence/empreinte, pas le document
  brut en base).

---

## 4. Migrations

Quatre migrations additives, timestamps **après** la dernière migration existante du dépôt
(`Version20260820160200`, dernière constatée dans `app/migrations/` au moment de la rédaction, FIN-3) —
toutes `CREATE TABLE` (aucune table existante modifiée, brique entièrement nouvelle) :

- **`Version20260821090000`** — `CREATE TABLE finance_treasury_bank_account` (`id BINARY(16) PK`,
  `establishment_id BINARY(16) NOT NULL FK → org_etablissement`, `ledger_account_id BINARY(16) NULL FK →
  compta_compte_comptable`, `iban_cipher LONGTEXT NULL`, `iban_last4 VARCHAR(4) NOT NULL DEFAULT ''`,
  `bic VARCHAR(11) NOT NULL`, `label VARCHAR(140) NOT NULL`, `opening_balance DECIMAL(12,2) NOT NULL`,
  `opening_balance_date DATE NOT NULL`, `active TINYINT(1) NOT NULL DEFAULT 1`, `created_at DATETIME NOT
  NULL`, `created_by_id BINARY(16) NULL FK → sec_utilisateur`) + `INDEX
  idx_treasury_bank_account_establishment (establishment_id)`.
- **`Version20260821090100`** — `CREATE TABLE finance_treasury_bank_statement_import` (`id BINARY(16)
  PK`, `bank_account_id BINARY(16) NOT NULL FK → finance_treasury_bank_account`, `format VARCHAR(8) NOT
  NULL`, `file_name VARCHAR(255) NULL`, `file_mime_type VARCHAR(100) NULL`, `file_size INT NULL`,
  `content_hash VARCHAR(64) NULL`, `imported_at DATETIME NOT NULL`, `status VARCHAR(10) NOT NULL DEFAULT
  'imported'`, `error_message LONGTEXT NULL`, `lines_created INT NOT NULL DEFAULT 0`, `lines_skipped INT
  NOT NULL DEFAULT 0`, `created_by_id BINARY(16) NULL FK → sec_utilisateur`) + `UNIQUE INDEX
  uniq_treasury_statement_import_hash (bank_account_id, content_hash)` + index sur `bank_account_id`.
- **`Version20260821090200`** — `CREATE TABLE finance_treasury_bank_statement_line` (`id BINARY(16) PK`,
  `statement_import_id BINARY(16) NOT NULL FK → finance_treasury_bank_statement_import`, `operation_date
  DATE NOT NULL`, `label VARCHAR(255) NOT NULL`, `amount DECIMAL(12,2) NOT NULL`, `reference VARCHAR(140)
  NULL`, `status VARCHAR(10) NOT NULL DEFAULT 'unmatched'`, `suggested_ledger_line_id BINARY(16) NULL FK →
  compta_ligne_ecriture`, `matched_ledger_line_id BINARY(16) NULL FK → compta_ligne_ecriture`,
  `reconciliation_code VARCHAR(36) NULL`, `ignored_reason LONGTEXT NULL`, `discrepancy_notified_at
  DATETIME NULL`, `created_at DATETIME NOT NULL`) + `INDEX idx_treasury_statement_line_import_date
  (statement_import_id, operation_date)` + index sur `status`.
- **`Version20260821090300`** — `CREATE TABLE finance_treasury_settings` (`id BINARY(16) PK`,
  `establishment_id BINARY(16) NOT NULL FK → org_etablissement`, `unmatched_alert_delay_days INT NOT NULL
  DEFAULT 15`, `matching_window_days INT NOT NULL DEFAULT 5`) + `UNIQUE INDEX
  uniq_treasury_settings_establishment (establishment_id)`.

**Down** : les quatre migrations sont réversibles (`DROP TABLE`, ordre inverse pour respecter les FK),
rejouables (constitution §7). Aucune donnée existante affectée (tables entièrement nouvelles, aucune table
du noyau Compta/Sepa/Facturation modifiée).

---

## 5. Tests

| Test | Type | Couvre |
|---|---|---|
| `BankAccountApiTest::testIbanJamaisExposeEnClairEnLecture` | Fonctionnel API | **CA-1** : réponse JSON de `GET /bank-accounts/{id}` ne contient ni `ibanCipher` ni IBAN en clair sous aucune clé |
| `BankAccountApiTest::testIbanChiffreEtDechiffrableViaCoffreSepa` | Unit | §0.3 — round-trip `ChiffreurIban::chiffrer()`/`dechiffrer()` sur la valeur stockée |
| **`CloisonnementTreasuryTest::testEtablissementHorsPerimetreRefuse403`** | Fonctionnel API | §0.2 — `establishment` d'un autre périmètre dans le corps de création de `BankAccount` → 403, aucun compte créé (D8) |
| `CloisonnementTreasuryTest::testLedgerAccountAutreProfilRefuse422` | Fonctionnel API | §0.2 point 1 — IDOR inter-profils sur `ledgerAccount` |
| `CloisonnementTreasuryTest::testLigneRelevesHorsPerimetreInvisible` | Fonctionnel API | §0.2 point 2 — chaîne à deux sauts, `BankStatementLine` d'un autre établissement absente de la collection |
| **`ConfirmReconciliationTest::testLedgerLineIdDunAutreCompteRefuse404`** | Fonctionnel API | §0.2 point 3 — `ledgerLineIds` du corps référençant une ligne d'un autre `CompteComptable` → 404 (D8, garde explicite sur identifiant client) |
| `BankStatementImportTest::testMemeFichierReimporteRefuse409SansDupliquerLesLignes` | Fonctionnel API | **CA-2** : import CSV puis ré-import identique → 409, aucune `BankStatementLine` dupliquée (comptage avant/après, §0.5 niveau fichier) |
| `BankStatementImportTest::testFichierPartiellementRecouvrantIgnoreLesLignesDejaConnues` | Fonctionnel API | §0.5 niveau ligne — fichier avec 3 lignes déjà importées + 2 nouvelles → seulement 2 créées, `linesSkipped = 3` |
| `BankStatementImportTest::testLigneCsvIllisibleNInterrompPasLeReste` | Fonctionnel API | §0.4 dégradation propre — une ligne à date invalide n'empêche pas l'import des autres |
| `BankStatementImportTest::testFormatOfxNonEncoreSupporteRefuse422` | Fonctionnel API | §0.4 — `format: ofx` → 422 explicite, pas 500 |
| `BankStatementImportTest::testModeManuelToujoursDisponibleSansFichier` | Fonctionnel API | §0.4 mode dégradé — création d'un import `manual` sans `content`, puis ajout d'une ligne unitaire |
| `BankStatementImportTest::testCompteInactifAccepteImportMaisAucuneNouvelleSuggestion` | Fonctionnel API | §7 cas limite spec — import accepté sur compte inactif, commande de suggestion l'ignore |
| `BankReconciliationSuggestionCalculatorTest::testToleranceMontantStricteAucunEcartAccepte` | Unit | RG-TRE-03 — 500,01 € ne matche pas 500,00 € |
| `BankReconciliationSuggestionCalculatorTest::testDeuxCandidatsMemeMontantMemeDateListesTousLesDeux` | Unit | §7 cas limite spec — ambiguïté, aucune sélection automatique |
| `BankReconciliationSuggestionCalculatorTest::testAucunCandidatSiComptesComptableNonRenseigne` | Unit | §0.7 — `ledgerAccount = null` → liste vide, pas d'exception |
| **`BankReconciliationHandlerTest::testConfirmationUneSeuleLigneCreeLettrageAvecReconciliationCode`** | Fonctionnel API | **CA-3** : ligne 500 €/écriture scellée 500 € → `status = reconciled`, `reconciliationCode` non nul, **identique** sur `BankStatementLine` et sur la `LettrageEcriture` de la ligne d'écriture (§0.6, assertion explicite demandée par la mission) |
| `BankReconciliationHandlerTest::testConfirmationDeuxiemeFoisSurMemeLigneRefuse409` | Fonctionnel API | §0.6 — garde de non-double-lettrage ajoutée explicitement (absente de `lettrer()` nu) |
| `BankReconciliationHandlerTest::testConfirmationGroupeeLettrerGroupeSiEquilibree` | Fonctionnel API | §0.6 cas rare — 2 lignes 512 opposées confirmées ensemble, `lettrerGroupe()` appelé littéralement |
| `BankReconciliationHandlerTest::testConfirmationGroupeeDesequilibreeRefuse422` | Fonctionnel API | §0.6 — 2 lignes de même sens, jamais équilibrées |
| `IgnoreStatementLineTest::testIgnoreSansMotifRefuse422` | Fonctionnel API | §7 cas limite spec |
| **`TreasuryPositionProviderTest::testSommeExacteSoldesOuvertureEtLignesRapprocheesUniquement`** | Fonctionnel API | **CA-4** : deux comptes, soldes d'ouverture + lignes `reconciled` seulement (une ligne `suggested` non comptée) — somme exacte |
| **`PaymentScheduleProviderTest::testTenantSansSepaEchéancierSansSectionSepaSansErreur`** | Fonctionnel API | **CA-5** : base sans `RemiseSepa`/`ConfigCreancierSepa` → `entries[]` ne contient aucune ligne SEPA, `200 OK`, pas d'exception |
| `PaymentScheduleProviderTest::testSoldeFournisseurReutiliseSupplierInvoiceBalanceCalculator` | Unit | §0.8 — pas de second calcul de solde réinventé |
| `PaymentScheduleProviderTest::testSoldeFactureClientCalculeDepuisReglementFacture` | Unit | §0.8 |
| `CashflowForecastProviderTest::testProjectionArithmetiqueSansPonderation` | Unit | RG-TRE-08 |
| **`DiscrepancyDetectionCommandTest::testEmissionUneSeuleFoisParLigneMemeApresDeuxPassagesDuCron`** | Unit/Fonctionnel (CLI) | **CA-6** : deux exécutions successives de la commande sur la même ligne non rapprochée → un seul `treasury.discrepancy_detected` émis (garde `discrepancyNotifiedAt`, §0.9) |
| `DiscrepancyDetectionCommandTest::testCompteInactifExcluDeLaDetection` | Unit/Fonctionnel (CLI) | §7 cas limite spec |
| `DiscrepancyDashboardProviderTest::testTableauDeBordListeIndependammentDuFlagDeNotification` | Fonctionnel API | US-TRE-09 — visibilité dans le tableau de bord dès le délai dépassé, même avant le passage du cron |
| **`EventTenantTreasuryTest::testTenantDeriveDuCompteBancaireJamaisDuContexte`** | Fonctionnel/Unit | **D6** — assertion explicite demandée par la mission, même patron que FIN-2/FIN-3 |
| `FinanceModuleExtensionTest::testPermissionsEvenementsFeaturesTreasuryPresentsSansRegression` | Unit | §0.11 — les 3 permissions/2 événements/2 features de cette brique figurent dans `FinanceModule`, **sans supprimer** ceux déjà ajoutés par FIN-2/FIN-3 (non-régression sur le fichier partagé, 3ᵉ modification) |

---

## 6. Tâches (voir tasks-treasury.md)

- **T1** — Enums (`BankStatementImportFormat` incl. `Manual`, `BankStatementImportStatus`,
  `BankStatementLineStatus`) + entités `BankAccount`/`BankStatementImport`/`BankStatementLine` (sans API
  Platform ni processor) + migrations `Version20260821090000`/`…090100`/`…090200` + intégration
  `ChiffreurIbanInterface` (autowiring, réutilise le service SEPA existant, aucune nouvelle clé d'env) +
  tests unitaires d'entité.
- **T2** — `App\Finance\Treasury\Doctrine\PerimetreFinanceExtension` (§0.2 point 2, chaîne à deux sauts)
  + `#[ApiResource]` lecture seule (`GetCollection`/`Get`) sur les trois entités + tests de cloisonnement
  en lecture.
- **T3** — `BankAccountProcessor` (§0.2 point 1, §0.3 chiffrement IBAN) + tests (CA-1, cloisonnement
  création) — dépend de T1/T2.
- **T4** — `BankStatementParserInterface` + `CsvBankStatementParser` (§0.4, seul adaptateur construit) +
  `ImportBankStatementProcessor` (§0.5 déduplication à deux niveaux) + tests (CA-2, dégradation ligne
  illisible, format non supporté) — dépend de T3.
- **T5** — `BankStatementImportFormat::Manual` + `AddManualStatementLineProcessor` (§0.4 mode manuel) +
  tests — dépend de T4.
- **T6** — Entité `TreasurySettings` + migration `…090300` + CRUD `finance.manage` + tests — indépendant,
  peut être fait en parallèle de T4/T5.
- **T7** — `BankReconciliationSuggestionCalculator` (§0.7) + `ReconciliationSuggestionProvider` (`GET
  …/suggestions`) + tests (tolérance stricte, ambiguïté, compte non configuré) — dépend de T3, T6
  (`matchingWindowDays`).
- **T8** — Commande planifiée `finance:treasury:suggerer-rapprochements` (§0.7, persistance du statut
  `suggested`) + tests — dépend de T7.
- **T9** — `BankReconciliationHandler::confirmer()` (§0.6, décision d'architecture centrale de ce plan —
  `lettrer()` mono-ligne + `reconciliationCode` explicite, `lettrerGroupe()` cas multi-lignes) +
  `ConfirmReconciliationProcessor` + `IgnoreStatementLineProcessor` + tests (CA-3, non-double-lettrage,
  cas groupé) — dépend de T7, FIN-1 (déjà livré). **Point de vigilance** : confirmer le design §0.6 avec
  le propriétaire de `App\Compta` avant de merger ce jalon (§7 point 1).
- **T10** — Émission `treasury.reconciliation_completed` dans T9 (branché rétroactivement, jalon de revue
  distinct) + tests d'événement (D6) — dépend de T9.
- **T11** — `TreasuryPositionProvider` (§0.8, RG-TRE-05) + tests (CA-4) — dépend de T9 (ne compte que les
  lignes `reconciled`).
- **T12** — `PaymentScheduleProvider` (§0.8, RG-TRE-06/07 — réutilise `SupplierInvoiceBalanceCalculator`
  FIN-2, lit `Facture`/`ReglementFacture`/`RemiseSepa` en lecture seule) + tests (CA-5) — dépend de FIN-2,
  `App\Facturation`, `App\Sepa` (tous déjà livrés).
- **T13** — `CashflowForecastProvider` (§0.8, RG-TRE-08) + tests — dépend de T11, T12.
- **T14** — Commande planifiée `finance:treasury:detecter-ecarts` (§0.9, RG-TRE-09) +
  `DiscrepancyDashboardProvider` (`GET …/discrepancies`) + émission `treasury.discrepancy_detected` +
  tests (CA-6, idempotence cron) — dépend de T3, T6 (`unmatchedAlertDelayDays`).
- **T15** — Extension de `App\Finance\FinanceModule` (§0.11 : +3 permissions, +2 événements, +2 features,
  +1 route) + `FinanceModuleExtensionTest` — **coordination explicite, 3ᵉ modification du fichier
  partagé** : à merger en dernier, après relecture de l'état réel du fichier au moment du merge.
- **T16** — Revue de cohérence (constitution §8) : `GET /health`, rejeu complet
  `App\Tests\Compta\*`/`App\Tests\Sepa\*`/`App\Tests\Facturation\*`/`App\Tests\Finance\{SupplierInvoice,
  ExpenseReport}\*` existants (non-régression — ce lot ne modifie aucun fichier de ces modules),
  vérification qu'aucun libellé utilisateur n'est en dur (i18n — clés `finance.treasury.*`) ; ajout de
  `treasury.*` au `catalogue-evenements.md` partagé (§0.10, §7 point 5) ; intégration `mapping.paths`
  **signalée mais non faite**, à faire en un seul passage pour les **trois** briques Finance (§2, §7
  point 5).

---

## 7. Risques / à valider

1. **[CRITIQUE] Tension mécanique entre RG-TRE-04 (« appelle `lettrerGroupe()` ») et l'implémentation
   réelle de `LettrageHandler`** (§0.6) — `lettrerGroupe()` exige ≥ 2 lignes et un équilibre débit/crédit
   strict entre elles, un mécanisme conçu pour FIN-2/FIN-3 (créance/dette face à son règlement). Le cas
   courant de Treasury (1 ligne 512 déjà scellée face à 1 fait bancaire externe non comptable) ne peut
   **mécaniquement pas** satisfaire ce contrat. Ce plan retient `lettrer()` (mono-ligne, existant) +
   `reconciliationCode` posé explicitement après coup, réservant `lettrerGroupe()` au cas rare où 2+
   lignes réelles s'équilibrent. **À confirmer avant merge avec le propriétaire de `App\Compta`** —
   c'est la décision la plus structurante de ce plan, susceptible d'être remise en cause si une lecture
   différente de RG-TRE-04 est retenue côté métier.
2. **`App\Stock\Security\PerimetreEtablissementVerificateur` réutilisé une 4ᵉ fois** hors de son module
   d'origine (§0.2 point 1) — même risque déjà signalé deux fois (FIN-2 §7 point 1, FIN-3 §7 point 4),
   jamais traité. Ce lot ne fait pas la promotion (hors périmètre, éviter une régression croisée sur
   Stock déjà en production) mais la réclame une troisième fois — le seuil de 4 consommateurs
   indépendants du même service mal placé devrait suffire à justifier le nettoyage.
3. **`PerimetreFinanceExtension` — troisième copie quasi identique du même patron** (`SupplierInvoice`,
   `ExpenseReport`, maintenant `BankAccount`/`BankStatementImport`/`BankStatementLine`/`TreasurySettings`)
   — même recommandation de promotion vers un namespace partagé paramétrable, réitérée une troisième
   fois (FIN-2 §7 point 1, FIN-3 §7 point 4).
4. **Coordination de merge sur `App\Finance\FinanceModule`, 3ᵉ modification du même fichier** (§0.11) —
   risque technique faible (concaténation de listes) mais réel ; recommandation inchangée depuis FIN-3
   §7 point 3 : relire l'état réel du fichier avant d'étendre, jamais un merge automatique aveugle.
5. **`mapping.paths` (`api_platform.yaml`) toujours non mis à jour pour les trois briques Finance** —
   constat cumulatif : ni FIN-2 (`SupplierInvoice`), ni FIN-3 (`ExpenseReport`), ni ce lot
   (`Treasury`) n'apparaissent dans `app/config/packages/api_platform.yaml` au moment de la rédaction de
   ce plan (vérifié directement) — **aucune ressource des trois briques n'est actuellement enregistrée
   par API Platform**. Point de coordination urgent et cumulatif avec l'intégrateur A, à traiter en un
   seul passage plutôt que trois PR distinctes sur le même fichier de configuration. De même,
   `catalogue-evenements.md` ne porte encore aucune entrée `treasury.*` — à ajouter au même moment que
   les deux renommages déjà signalés par `spec-finance-suite.md` §0.
6. **`BankStatementImportFormat::Manual` — 4ᵉ valeur ajoutée au-delà des 3 littéralement listées par
   `spec-treasury.md` §5** (§0.4) — additive, nécessaire pour satisfaire l'exigence explicite de la
   mission (« + mode manuel »), mais non actée par la spec elle-même. À confirmer/aligner dans
   `spec-treasury.md` avant merge (même type de correction que FIN-2 §0.9 `correctedBy` →
   `correctsInvoice`).
7. **`matchedLedgerEntry` (spec) renommé `matchedLedgerLine`** (§1) — la spec référence
   `EcritureComptable`, ce plan pointe la `LigneEcriture` (512) précise, seule granularité utile pour le
   lettrage et les requêtes de position. Changement de précision, pas de comportement — à faire acter
   dans la spec avant merge.
8. **Convention de signe `BankStatementLine.amount` ↔ `debitCentimes`/`creditCentimes`** (§0.7) — la
   correspondance retenue (positif = crédit relevé = débit 512 comptable) est une source d'erreur
   classique en rapprochement bancaire ; documentée explicitement pour qu'un futur agent ne l'inverse pas
   silencieusement — un test dédié (`BankReconciliationSuggestionCalculatorTest`) rend cette convention
   exécutable plutôt qu'un commentaire seul.
9. **Absence de jeton de recherche HMAC sur l'IBAN** (contrairement à `ConfigCreancierSepa`, §0.3) —
   simplification assumée (aucun besoin de recherche par IBAN identifié pour `BankAccount`), mais à
   confirmer que ce n'est pas un oubli plutôt qu'une économie légitime, en particulier si un futur usage
   de rapprochement par IBAN émetteur apparaît (rapprochement OFX/CAMT.053, qui portent souvent l'IBAN de
   la contrepartie).
10. **Incohérence pré-existante dans `App\Finance\FinanceModule::features()`** (§0.11) — `supplier_invoices`/
    `ocr_supplier_invoices` (FIN-2) en sont absents alors que `expense_reports`/`ocr_expense_reports`
    (FIN-3) y figurent ; constat fait en préparant ce plan, **non corrigé par ce lot** (pas la
    responsabilité de FIN-4 de réparer un fichier partagé au-delà de sa propre extension) — signalé à
    l'intégrateur pour correction lors d'un passage dédié.
11. **`ModuleAccess`/`ModuleManifest` absents de `App\Sepa`/`App\Facturation`** (§0.8) — la dégradation
    propre de l'échéancier (CA-5) est donc implémentée au niveau données (absence de lignes), pas au
    niveau activation de module — cohérent avec l'état réel du dépôt, mais à revoir dès que ces modules
    legacy sont rétrofités avec un manifeste (tâche de coordination C5, déjà notée par FIN-2/FIN-3).
12. **`unmatchedAlertDelayDays` (défaut 15 j) et `matchingWindowDays` (défaut 5 j)** — valeurs choisies
    par ce plan, non données par la spec (qui les laisse paramétrables sans valeur par défaut) — à
    confirmer avec le métier.
13. **⚠ HORS BACKLOG** — comme l'indique l'en-tête de `spec-treasury.md`, aucune source ne couvre la
    Trésorerie dans le backlog existant : ce lot, comme les 4 autres de la suite, est à faire
    **valider et chiffrer** avant tout développement réel — rappel explicite, pas une nouveauté propre à
    ce plan.
14. **Devises étrangères, granularité de la suggestion heuristique au-delà de montant/date/référence,
    export du prévisionnel** — hors périmètre v1, non retranchés par ce plan (cohérent avec la consigne
    « ne pas re-trancher » de la constitution) ; hypothèses déjà actées par `spec-treasury.md` §7/§9.

---

## Récapitulatif pour l'intégrateur A

- **Entités nouvelles** : `BankAccount`, `BankStatementImport`, `BankStatementLine`, `TreasurySettings`
  (`App\Finance\Treasury\Entity`), aucune modification d'entité existante.
- **Migrations** : `Version20260821090000` à `…090300` (additives, après `Version20260820160200`, dernière
  du dépôt au moment de la rédaction).
- **Réutilisé, non dupliqué** : coffre IBAN `App\Sepa\Service\ChiffreurIban` (§0.3) ; `App\Compta\
  Service\LettrageHandler` (`lettrer()` + `lettrerGroupe()`, §0.6 — **aucune** `EcritureComptable`
  nouvelle créée par ce lot) ; `App\Finance\SupplierInvoice\Service\SupplierInvoiceBalanceCalculator`
  (FIN-2, réutilisé pour le solde fournisseur de l'échéancier) ; `App\Facturation\Entity\{Facture,
  ReglementFacture}` et `App\Sepa\Entity\RemiseSepa` en lecture seule ; `App\Stock\Security\
  PerimetreEtablissementVerificateur` (4ᵉ consommateur hors Stock).
- **Nouveau** : parseur CSV (`CsvBankStatementParser`, lot minimal explicite) derrière un port
  `BankStatementParserInterface` branchable — OFX/CAMT.053 **non construits**, extension future
  documentée (§0.4) ; moteur de suggestion heuristique (§0.7) ; deux commandes planifiées
  (`finance:treasury:suggerer-rapprochements`, `finance:treasury:detecter-ecarts`).
- **Événements** : `treasury.reconciliation_completed`, `treasury.discrepancy_detected` — **absents du
  catalogue partagé**, à y ajouter (§7 point 5).
- **Ordre des tâches** : T1→T3 (comptes bancaires) → T4/T5 (import CSV + mode manuel) ∥ T6 (réglages) →
  T7/T8 (suggestion) → T9/T10 (confirmation + événement, **jalon à valider avec le propriétaire de
  `App\Compta`, §7 point 1**) → T11/T12/T13 (position/échéancier/prévisionnel) → T14 (détection d'écart)
  → T15 (extension du manifeste, en dernier) → T16 (revue de cohérence).
- **Risque majeur unique à traiter avant tout code** : §7 point 1 (design de rapprochement via
  `LettrageHandler`) — tout le reste est additif et sans ambiguïté structurelle comparable.
- **Dette cumulative à traiter en un seul passage** : `api_platform.yaml` `mapping.paths` (3 briques
  Finance manquantes) + `catalogue-evenements.md` (`treasury.*` manquant + les 2 renommages déjà signalés
  par `spec-finance-suite.md` §0) — §7 point 5.

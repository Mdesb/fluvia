# Plan technique — Supplier invoices / Factures fournisseur (`App\Finance\SupplierInvoice`, lot `FIN-2`)

- **Spec source :** specs/finance/spec-supplier-invoices.md (+ specs/finance/spec-finance-suite.md §3/§5/§6)
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Contrat de plateforme :** COORDINATION/CONTRACT/manifeste-module.md (nouveau module `finance`),
  COORDINATION/CONTRACT/catalogue-evenements.md (4 événements `supplier_invoice.*` déjà catalogués),
  COORDINATION/CONTRACT/noyau-commun.md (invariants #1/#2/#3/#7), COORDINATION/DECISIONS.md **D5/D6/D7/D8**
  (impératifs de la mission, traités explicitement §0.2/§0.7/§0.8/§0.9)
- **Dépend de (déjà livré, réutilisé tel quel) :** `App\Compta` L4 + extension **FIN-1** (déjà en place —
  `DirectLedgerEntryBuilder`, `DirectLedgerEntryLine`, `ExpenseAccountMapping`/`ExpenseAccountMappingGuard`,
  `LettrageHandler::lettrerGroupe()`, `PeriodeComptableResolver`, `CompteLookupService`,
  `ScellementEcritureHandler`), `App\Stock` (`Fournisseur`, `CommandeAchat`/`LigneCommandeAchat`,
  `ReceptionAchat`/`LigneReceptionAchat`, `ArticleStock`, `PerimetreEtablissementVerificateur`),
  `App\Ocr` (`DocumentExtractor`, `DocumentKind::SupplierInvoice`), `App\Securite`
  (`ContexteEtablissement`, `CalculateurDroits`, `Utilisateur`), `App\Platform\Event`
  (`EventBus`/`DomainEvent`/`EventTenant`/`EventSubject`/`EventName`), `App\Platform\Module`
  (`ModuleManifest`/`ModuleRegistry`)
- **Couvre :** US-SINV-01 à US-SINV-09 · RG-SINV-01 à RG-SINV-12 · CA-1 à CA-9

> **Note de méthode.** Ce lot est le **premier consommateur réel** de `App\Platform\Event\EventBus`
> (aucun module ne l'utilise encore directement — seul `LegacyEventBridge` republie des événements PHP
> préexistants d'autres modules) et le **deuxième** module à implémenter `ModuleManifest` après
> `App\Ocr\OcrModule`. Plusieurs décisions d'intégration nouvelles en découlent, documentées §0.

---

## 0. Décisions d'architecture

### 0.1 Namespace et rattachement du module

`App\Finance\SupplierInvoice\{Entity,Enum,Dto,Service,State,Doctrine}` (brique), `App\Finance\FinanceModule`
(manifeste, racine du module — cartographie `spec-finance-suite.md` §2). Aucune entité de ce lot n'est
créée dans `App\Compta`/`App\Stock` : ils sont **référencés**, jamais étendus par ce lot (FIN-1 a déjà
fait l'extension nécessaire de `App\Compta`).

### 0.2 Cloisonnement (D3/D8) — deux mécanismes complémentaires, pas un seul

`SupplierInvoice` porte un **`establishment` direct** (comme `CommandeAchat`/`ReceptionAchat`, pas
seulement via `businessProfile`) — c'est l'**ancre de périmètre** de toute la brique et la source du
tenant d'événement (D6, §0.6).

1. **Lecture (`GetCollection`/`Get`/tout opérateur `read: true`)** : nouvelle extension Doctrine
   `App\Finance\SupplierInvoice\Doctrine\PerimetreFinanceExtension implements
   QueryCollectionExtensionInterface, QueryItemExtensionInterface` — **copie stricte du patron**
   `App\Stock\Doctrine\PerimetreStockExtension` (jointure jusqu'à `.etablissement` + `Affectation` de
   l'utilisateur courant). Chaînes : `SupplierInvoice => []`, `SupplierInvoiceLine =>
   ['supplierInvoice']`, `SupplierPayment => ['supplierInvoice']`. Cette extension protège aussi les
   endpoints d'action déclarés `read: true` (`/approve`, `/dispute`, `/resolve-dispute`, `/cancel`,
   `/credit-note`, `/reconciliation`) : le patron `read: true` **passe par le provider d'item standard**,
   donc par cette extension — cohérent avec la lecture de D8 (« une opération `read: false` **ou** un
   `find()` brut sort du filet » implique, a contrario, qu'un `read: true` normal y reste).
2. **Écriture par corps brut (`read: false`)** — `SupplierInvoiceProcessor` (création),
   `ExtractSupplierInvoiceProcessor` (aucune écriture mais résout l'établissement actif) : **revérification
   explicite**, patron `SaisirEcritureManuelleProcessor`/`LettrerGroupeProcessor` (FIN-1, §0.5 de
   `plan-comptabilite-generale.md`) — réutilise **directement**
   `App\Stock\Security\PerimetreEtablissementVerificateur::verifier()` (échec fermé 404) plutôt que de
   dupliquer sa logique, puisque `finance` dépend déjà de `stock` pour `Fournisseur`/`CommandeAchat`/
   `ReceptionAchat`. ⚠ **Point à faire remonter à l'intégrateur (§7, point 1)** : ce service vit dans
   `App\Stock\Security`, un namespace de module métier, alors qu'il ne contient aucune logique propre à
   Stock (il ne consomme que `Etablissement`/`ContexteEtablissement`/`CalculateurDroits`, tous socle) — un
   second module qui le réutilise (ici Finance) est le signal qu'il devrait être promu vers un namespace
   partagé (`App\Securite\Service`, à côté de `ContexteEtablissement`) ; ce lot **ne fait pas** ce
   déplacement (hors périmètre, risque de casser Stock) mais le signale.

### 0.3 OCR — endpoint dédié, pas de couplage direct dans la création

`App\Ocr\DocumentExtractor` est « consommé exclusivement en PHP par FIN-2/FIN-3 » (`OcrModule::routes()`,
commentaire explicite du code) — Finance doit donc exposer lui-même la route HTTP. Design retenu :
`POST /finance/supplier-invoices/extract` (corps `{ content: base64, mimeType }`, `read: false`,
`input: false`, `output: false`) → `ExtractSupplierInvoiceProcessor` appelle
`DocumentExtractor::extract($dto, DocumentKind::SupplierInvoice)` et renvoie les champs extraits **+**
l'IRI de l'`ExtractionAttempt` créé par `TenantAwareDocumentExtractor` (traçabilité, déjà automatique).
Le client pré-remplit son formulaire de brouillon puis appelle le `POST /finance/supplier-invoices`
**standard**, en passant en option `ocrExtraction` (IRI de cet `ExtractionAttempt`) — **aucune validation
n'est automatique** (CA-2) : les deux appels sont strictement découplés, l'utilisateur revoit chaque champ
entre les deux. Un OCR non configuré ou en échec renvoie `status: failed` (dégradation propre déjà gérée
par `TenantAwareDocumentExtractor`) — la saisie manuelle reste utilisable sans jamais appeler cette route.

### 0.4 Comptabilisation — **appel direct** à `DirectLedgerEntryBuilder`, pas un abonnement d'événement

La mission demande de trancher explicitement entre (a) un consumer de `supplier_invoice.recorded` côté
Compta ou (b) un appel direct au service de saisie FIN-1. **Décision : (b), appel direct**, dans le même
handler/la même transaction que la validation (`approve`), pour trois raisons :
1. **Atomicité.** RG-SINV-05 exige que la transition `draft → to_pay` **soit** le fait générateur
   comptable : au moment où `status = to_pay` est visible, l'écriture doit déjà exister, scellée. Un
   déclenchement par événement (même synchrone, D7) introduirait une dépendance à l'ordre d'abonnement et
   compliquerait le verrou pessimiste unique déjà nécessaire (§0.7) — deux préoccupations dans un seul
   verrou plutôt qu'un couplage caché entre un `process()` et un `EventSubscriber` externe.
2. **Précédent déjà posé.** `App\Facturation\Service\EmettreFactureDirecteHandler` (facture **client**)
   appelle déjà directement `App\Compta\Nf525\ScellementEcritureHandler` — Compta agit ici comme un
   **service de plateforme composé synchrone** (au même titre que `ContexteEtablissement`), pas comme un
   pair-domaine au sens strict de D2. `DirectLedgerEntryBuilder` (FIN-1) a été **conçu explicitement**
   pour ce cas d'usage (« pour que la saisie manuelle **et** FIN-2/FIN-3 ne dupliquent pas cette
   mécanique », doc-bloc du service).
3. **`supplier_invoice.recorded` reste émis**, mais comme **pur fait diffusé** (Treasury, Reporting,
   futurs consommateurs) — sans porter la responsabilité de la comptabilisation. Si Compta s'abonnait un
   jour à cet événement pour une raison indépendante, ce serait additif, jamais un doublon de ce que ce
   lot fait déjà de façon synchrone.

⚠ **Correction à porter au catalogue partagé (§7, point 2)** : `catalogue-evenements.md`/
`spec-finance-suite.md` §6 listent « Compta (FIN-1) » comme consommateur probable de
`supplier_invoice.recorded` — décision ci-dessus : ce n'est **pas** le mécanisme retenu par ce lot (appel
direct, pas d'abonnement). La ligne du catalogue reste correcte en tant que *catalogue d'intentions*
mais ne décrit pas l'implémentation réelle de FIN-2 ; à clarifier pour ne pas laisser un futur agent
implémenter un consumer Compta redondant.

### 0.5 Résolution des comptes — réutilise `CompteLookupService` (FIN-1/Régime), aucun nouveau moteur

`App\Finance\SupplierInvoice\Service\ResolveurComptesSupplierInvoice` (service fin, sans état propre) :
- `compteFournisseur(profil)` → `CompteLookupService::compteParPrefixe($profil, '401')` (compte
  collectif, RG-M6-13 : le détail par fournisseur passe par `counterpartyType`/`counterpartyId`/
  `counterpartyLabel` sur la `LigneEcriture`, **pas** un compte par fournisseur).
- `compteTvaDeductible(profil)` → `compteParPrefixe($profil, '4456')` (convention PCG « TVA déductible »,
  même patron que `compteTvaCollectee` → `4457` déjà utilisé par `RegimeRegieDirecte`/`RegimeDspPcg`).
- `compteTresorerie(profil)` → `compteParPrefixe($profil, '512')` — **même compte que `RegimeBase`**
  (ligne 118, déjà en production), pas une nouvelle convention.
- `journalAchats(profil)` → `CompteLookupService::journal($profil, 'ACH')` (**nouveau code**, absent de
  `ComptaFixtures` actuel — même situation que le journal `OD` déjà signalée comme risque non bloquant
  par FIN-1, §7 point 5 de son plan ; ce lot ajoute `ACH`/`BNQ` à ses **propres** fixtures de test, et
  documente la même recommandation de configuration).
- `journalReglements(profil)` → `journal($profil, 'BNQ')` (**nouveau code** — `REG` existant signifie
  « Journal de la régie », **pas** « règlements » au sens générique ; le réutiliser serait une confusion
  fonctionnelle, pas un raccourci technique valable).

Charge par ligne : `expenseAccount` résolu par `ExpenseAccountMappingGuard::resoudre($profil,
$ligne->expenseNatureCode)` (FIN-1, réutilisé tel quel) — un mapping incomplet/inactif **bloque**
`approve()` (CA-5), la facture reste `draft`. Le taux de TVA **appliqué** est celui de la ligne
(`SupplierInvoiceLine.vatRate`, ce que le fournisseur a réellement facturé, RG-M6-05 « pas de taux
moyen ») — le `deductibleVatRate` du mapping n'est **pas** utilisé pour construire l'écriture (il ne sert
qu'à qualifier la nature de charge par défaut à la saisie/l'OCR, hors périmètre strict de la
comptabilisation).

### 0.6 Événements — tenant dérivé de `SupplierInvoice.establishment` (D6), jamais du contexte HTTP

Les quatre événements du catalogue sont émis, **dans la même transaction** que l'action qui les
déclenche (D7) :

| Événement | Émis par | `EventTenant` | `EventSubject` | Payload |
|---|---|---|---|---|
| `supplier_invoice.recorded` | `SupplierInvoiceProcessor` (fin de création, statut `draft`) | `new EventTenant($facture->getEtablissement()->getId())` | `SupplierInvoice`/id | `supplierId`, `supplierInvoiceNumber`, `amountInclTaxCents`, `source` (`manual`/`ocr`) |
| `supplier_invoice.approved` | `SupplierInvoiceApprovalHandler` (après scellement) | idem | idem | `amountInclTaxCents`, `dueDate` (`Y-m-d`) |
| `supplier_invoice.paid` | `SupplierPaymentHandler` (seulement au passage `paid`, pas à chaque règlement partiel) | idem | idem | `amountInclTaxCents`, `date` (`Y-m-d`), `paymentMethod` (code) |
| `supplier_invoice.disputed` | `SupplierInvoiceDisputeHandler` (ouverture) | idem | idem | `reason` |

**Jamais** `ContexteEtablissement`/`idActif()` comme source du tenant, même si — par construction du
cloisonnement §0.2 — les deux coïncident presque toujours dans une requête normale : l'enveloppe lit
`$supplierInvoice->getEtablissement()->getId()` explicitement, seule source autorisée par D6.

### 0.7 Idempotence de la validation — même patron que `EmettreFactureDirecteHandler`

`SupplierInvoiceApprovalHandler::approuver()` :
1. Garde `estBrouillon()`/`estScellee()`-équivalent évalué **avant** la transaction (fail-fast) —
   résolution des comptes/journal/mapping AVANT toute écriture.
2. `$this->em->wrapInTransaction()` : `LockMode::PESSIMISTIC_WRITE` posé sur le `SupplierInvoice` en tout
   premier, garde **revérifiée après verrou** (`status === Draft`, `ledgerEntry === null`) → sinon
   `ConflictHttpException` (CA-4, seconde tentative concurrente rejetée proprement, aucune seconde
   `EcritureComptable`).
3. `PeriodeComptableResolver::resoudreOuCreer()` (et **non** `resoudre()`) : contrairement à la saisie
   manuelle OD (FIN-1, réservée à un usage humain ponctuel où l'absence de période est un signal
   d'anomalie), la validation d'une facture fournisseur est un flux opérationnel courant au même titre
   que le moteur ventes — création silencieuse de la période mensuelle si absente, **même comportement
   que `GenerateurEcrituresHandler`**.
4. `DirectLedgerEntryBuilder::construire()` (réutilisé tel quel) construit 1 ligne crédit « compte
   fournisseur » (401, montant TTC, `counterpartyType='stock_fournisseur'`) + 1 ligne débit par compte de
   charge distinct (Σ HT des lignes qui y résolvent) + 1 ligne débit par taux de TVA distinct présent
   parmi les lignes (compte 4456, `tauxTva` = le taux réel — RG-M6-05).
5. `ledgerEntry` de `SupplierInvoice` pointé vers l'écriture scellée, `status = ToPay`, `flush()`,
   émission `supplier_invoice.approved` (§0.6) **avant** le retour de la méthode (encore dans la
   transaction wrappée).

### 0.8 Règlement — lettrage **différé**, une ligne d'écriture par règlement

RG-SINV-07 exige un lettrage via `LettrageHandler::lettrerGroupe()` (FIN-1), dont l'invariant est
`Σdébit === Σcrédit` **sur l'ensemble des lignes passées en une seule fois**. Un règlement **partiel** ne
peut donc pas être lettré seul contre la ligne 401 de la facture (déséquilibre). Design retenu, documenté
ici car non détaillé littéralement par la spec (⚠ à valider, §7 point 3) :

1. Chaque `SupplierPayment` génère **sa propre écriture** de règlement via `DirectLedgerEntryBuilder`
   (débit 401 / crédit 512, montant du règlement, journal `BNQ`, `counterparty*` identiques à la ligne
   d'origine) — `SupplierPayment.ledgerEntry` pointe vers cette écriture. C'est la **seule** façon
   d'obtenir une `LigneEcriture` à passer à `lettrerGroupe()` (qui n'opère que sur des lignes déjà
   scellées, jamais sur un simple enregistrement déclaratif).
2. `SupplierPaymentHandler` calcule le **solde restant dû** = montant TTC de la ligne « compte
   fournisseur » − Σ des règlements déjà enregistrés (tous statuts sauf annulé — pas de suppression de
   règlement dans ce lot). Rejet 422 si `montant > solde` (§6 cas limite spec).
3. **Lettrage différé** : dès que Σ(règlements enregistrés) **égale exactement** le montant de la ligne
   401 d'origine, `lettrerGroupe([ligne401Facture, ligne401Reglement1, …, ligne401ReglementN], auteur)`
   est appelé **une seule fois**, sur l'ensemble des lignes 401 impliquées (celle de la facture + celle
   de chaque règlement qui a contribué) → `status = Paid`, émission `supplier_invoice.paid`.
4. Tant que le solde n'est pas nul, `status = PartiallyPaid`, **aucun lettrage** n'est tenté (CA-6 :
   1000 €/règlement de 400 € → `partially_paid`, solde 600 €, pas de `LettrageEcriture` créée à ce
   stade — testé explicitement, §5).

### 0.9 Avoir fournisseur — réutilise le moteur d'écritures, pas `ExtourneEcritureProcessor`

`App\Compta\State\ExtourneEcritureProcessor` (`/compta/ecritures/{id}/extourne`) est **couplé au régime
de vente** (`RegimeComptableResolver::genererEcritureExtourne()`, `AvoirProjectionDto`,
`venteOrigine`) et **total uniquement** (mirrors `totalDebitCentimes()` en bloc) — RG-SINV-09 exige un
avoir **total ou partiel**. Ce lot **ne réutilise donc pas ce processor tel quel** (le déclarer réutilisé
serait trompeur) mais reprend le **même principe** avec les briques génériques déjà disponibles :

- Nouveau `App\Finance\SupplierInvoice\Service\SupplierCreditNoteHandler` construit une **nouvelle**
  `EcritureComptable` via `DirectLedgerEntryBuilder`, lignes **miroir** (débit ↔ crédit inversés) de tout
  ou partie des lignes de l'écriture d'origine, et pointe le champ **générique et déjà existant**
  `EcritureComptable::pieceExtourneDe` vers l'écriture d'origine — ce champ n'est pas spécifique aux
  ventes, sa réutilisation est donc légitime et strictement additive.
- Un nouveau `SupplierInvoice` (`nature = CreditNote`) est créé, `correctsInvoice` (renommé depuis le
  `correctedBy` de la spec, §7 point 4) pointant vers la facture d'origine, `ledgerEntry` pointant vers
  la nouvelle écriture miroir.
- **Renommage documenté** : la table §5 de la spec nomme ce champ `correctedBy` mais le décrit comme
  « requis si nature = avoir » — porté par l'enregistrement **qui corrige**, pas celui qui est corrigé ;
  lu littéralement, `correctedBy` (« corrigé par ») désignerait l'inverse. Ce plan renomme en
  `correctsInvoice` (« corrige ») pour éviter d'écrire un nom trompeur dans le code — **aucun changement
  de comportement**, seulement de nom (à confirmer/aligner avec la spec avant merge, §7).

---

## 1. Entités & schéma

| Entité (`App\Finance\SupplierInvoice\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **`SupplierInvoice`** | id | uuid | non | PK | — |
| | establishment | uuid (FK) | non | index (`establishment`, `status`) | `Etablissement` (Organisation, socle) — ancre D6/D8 |
| | businessProfile | uuid (FK) | non | — | `ProfilExploitant` (Compta) — doit couvrir `establishment` (422 sinon, §0.2) |
| | supplier | uuid (FK) | non | index | `Fournisseur` (Stock) — doit appartenir au même `establishment` (404 sinon) |
| | nature | `string(12)` enum `SupplierInvoiceNature` | non | défaut `invoice` | `invoice`\|`credit_note` |
| | supplierInvoiceNumber | `string(64)` | non | — | texte libre, **pas** unique (RG-SINV, §7 cas limite spec) |
| | invoiceDate | `date_immutable` | non | — | — |
| | dueDate | `date_immutable` | non | — | — |
| | status | `string(16)` enum `SupplierInvoiceStatus` | non | défaut `draft` | `draft`\|`to_pay`\|`partially_paid`\|`paid`\|`disputed`\|`cancelled` |
| | purchaseOrder | uuid (FK) | **oui** | — | `CommandeAchat` (Stock), même `establishment` sinon 404 |
| | goodsReceipt | uuid (FK) | **oui** | — | `ReceptionAchat` (Stock), même `establishment` sinon 404 |
| | amountExclTax | `decimal(12,2)` | non | défaut `0.00` | Σ lignes, **recalculé serveur** à chaque écriture de ligne (jamais fourni par le client) |
| | amountInclTax | `decimal(12,2)` | non | défaut `0.00` | idem |
| | attachmentFileName / attachmentMimeType / attachmentSize / attachmentUrl | `string(255)` / `string(100)` / `int` / `string(500)` | **oui** | — | même patron que `App\Support\Entity\PieceJointeTicket` (métadonnées + URL déjà hébergée, aucun stockage de fichier construit par ce lot) |
| | ocrExtraction | uuid (FK) | **oui** | — | `ExtractionAttempt` (Ocr) — traçabilité de l'assistance (§0.3) |
| | source | `string(8)` enum `SupplierInvoiceSource` | non | défaut `manual` | `manual`\|`ocr` — dérivé serveur de la présence d'`ocrExtraction`, pas déclaré par le client |
| | ledgerEntry | uuid (FK) | **oui** | — | `EcritureComptable` (Compta) — posé par `approve()` (§0.7) |
| | disputeReason | `text` | **oui** | requis si `status = disputed` (validé applicativement) | RG-SINV-08 |
| | disputeResolutionReason | `text` | **oui** | requis à la clôture du litige | §4.7 spec |
| | correctsInvoice *(renommé, §0.9)* | uuid (FK, self) | **oui** | requis si `nature = credit_note` | pointe vers la facture d'origine |
| | createdAt | `datetime_immutable` | non | — | — |
| | createdBy | uuid (FK) | **oui** | — | `Utilisateur` (Securite) |
| **`SupplierInvoiceLine`** | id | uuid | non | PK | — |
| | supplierInvoice | uuid (FK) | non | index | `SupplierInvoice`, `inversedBy: lines` |
| | description | `string(255)` | non | — | — |
| | stockArticle | uuid (FK) | **oui** | — | `ArticleStock` (Stock) — appariement rapprochement (§4.3 spec) |
| | quantity | `decimal(12,3)` | non | `> 0` | — |
| | unitPriceExclTax | `decimal(12,4)` | non | `>= 0` | — |
| | vatRate | uuid (FK) | non | — | `TauxTva` (Compta) — doit appartenir au même `businessProfile` que la facture (422 sinon) |
| | expenseNatureCode | `string(64)` | non | — | résolu par `ExpenseAccountMappingGuard` à `approve()` |
| | amountExclTax | `decimal(12,2)` | non | calculé `quantity × unitPriceExclTax` | — |
| | vatAmount | `decimal(12,2)` | non | calculé | — |
| | amountInclTax | `decimal(12,2)` | non | calculé | — |
| **`SupplierPayment`** | id | uuid | non | PK | — |
| | supplierInvoice | uuid (FK) | non | index | `SupplierInvoice` |
| | date | `date_immutable` | non | — | — |
| | amount | `decimal(12,2)` | non | `> 0`, `<= solde dû` (validé applicativement, §0.8) | — |
| | paymentMethod | uuid (FK) | non | — | `MoyenPaiement` (Compta) |
| | reference | `string(64)` | **oui** | — | n° virement/chèque |
| | ledgerEntry | uuid (FK) | non | — | `EcritureComptable` propre au règlement (§0.8) |
| | reconciliationCode | `string(36)` | **oui** | — | copié depuis `LettrageEcriture.reconciliationCode` **seulement** au règlement qui solde la facture (§0.8 point 3) |
| | createdAt | `datetime_immutable` | non | — | — |
| | createdBy | uuid (FK) | **oui** | — | `Utilisateur` |
| **`ReconciliationSettings`** *(nouveau, admin — §0.5/spec §3)* | id | uuid | non | PK | — |
| | businessProfile | uuid (FK) | non | **unique** | `ProfilExploitant` — 1 réglage par profil, défaut applicatif si absent |
| | toleranceThresholdPercent | `decimal(5,2)` | non | défaut `5.00` | RG-SINV-03, seuil d'écart de rapprochement |
| *(non persisté)* `PurchaseReconciliationGap` (`App\Finance\SupplierInvoice\Dto`) | quantityGap, unitPriceGap, unitPriceGapPercent, thresholdExceeded | decimal/decimal/decimal/bool | — | calculé à la volée par `PurchaseReconciliationCalculator`, jamais stocké (§4.3 spec) |

> id = UUID (`symfony/uid`). Rattachement multi-entités : `establishment` **direct** sur `SupplierInvoice`
> (ancre de cloisonnement, D6/D8) ; `SupplierInvoiceLine`/`SupplierPayment` héritent du périmètre via leur
> parent (chaînes de jointure §0.2). `ReconciliationSettings` suit le patron `ExpenseAccountMapping` (FIN-1) :
> cloisonné par appartenance au `businessProfile`, **pas** d'extension Doctrine dédiée (contrôle explicite
> dans son processor, comme `ExpenseAccountMapping` — cohérence délibérée avec l'absence de
> `PerimetreComptaExtension` déjà constatée par FIN-1).

**Enums** (`App\Finance\SupplierInvoice\Enum`, valeurs anglaises D5) : `SupplierInvoiceStatus` (`Draft
= 'draft'`, `ToPay = 'to_pay'`, `PartiallyPaid = 'partially_paid'`, `Paid = 'paid'`, `Disputed =
'disputed'`, `Cancelled = 'cancelled'`), `SupplierInvoiceNature` (`Invoice = 'invoice'`, `CreditNote =
'credit_note'`), `SupplierInvoiceSource` (`Manual = 'manual'`, `Ocr = 'ocr'`).

---

## 2. API (API Platform)

| Ressource / route | Opération | `security:` | Processor/Provider | Groupes sérialisation |
|---|---|---|---|---|
| `SupplierInvoice` | `GetCollection`, `Get` | `finance.read` | — (filtré par `PerimetreFinanceExtension`) | `supplier_invoice:read` |
| `SupplierInvoice` | `POST /finance/supplier-invoices` | `finance.supplier_invoice_create` | `SupplierInvoiceProcessor` (D8 explicite, §0.2 point 2) | in: `supplier_invoice:write`, out: `supplier_invoice:read` |
| `SupplierInvoice` | `PATCH /finance/supplier-invoices/{id}` | `finance.supplier_invoice_create` | `SupplierInvoiceProcessor` (rejette 409 si `status != draft`) | idem |
| `SupplierInvoice` | `POST /finance/supplier-invoices/extract` | `finance.supplier_invoice_create` | `ExtractSupplierInvoiceProcessor` (§0.3), `read:false`, `input:false`, `output:false` | — (JSON brut : champs extraits + IRI `ExtractionAttempt`) |
| `SupplierInvoice` | `POST /finance/supplier-invoices/{id}/approve` | `finance.supplier_invoice_approve` | `ApproveSupplierInvoiceProcessor` → `SupplierInvoiceApprovalHandler` (§0.7), `read:true`, `input:false` | out: `supplier_invoice:read` |
| `SupplierInvoice` | `POST /finance/supplier-invoices/{id}/cancel` | `finance.supplier_invoice_create` | `CancelSupplierInvoiceProcessor` (`status == draft` seulement, sinon 409) | idem |
| `SupplierInvoice` | `POST /finance/supplier-invoices/{id}/dispute` | `finance.supplier_invoice_dispute` | `DisputeSupplierInvoiceProcessor`, corps `{ reason }` (422 si vide, CA-7), `read:true` | idem |
| `SupplierInvoice` | `POST /finance/supplier-invoices/{id}/resolve-dispute` | `finance.supplier_invoice_dispute` | `ResolveDisputeSupplierInvoiceProcessor`, corps `{ resolutionReason }` | idem |
| `SupplierInvoice` | `POST /finance/supplier-invoices/{id}/credit-note` | `finance.supplier_invoice_approve` (engage une nouvelle écriture, §0.9) | `CreditNoteSupplierInvoiceProcessor` → `SupplierCreditNoteHandler`, corps `{ lines?: [{ lineId, amount }], reason }` (absent `lines` = avoir total) | out: `supplier_invoice:read` |
| `SupplierInvoice` | `GET /finance/supplier-invoices/{id}/reconciliation` | `finance.read` | `ReconciliationGapProvider` → `PurchaseReconciliationCalculator` (§4.3 spec, non persisté) | — (JSON : `list<PurchaseReconciliationGap>` par ligne) |
| `SupplierInvoiceLine` | `GetCollection`, `Get`, `Post`, `Patch` | Lecture `finance.read` · Écriture `finance.supplier_invoice_create` | `SupplierInvoiceLineProcessor` (D8 : `supplierInvoice` référencé doit être dans le périmètre **et** `status == draft`, sinon 409 — RG-SINV-05 « scellée, plus aucune ligne modifiable » ; recalcule les totaux du parent, §0.9 note nested-vs-séparé) | `supplier_invoice_line:read` / `:write` |
| `SupplierPayment` | `GetCollection`, `Get` | `finance.read` | — (filtré) | `supplier_payment:read` |
| `SupplierPayment` | `Post` | `finance.supplier_invoice_pay` | `SupplierPaymentProcessor` → `SupplierPaymentHandler` (§0.8) | in: `supplier_payment:write`, out: `supplier_payment:read` |
| `ReconciliationSettings` | `GetCollection`, `Get` | `finance.read` | — | `reconciliation_settings:read` |
| `ReconciliationSettings` | `Post`, `Patch` | `finance.manage` | `ReconciliationSettingsProcessor` (vérifie `businessProfile->couvre($etablissementActif)`, patron `ExpenseAccountMapping` FIN-1) | `:read` / `:write` |

**Filtres** (`ApiFilter(SearchFilter::class, ...)`) : `SupplierInvoice` → `status` exact, `supplier`
exact, `businessProfile` exact, `nature` exact ; `SupplierInvoiceLine` → `supplierInvoice` exact ;
`SupplierPayment` → `supplierInvoice` exact.

**Non exposé par ce lot** : suppression de `SupplierInvoiceLine`/`SupplierPayment` (aucun `Delete` —
cohérent avec l'irréversibilité au-delà du brouillon, RG-SINV-09) ; les lignes sont créées **séparément**
de la facture (même patron que `LigneCommandeAchat`/`LigneReceptionAchat` côté Stock : le champ `lines`
de `SupplierInvoice` n'est exposé qu'en lecture, `Groups(['supplier_invoice:read'])` uniquement, jamais
en écriture nested).

> **Intégration `api_platform.yaml` — non modifiée par ce lot.** `mapping.paths` ne contient pas
> `src/Finance/Entity` ; **c'est à l'intégrateur d'ajouter** `'%kernel.project_dir%/src/Finance/SupplierInvoice/Entity'`
> (et `.../ApiResource` si des DTO API Platform hors-entité sont introduits pour
> `PurchaseReconciliationGap`) — sans cet ajout, aucune ressource de ce lot n'est enregistrée par API
> Platform (signalé, non fait, §7 point 5).

---

## 3. Sécurité & droits

- **Permissions consommées par ce lot** (sous-ensemble du module `finance` proposé par
  `spec-finance-suite.md` §5, réellement câblé par FIN-2 — les permissions `treasury_*`/`expense_report_*`
  ne sont **pas** déclarées par ce lot, elles arriveront avec FIN-3/FIN-4) : `finance.read`,
  `finance.supplier_invoice_create`, `finance.supplier_invoice_approve`, `finance.supplier_invoice_pay`,
  `finance.supplier_invoice_dispute`, `finance.manage`. ⚠ Comme tous les modules déjà livrés, noms
  **proposés par analogie**, à arbitrer avec M8 avant figement (hérité tel quel de la spec §3/§9).
- **Voters** : aucun voter dédié — `PermissionVoter` existant (`is_granted('PERM', 'module.action')`)
  suffit ; le filtrage de périmètre (établissement) est porté par `PerimetreFinanceExtension` (lecture)
  et les processors dédiés (écriture par corps brut) — §0.2, même séparation de responsabilités que FIN-1.
- **Cloisonnement — gardes explicites, tous échec fermé (404 « introuvable », jamais 403 qui révélerait
  l'existence hors périmètre — cohérent noyau commun #2)** :
  1. `SupplierInvoiceProcessor` (création) : `establishment` du corps → `PerimetreEtablissementVerificateur::verifier()`
     (Stock, réutilisé §0.2) ; `businessProfile` → `couvre($etablissement)` (422 sinon, IDOR inter-profils,
     même patron FIN-1 §0.5) ; `supplier` → doit appartenir au **même** `establishment` (404 sinon —
     un fournisseur d'un autre établissement n'existe pas dans ce périmètre) ; `purchaseOrder`/
     `goodsReceipt` (si fournis) → idem.
  2. `SupplierInvoiceLineProcessor` : `supplierInvoice` référencé → dans le périmètre (protégé par
     `PerimetreFinanceExtension` **si** la résolution de la relation passe par l'`IriConverter` standard —
     ⚠ à confirmer empiriquement, §7 point 6) **et** `status == draft` (409 sinon, RG-SINV-05).
  3. `SupplierPaymentProcessor` : `supplierInvoice` doit être `to_pay`/`partially_paid` (409 si
     `disputed`/`paid`/`draft`/`cancelled` — gel du règlement en litige, RG-SINV-08).
  4. `ReconciliationSettingsProcessor` : `businessProfile` doit être couvert par l'établissement actif
     (patron `ExpenseAccountMapping`, FIN-1).
- **Aucun secret manipulé par ce lot** — le document source (`attachmentUrl`) est une référence externe
  déjà hébergée (§1), jamais transmise en base64 en base ; l'OCR chiffre déjà ses propres clés fournisseur
  (`App\Ocr\Service\ChiffreurApiKeyOcr`, hors périmètre de ce lot).

---

## 4. Migrations

Quatre migrations additives, timestamps après la dernière migration existante
(`Version20260819140100`), toutes `CREATE TABLE` (aucune table existante modifiée — brique entièrement
nouvelle) :

- **`Version20260820090000`** — `CREATE TABLE finance_supplier_invoice` (colonnes du tableau §1 :
  `id BINARY(16) PK`, `establishment_id BINARY(16) NOT NULL FK → org_etablissement`,
  `business_profile_id BINARY(16) NOT NULL FK → compta_profil_exploitant`,
  `supplier_id BINARY(16) NOT NULL FK → stk_fournisseur`, `nature VARCHAR(12) NOT NULL DEFAULT
  'invoice'`, `supplier_invoice_number VARCHAR(64) NOT NULL`, `invoice_date DATE NOT NULL`,
  `due_date DATE NOT NULL`, `status VARCHAR(16) NOT NULL DEFAULT 'draft'`,
  `purchase_order_id BINARY(16) NULL FK → stk_commande_achat`,
  `goods_receipt_id BINARY(16) NULL FK → stk_reception_achat`,
  `amount_excl_tax DECIMAL(12,2) NOT NULL DEFAULT '0.00'`, `amount_incl_tax DECIMAL(12,2) NOT NULL
  DEFAULT '0.00'`, `attachment_file_name VARCHAR(255) NULL`, `attachment_mime_type VARCHAR(100) NULL`,
  `attachment_size INT NULL`, `attachment_url VARCHAR(500) NULL`,
  `ocr_extraction_id BINARY(16) NULL FK → ocr_extraction_attempt`, `source VARCHAR(8) NOT NULL DEFAULT
  'manual'`, `ledger_entry_id BINARY(16) NULL FK → compta_ecriture_comptable`, `dispute_reason
  LONGTEXT NULL`, `dispute_resolution_reason LONGTEXT NULL`, `corrects_invoice_id BINARY(16) NULL FK →
  finance_supplier_invoice`, `created_at DATETIME NOT NULL`, `created_by_id BINARY(16) NULL FK →
  sec_utilisateur`) + `INDEX idx_supplier_invoice_establishment_status (establishment_id, status)` +
  index sur `supplier_id`.
- **`Version20260820090100`** — `CREATE TABLE finance_supplier_invoice_line` (`id BINARY(16) PK`,
  `supplier_invoice_id BINARY(16) NOT NULL FK → finance_supplier_invoice`, `description VARCHAR(255)
  NOT NULL`, `stock_article_id BINARY(16) NULL FK → stk_article_stock`, `quantity DECIMAL(12,3) NOT
  NULL`, `unit_price_excl_tax DECIMAL(12,4) NOT NULL`, `vat_rate_id BINARY(16) NOT NULL FK →
  compta_taux_tva`, `expense_nature_code VARCHAR(64) NOT NULL`, `amount_excl_tax DECIMAL(12,2) NOT
  NULL`, `vat_amount DECIMAL(12,2) NOT NULL`, `amount_incl_tax DECIMAL(12,2) NOT NULL`) + index sur
  `supplier_invoice_id`.
- **`Version20260820090200`** — `CREATE TABLE finance_supplier_payment` (`id BINARY(16) PK`,
  `supplier_invoice_id BINARY(16) NOT NULL FK → finance_supplier_invoice`, `date DATE NOT NULL`,
  `amount DECIMAL(12,2) NOT NULL`, `payment_method_id BINARY(16) NOT NULL FK → compta_moyen_paiement`,
  `reference VARCHAR(64) NULL`, `ledger_entry_id BINARY(16) NOT NULL FK → compta_ecriture_comptable`,
  `reconciliation_code VARCHAR(36) NULL`, `created_at DATETIME NOT NULL`, `created_by_id BINARY(16)
  NULL FK → sec_utilisateur`) + index sur `supplier_invoice_id`.
- **`Version20260820090300`** — `CREATE TABLE finance_reconciliation_settings` (`id BINARY(16) PK`,
  `business_profile_id BINARY(16) NOT NULL FK → compta_profil_exploitant`, `tolerance_threshold_percent
  DECIMAL(5,2) NOT NULL DEFAULT '5.00'`) + `UNIQUE INDEX uniq_reconciliation_settings_profil
  (business_profile_id)`.

**Down** : les quatre migrations sont réversibles (`DROP TABLE`, ordre inverse pour respecter les FK),
rejouables (constitution §7). Aucune donnée existante affectée (tables entièrement nouvelles).

---

## 5. Tests

| Test | Type | Couvre |
|---|---|---|
| `SupplierInvoiceApiTest::testCreationBrouillonSansCommandeNiReception` | Fonctionnel API | CA-1, §7 cas limite « facture sans commande ni réception » — valide, aucun rapprochement |
| `SupplierInvoiceApiTest::testFournisseurInactifRefuseNouvelleFacture` | Fonctionnel API | CA-1 : `Fournisseur.actif = false` → 422 à la création ; une facture **historique** déjà liée reste consultable |
| **`CloisonnementSupplierInvoiceTest::testEtablissementHorsPerimetreRefuse404`** | Fonctionnel API | §0.2 — `establishment` d'un autre périmètre dans le corps de création → 404, aucune facture créée (test de cloisonnement explicitement demandé par la mission, D8) |
| `CloisonnementSupplierInvoiceTest::testBusinessProfileAutreEtablissementRefuse422` | Fonctionnel API | §0.2 point 1 — IDOR inter-profils |
| `CloisonnementSupplierInvoiceTest::testFournisseurAutreEtablissementRefuse404` | Fonctionnel API | §0.2 point 1 |
| `CloisonnementSupplierInvoiceTest::testLigneSurFactureHorsPerimetreRefuse404` | Fonctionnel API | §3 point 2 — `SupplierInvoiceLine` créée en référençant une facture d'un autre périmètre |
| **`EventTenantSupplierInvoiceTest::testTenantDeriveDeLEtablissementFactureJamaisDuContexte`** | Fonctionnel/Unit | D6 — un appelant dont le `X-Etablissement` actif diffère de l'établissement réel de la facture (ex. utilisateur multi-établissement) déclenche un événement dont `tenant.establishmentId` correspond **strictement** à `SupplierInvoice.establishment`, jamais à `ContexteEtablissement::idActif()` (assertion explicite demandée par la mission) |
| `SupplierInvoiceOcrTest::testExtractionPreRemplitSansValiderAutomatiquement` | Fonctionnel API | CA-2 : `POST .../extract` renvoie des champs, la facture reste `draft` tant que `POST /finance/supplier-invoices` n'a pas été appelé explicitement — aucune création automatique |
| **`SupplierInvoiceOcrTest::testOcrNonConfigureModeDegradeSaisieManuelleFonctionne`** | Fonctionnel API | CA-2, mode dégradé explicitement demandé par la mission — `DocumentExtractor` renvoie `failed` (aucun `OcrProviderConfig`), la création manuelle standard aboutit normalement |
| `PurchaseReconciliationCalculatorTest::testEcartPrixSignaleNonBloquant` | Unit | CA-3 : réception 100×4,00 €, facture 100×4,50 € → `PurchaseReconciliationGap.thresholdExceeded = true`, `approve()` **n'est pas bloqué** |
| `PurchaseReconciliationCalculatorTest::testSansCommandeNiReceptionAucunEcartCalcule` | Unit | §4.3 spec |
| `SupplierInvoiceApprovalHandlerTest::testValidationGenereEcritureEquilibreeEtScelle` | Unit/Fonctionnel | CA-4 : `status → to_pay`, `ledgerEntry` scellée, Σdébit = Σcrédit |
| **`SupplierInvoiceApprovalHandlerTest::testDeuxiemeValidationConcurrenteRejetee409`** | Fonctionnel API | CA-4 — idempotence, verrou pessimiste (§0.7), une seule `EcritureComptable` créée même avec deux appels concurrents |
| **`SupplierInvoiceApprovalHandlerTest::testMappingChargeIncompletBloqueValidation422`** | Fonctionnel API | CA-5 : ligne avec `expenseNatureCode` sans `ExpenseAccountMapping` actif → 422, facture reste `draft`, aucune écriture créée (comptage avant/après) |
| `SupplierPaymentHandlerTest::testReglementTotalPasseAPaidEtLettre` | Fonctionnel API | CA-6 (1000 €/1000 €) : `status → paid`, une `LettrageEcriture` groupée créée, même `reconciliationCode` sur les deux lignes 401 |
| `SupplierPaymentHandlerTest::testReglementPartielPasseAPartiallyPaidSansLettrage` | Fonctionnel API | CA-6 (1000 €/400 €) : `status → partially_paid`, solde 600 €, **aucune** `LettrageEcriture` créée (§0.8 point 4) |
| `SupplierPaymentHandlerTest::testReglementSuperieurAuSoldeRejete422` | Fonctionnel API | §7 cas limite spec |
| `SupplierPaymentHandlerTest::testReglementSurFactureLitigieuseRefuse409` | Fonctionnel API | RG-SINV-08 — gel |
| `SupplierInvoiceDisputeTest::testOuvertureSansMotifRefusee422` | Fonctionnel API | CA-7 |
| `SupplierInvoiceDisputeTest::testOuvertureAvecMotifGeleLesReglements` | Fonctionnel API | CA-7 |
| `SupplierInvoiceDisputeTest::testClotureLitigeTraceeMotifResolution` | Fonctionnel API | §4.7 spec |
| `SupplierCreditNoteHandlerTest::testAvoirTotalGenereEcritureMiroirSansModifierOrigine` | Fonctionnel API | CA-8 : aucune ligne d'origine modifiée, nouvelle écriture avec `pieceExtourneDe` pointant l'écriture d'origine, Σdébit/crédit inversée |
| `SupplierCreditNoteHandlerTest::testAvoirPartielMontantReduit` | Fonctionnel API | §4.8 spec « total ou partiel » |
| `SupplierCreditNoteHandlerTest::testFactureDraftOuAnnuleeNePeutPasRecevoirAvoir` | Unit | RG-SINV-09 — l'avoir corrige une facture **validée**, pas un brouillon (rejet 409/422) |
| **`SupplierInvoiceEventTest::testRecordedEmisAvecPayloadAttendu`** | Fonctionnel/Unit | CA-9 : `supplier_invoice.recorded` publié avec `supplier`, `amount`, `source` dans le payload |
| `SupplierInvoiceEventTest::testApprovedPaidDisputedEmisAuxTransitionsAttendues` | Unit | §0.6 — un événement par transition, jamais à un autre moment |
| `FinanceModuleManifestTest::testManifestConstructibleSansArgumentEtPermissionsValides` | Unit | contrat `ModuleManifest` (patron `ManifestCatalogueTest` existant) — noms de permissions/événements conformes au catalogue |
| `FinanceModuleRegistryBootTest::testRegistreDemarreSansDependanceNonResolue` | Unit/Fonctionnel | §0.10 (nouveau, voir ci-dessous) — vérifie que `FinanceModule::dependencies()` ne fait **pas** échouer `ModuleRegistry` au démarrage tant que `stock`/`compta`/`sepa`/`personnel`/`autorisation` n'implémentent pas eux-mêmes `ModuleManifest` (§7 point 7) |

---

## 6. Tâches (voir tasks-supplier-invoices.md)

- **T1** — Enums (`SupplierInvoiceStatus`/`Nature`/`Source`) + entités `SupplierInvoice`/
  `SupplierInvoiceLine` (sans API Platform ni processor) + migrations `Version20260820090000`/
  `…090100` + tests unitaires d'entité (calculs de ligne, totaux).
- **T2** — `App\Finance\SupplierInvoice\Doctrine\PerimetreFinanceExtension` (§0.2 point 1) +
  `#[ApiResource]` lecture seule (`GetCollection`/`Get`) sur `SupplierInvoice`/`SupplierInvoiceLine` +
  tests de cloisonnement en lecture.
- **T3** — `ResolveurComptesSupplierInvoice` (§0.5) + fixtures de test (`ACH`/`BNQ`, comptes
  401/4456/512) + `SupplierInvoiceProcessor` (création, D8 explicite §0.2 point 2) +
  `SupplierInvoiceLineProcessor` (création/édition de ligne, recalcul des totaux parent, garde
  `status == draft`) + tests (dépend de T1/T2, de FIN-1 déjà livré).
- **T4** — `PurchaseReconciliationCalculator` + DTO `PurchaseReconciliationGap` +
  `ReconciliationSettings` (entité, migration `…090300`, CRUD `finance.manage`) +
  `ReconciliationGapProvider` (`GET .../reconciliation`) + tests (CA-3).
- **T5** — `SupplierInvoiceApprovalHandler` (§0.7, verrou pessimiste + `DirectLedgerEntryBuilder` +
  `ExpenseAccountMappingGuard`) + `ApproveSupplierInvoiceProcessor` + tests (CA-4, CA-5) — dépend de T3,
  FIN-1.
- **T6** — Entité `SupplierPayment` + migration `…090200` + `SupplierPaymentHandler` (§0.8, lettrage
  différé) + `SupplierPaymentProcessor` + tests (CA-6, cas limites) — dépend de T5.
- **T7** — `SupplierInvoiceDisputeHandler` + `DisputeSupplierInvoiceProcessor`/
  `ResolveDisputeSupplierInvoiceProcessor` + tests (CA-7) — dépend de T5.
- **T8** — `SupplierCreditNoteHandler` (§0.9) + `CreditNoteSupplierInvoiceProcessor` + tests (CA-8) —
  dépend de T5.
- **T9** — Émission des 4 événements (§0.6) dans T3/T5/T6/T7 (branché rétroactivement, pas un lot séparé
  en pratique mais suivi comme jalon distinct pour la revue) + tests d'événement (CA-9) — dépend de T3,
  T5, T6, T7.
- **T10** — `ExtractSupplierInvoiceProcessor` (§0.3, appel `DocumentExtractor`) + tests OCR (CA-2,
  dégradé) — dépend de T3, `App\Ocr` (déjà livré).
- **T11** — `App\Finance\FinanceModule implements ModuleManifest` (§7 point 7 : `dependencies(): []`
  documenté) + `FinanceModuleManifestTest`/`FinanceModuleRegistryBootTest` — peut être fait tôt (aucune
  dépendance technique sur T1-T10) mais listé en fin pour refléter que ses `permissions()`/
  `eventsEmitted()` ne sont figés qu'une fois T1-T10 stabilisées.
- **T12** — Revue de cohérence (constitution §8) : `GET /health`, rejeu complet
  `App\Tests\Compta\*`/`App\Tests\Stock\*`/`App\Tests\Ocr\*` existants (non-régression — ce lot ne
  modifie aucun fichier de ces modules), vérification qu'aucun libellé utilisateur n'est en dur
  (i18n — clés `finance.supplier_invoice.*`, cohérent `i18n-traduction.md`), intégration de
  `mapping.paths` **signalée mais non faite** (§2, à la charge de l'intégrateur).

---

## 7. Risques / à valider

1. **`App\Stock\Security\PerimetreEtablissementVerificateur` réutilisé hors de son module d'origine**
   (§0.2 point 2) — fonctionnellement correct (aucune logique propre à Stock), mais son emplacement
   namespace devient trompeur dès qu'un deuxième module le consomme. **Recommandation** : promotion vers
   `App\Securite\Service` dans un lot de nettoyage dédié (hors périmètre FIN-2, ne pas faire dans ce
   lot pour ne pas risquer une régression sur Stock déjà en production).
2. **Écart entre `catalogue-evenements.md`/`spec-finance-suite.md` §6 et l'implémentation retenue** —
   le catalogue liste « Compta (FIN-1) » comme consommateur probable de `supplier_invoice.recorded` ;
   ce plan retient un **appel direct** (§0.4), pas un abonnement. Recommandation : corriger la colonne
   « consommateurs probables » du catalogue partagé pour éviter qu'un futur agent implémente un consumer
   Compta redondant avec la comptabilisation déjà faite de façon synchrone.
3. **Lettrage différé des règlements partiels (§0.8)** — mécanisme **non détaillé littéralement** par
   `spec-supplier-invoices.md` (qui cite `lettrerGroupe()` sans préciser le cas partiel) ; ce plan propose
   qu'un règlement partiel génère sa propre écriture (débit 401/crédit 512) mais **diffère** le lettrage
   groupé jusqu'au règlement qui solde exactement la ligne d'origine. Solution techniquement cohérente
   avec l'invariant strict de `lettrerGroupe()` (Σdébit = Σcrédit), mais à **valider avec un
   expert-comptable** : un rapprochement bancaire réel tolère parfois un lettrage partiel affiché comme
   « en cours », ce que ce design ne fait pas (le solde reste visible via `SupplierInvoice`, pas via
   `LettrageEcriture`, avant le règlement soldant).
4. **Renommage `correctedBy` → `correctsInvoice`** (§0.9) — corrige une incohérence de nommage relevée
   dans la spec (le sens littéral de « correctedBy » contredit son usage documenté « requis si nature =
   avoir »). Changement de nom uniquement, sans impact comportemental — **à faire acter dans
   `spec-supplier-invoices.md` avant merge** pour que spec et code restent synchronisés.
5. **`api_platform.yaml` `mapping.paths`** — ce lot ne le modifie pas (hors périmètre de la mission,
   « c'est l'intégrateur qui l'ajoute »). Sans cet ajout, **aucune** ressource de ce lot n'apparaît dans
   l'API une fois le code posé — point de coordination explicite avec l'intégrateur A, pas un oubli.
6. **Protection D8 de la dénormalisation de relation par IRI (`SupplierInvoiceLine.supplierInvoice`,
   `SupplierPayment.paymentMethod`, etc.)** — ce plan **suppose** que la résolution standard d'une
   relation `ManyToOne` par IRI (API Platform, hors `read: false`) passe par l'`IriConverter` puis le
   provider d'item standard, donc par `PerimetreFinanceExtension` (même mécanisme que toute relation
   Stock déjà en production, ex. `LigneCommandeAchat.commandeAchat`). **Cette hypothèse n'est vérifiée
   nulle part explicitement dans le dépôt actuel** (aucun test ne l'assert pour Stock non plus) — si elle
   est fausse, **toutes** les relations dénormalisées par IRI du dépôt (pas seulement Finance) partagent
   la même brèche silencieuse que celle qui a motivé D8. Recommandation forte à l'intégrateur : un test
   transverse unique, au niveau plateforme, qui vérifie ce comportement une fois pour toutes plutôt que
   chaque module ne le re-suppose séparément.
7. **`FinanceModule::dependencies()` et `ModuleRegistry`** — la spec de suite propose
   `dependencies(): ['compta', 'stock', 'sepa', 'personnel', 'autorisation']` (§3.1 `spec-finance-suite.md`).
   **Constat fait en préparant ce plan** : `App\Platform\Module\ModuleRegistry::assertDependenciesAreResolved()`
   fait échouer **tout le démarrage applicatif** (`\LogicException`) si une dépendance déclarée n'est pas
   elle-même un `id` de module enregistré — or **aucun** de `compta`/`stock`/`sepa`/`personnel`/
   `autorisation` n'implémente `ModuleManifest` aujourd'hui (seul `App\Ocr\OcrModule` le fait). Déclarer
   ces dépendances telles quelles **casserait le boot de l'application entière** dès que `FinanceModule`
   serait enregistré. **Décision de ce plan** : `FinanceModule::dependencies(): []` (aucune, pour l'instant),
   documentée comme état **transitoire** — à revoir dès que ces modules legacy sont eux-mêmes rétrofités
   avec un `ModuleManifest` minimal (hors périmètre FIN-2, tâche de coordination C5 déjà notée par
   `manifeste-module.md`). Point à remonter à l'intégrateur A **avant** merge, pas une hypothèse à valider
   silencieusement.
8. **Comptes `401`/`4456`/`512` résolus par préfixe unique** (§0.5) — cohérent avec l'existant
   (`RegimeBase`), mais suppose qu'un seul compte 401 collectif suffit pour tous les fournisseurs d'un
   profil exploitant (le détail par fournisseur passant uniquement par `counterparty*`, RG-M6-13). Un
   profil exploitant qui utiliserait plusieurs comptes 401 par catégorie de fournisseur (pratique parfois
   vue en DSP/PCG) ne serait pas couvert — non signalé comme un besoin par la spec, mais à confirmer.
9. **Journaux `ACH`/`BNQ` non seedés** dans `ComptaFixtures` — même situation, même traitement que le
   journal `OD` déjà signalé par FIN-1 (§7 point 5 de son plan) : aucun blocage technique (n'importe quel
   `Journal` du profil fonctionnerait), mais recommandé comme configuration initiale et à ajouter aux
   fixtures de test de ce lot.
10. **Devises étrangères, unicité de `supplierInvoiceNumber`, statut `disputed` post-`paid`** — hypothèses
    déjà actées et non retenues v1 par la spec elle-même (§7/§9 points 4/5/6) ; ce plan les reprend sans
    les retrancher, cohérent avec la consigne « ne pas re-trancher » de la constitution.

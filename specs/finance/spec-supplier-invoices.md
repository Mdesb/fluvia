# Spec — Supplier invoices / Factures fournisseur (`App\Finance\SupplierInvoice`, lot `FIN-2`)

- **Lot / module :** module **nouveau**, `finance` (capacité) / feature `supplier_invoices` — voir
  `spec-finance-suite.md` pour le manifeste complet. Convention de nommage : identifiants techniques en
  anglais, prose en français (`spec-finance-suite.md` §0).
- **Stories couvertes :** **US-SINV-01 à US-SINV-09** — ⚠ **HORS BACKLOG** : aucune source
  (`backlog.html`/`cahier-detaille.html`) ne couvre les factures fournisseur ; `spec-compta.md` §2
  exclut explicitement « la comptabilité fournisseurs générale » du périmètre M6, et `spec-stock.md`
  §2.3/§8 signale ce **gap** explicitement (« aucun module de ce dépôt ne couvre à ce jour la
  comptabilité fournisseurs »). Cette spec **répond à ce gap**. À faire valider/chiffrer avant
  développement.
- **Règles de gestion :** **RG-SINV-01 à RG-SINV-12** (nouvelles). Règles **réutilisées, non
  redéfinies** : `RG-SOCLE-01` à `07`, `RG-STOCK-03/04/05/06` (fournisseur, commande, réception,
  `spec-stock.md`), `RG-M6-04`/`RG-M6-12`/`RG-M6-13`/`RG-M6-14` (`spec-comptabilite-generale.md`).
- **Statut :** brouillon.

## 1. Objectif

Permettre à un comptable de **saisir, suivre et régler** une facture reçue d'un fournisseur — avec ou
sans commande d'achat préalable — de la **réception de la facture** jusqu'à son **règlement complet**,
en la **rapprochant** le cas échéant avec la commande et la réception physique déjà tracées par
`App\Stock`, sans jamais dupliquer le référentiel fournisseur ni le moteur d'écritures existants.

## 2. Périmètre

### Inclus
- **Fournisseur** : réutilisation **stricte** de `App\Stock\Entity\Fournisseur` (US-SINV-01) — aucun
  second référentiel créé.
- **Saisie d'une facture fournisseur**, manuelle ou assistée par OCR (US-SINV-02, RG-SINV-01/02),
  rattachée en option à une `CommandeAchat`/`ReceptionAchat` (`App\Stock`) existante.
- **Rapprochement 3 voies** (commande / réception / facture) : quantités et prix comparés, écart
  signalé (US-SINV-03, RG-SINV-03).
- **Cycle de vie** : brouillon → à payer (comptabilisée) → payée partiellement/payée, ou litige
  (US-SINV-04, RG-SINV-04/05).
- **Comptabilisation** à la validation, via le service `DirectLedgerEntryBuilder`
  (`spec-comptabilite-generale.md` §4.5) et `ExpenseAccountMapping` (US-SINV-05, RG-SINV-06).
- **Règlement** (partiel ou total), lettré avec l'écriture générée via le lettrage groupé
  (US-SINV-06, RG-SINV-07).
- **Litige** : ouverture/clôture, motif obligatoire, gel du règlement tant qu'ouvert (US-SINV-07,
  RG-SINV-08).
- **Avoir fournisseur** (facture d'avoir reçue du fournisseur, correction d'une facture déjà
  comptabilisée) comme seule voie de correction (US-SINV-08, RG-SINV-09).
- Émission `supplier_invoice.recorded`, `supplier_invoice.approved`, `supplier_invoice.paid`,
  `supplier_invoice.disputed` (US-SINV-09).

### Exclu (pour l'instant)
- La **définition du fournisseur** (coordonnées, catalogue, conditions) → `App\Stock` (réutilisé,
  §2.1/`spec-stock.md`), non redéfinie ici.
- La **commande d'achat et la réception physique** → `App\Stock` (`CommandeAchat`/`ReceptionAchat`,
  réutilisées, non redéfinies) ; cette brique **consomme** ces objets en lecture pour le rapprochement.
- Le **moteur d'écritures, le plan de comptes, le lettrage, l'export FEC, la clôture** →
  `App\Compta`/`spec-comptabilite-generale.md`, réutilisés à l'identique.
- La **remise bancaire réelle** (virement fournisseur, prélèvement par le fournisseur) → hors
  périmètre ; le règlement enregistré ici est un **fait déclaratif** (le virement a été émis/reçu),
  pas l'émission technique d'un virement SEPA. Un futur lot pourrait relier un règlement à une remise
  virement SEPA sortante (`App\Sepa`, non couvert par le module aujourd'hui, qui ne porte que le
  prélèvement entrant) — signalé en point ouvert (§8, Treasury).
- **L'OCR lui-même** (extraction) → `App\Ocr` (service partagé, `spec-ocr.md`), consommé en option.

## 3. Acteurs & droits

Permissions réutilisent le module `finance` (`spec-finance-suite.md` §5).

| Acteur | Peut | Ne peut pas | Permission |
|---|---|---|---|
| **Accounts payable clerk** (comptable fournisseurs) | Créer/éditer une facture en brouillon, la rattacher à une commande/réception, enregistrer un règlement | Valider (passer « à payer », engager la dépense) | `finance.read`, `finance.supplier_invoice_create` |
| **Responsable financier / Comptable** | Tout ce qui précède + **valider** (comptabiliser), **régler**, ouvrir/clore un **litige** | Modifier une facture déjà validée (correction par avoir uniquement) | `finance.read`, `finance.supplier_invoice_create`, `finance.supplier_invoice_approve`, `finance.supplier_invoice_pay`, `finance.supplier_invoice_dispute` |
| **Administrateur** | Paramétrer `ExpenseAccountMapping` (réutilise `compta.gerer`), configurer le seuil d'écart de rapprochement | — | `finance.manage`, `compta.gerer` (réutilisée) |
| **Magasinier** *(réutilise `stock`, `spec-stock.md` §3)* | Consulter la réception d'origine pour éclairer un écart | Saisir/valider une facture fournisseur | `stock.lire` (hérité, hors module `finance`) |

⚠ HYPOTHÈSE — noms de permissions proposés par analogie, à arbitrer avec M8 (comme tous les modules
déjà livrés).

## 4. Comportements & règles

### 4.1 Fournisseur réutilisé, aucun doublon (US-SINV-01)
- **RG-SINV-01** — Tout `SupplierInvoice` référence un **`App\Stock\Entity\Fournisseur`** existant
  (`supplier`, FK) — jamais de saisie fournisseur dupliquée dans cette brique. Un fournisseur inactif
  (`Fournisseur.actif = false`) ne peut plus recevoir de **nouvelle** facture, mais les factures
  historiques restent consultables/payables.

### 4.2 Saisie, assistée ou manuelle (US-SINV-02, RG-SINV-02)
- **RG-SINV-02** — Une facture fournisseur peut être créée :
  - **manuellement** — saisie ligne à ligne par l'Accounts payable clerk ;
  - **assistée par OCR** — un document (image/PDF) est soumis à `App\Ocr\DocumentExtractor` (§8) ; les
    champs extraits (`supplierName`, `amountExclTax`, `amountInclTax`, `vatAmount`, `invoiceDate`,
    `supplierInvoiceNumber`) **pré-remplissent** le brouillon, **jamais** ne le valident
    automatiquement — l'utilisateur **revoit et confirme** chaque champ avant tout passage en « à
    payer » (cohérent avec le principe « aucune dérogation automatique », décision actée M3 déjà
    reprise par `spec-autorisation.md` §0).
  - Le document source (scan/PDF) est **conservé en pièce jointe**, quelle que soit la voie de saisie.

### 4.3 Rapprochement 3 voies (US-SINV-03, RG-SINV-03)
- **RG-SINV-03** — Quand une facture référence une `purchaseOrder` (`CommandeAchat`) et/ou un
  `goodsReceipt` (`ReceptionAchat`), le système calcule un **écart de rapprochement** (non persisté,
  valeur calculée `PurchaseReconciliationGap`) :
  - quantité facturée vs quantité reçue (par ligne, quand `stockArticleRef` est renseigné) ;
  - prix unitaire facturé vs prix unitaire de réception (`LigneReceptionAchat.prixAchatUnitaireHT`).
  - Un écart **au-delà d'un seuil paramétrable** (`toleranceThreshold`, `%` ou montant, par profil
    exploitant) est **signalé** (badge « écart de rapprochement ») mais **ne bloque pas** la validation
    — ⚠ HYPOTHÈSE : blocage strict non retenu par défaut (le fournisseur peut légitimement avoir changé
    son prix), à confirmer avec le métier si un blocage dur est souhaité au-delà d'un seuil sévère.
  - Une facture **sans** commande/réception rattachée (achat ponctuel, prestation de service) est
    **valide sans rapprochement** — aucun écart calculé.

### 4.4 Cycle de vie (US-SINV-04, RG-SINV-04/05)
- **RG-SINV-04** — Statuts : `draft → to_pay → (partially_paid) → paid`, ou `disputed` (depuis
  `to_pay`/`partially_paid`), ou `cancelled` (depuis `draft` uniquement, avant toute comptabilisation).
- **RG-SINV-05** — La transition `draft → to_pay` (**validation**, `finance.supplier_invoice_approve`)
  est le **fait générateur comptable** (§4.5) : elle **scelle** la facture (plus aucune ligne
  modifiable), génère l'écriture, **ne peut se produire qu'une seule fois** (idempotence, même patron
  que `RG-FACT-03`/`EmettreFactureDirecteHandler` — verrou pessimiste, garde revérifiée après verrou).

### 4.5 Comptabilisation — via l'extension Compta (US-SINV-05, RG-SINV-06)
- **RG-SINV-06** — À la validation, `DirectLedgerEntryBuilder` (`spec-comptabilite-generale.md` §4.5)
  génère une écriture équilibrée : **crédit compte fournisseur (401)** du montant TTC, **débit
  compte(s) de charge** (résolus par `ExpenseAccountMapping` par ligne, HT) + **débit compte de TVA
  déductible** (par taux). La ligne « compte fournisseur » porte `counterpartyType =
  'stock_fournisseur'`, `counterpartyId` = id du `Fournisseur`, `counterpartyLabel` = raison sociale
  (compte auxiliaire, RG-M6-13).
  - Un **mapping de charge incomplet** (§4.2 de `spec-comptabilite-generale.md`) **bloque** la
    validation (comportement identique à `RG-M6-01`/CA-2 côté ventes) : la facture reste `draft`,
    signalée « mapping de charge incomplet », jamais silencieusement acceptée sans écriture.
  - Émission `supplier_invoice.approved` après succès.

### 4.6 Règlement (US-SINV-06, RG-SINV-07)
- **RG-SINV-07** — Un `SupplierPayment` enregistre un règlement (date, montant, `paymentMethod` —
  réutilise `App\Compta\Entity\MoyenPaiement` existant, référence). Il est **lettré** avec la ligne
  « compte fournisseur » de l'écriture générée, via `LettrageHandler::lettrerGroupe()`
  (`spec-comptabilite-generale.md` §4.4) : la somme des règlements lettrés doit égaler le montant de la
  ligne pour que le statut passe à `paid` ; un règlement **partiel** passe le statut à
  `partially_paid`, le solde restant dû est recalculé.
  - ⚠ HYPOTHÈSE — **Validation graduée des règlements au-delà d'un plafond** : cette brique **ne câble
    pas** par défaut `App\Autorisation` sur `finance.supplier_invoice_pay`, contrairement aux Expense
    reports (§4.3 de `spec-expense-reports.md`) — le catalogue `OperationSensible` **peut** référencer
    cette opération sans qu'elle soit active (même patron que `spec-autorisation.md` §4.1, « catalogue
    déclaré mais non câblé »), à activer si le métier le confirme (`spec-finance-suite.md` §9, point 5).

### 4.7 Litige (US-SINV-07, RG-SINV-08)
- **RG-SINV-08** — Depuis `to_pay`/`partially_paid`, un utilisateur habilité (`finance.
  supplier_invoice_dispute`) peut passer la facture en `disputed`, avec un **motif obligatoire**
  (`disputeReason`). Tant qu'une facture est `disputed` :
  - **aucun nouveau règlement** ne peut y être rattaché (gel, cohérent avec la logique « pas de
    dérogation automatique ») ;
  - l'écriture déjà générée (le cas échéant) **n'est pas extournée automatiquement** — le litige est un
    état **documentaire/opérationnel** distinct de la comptabilisation, qui reste correcte tant que la
    facture n'est pas **corrigée par avoir** (§4.8).
  - La **clôture** du litige (retour à `to_pay`/`partially_paid`, ou passage à un avoir) est manuelle,
    tracée (motif de résolution).
  - Émission `supplier_invoice.disputed` à l'ouverture.

### 4.8 Avoir fournisseur — seule voie de correction (US-SINV-08, RG-SINV-09)
- **RG-SINV-09** — Une facture **validée** (donc scellée/comptabilisée) ne peut plus être modifiée ni
  supprimée : toute correction (erreur de saisie découverte après validation, avoir reçu du
  fournisseur) passe par un **avoir fournisseur**, qui référence la facture d'origine et génère une
  **écriture d'extourne symétrique** (même mécanisme que `RG-FACT-05`, `spec-facturation.md` §4.6,
  réutilisé par analogie côté achats). Un avoir peut être **total** ou **partiel**.

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **`SupplierInvoice`** | id | uuid | PK | RG-SINV-01 |
| | establishment | ref Etablissement (socle) | requis | cloisonnement `RG-SOCLE-01/05` |
| | businessProfile | ref ProfilExploitant (Compta, français, réutilisé) | requis | porte le journal/la période |
| | supplier | ref Fournisseur (Stock, français, réutilisé) | requis | RG-SINV-01 |
| | supplierInvoiceNumber | string | requis | n° facture **du fournisseur** (texte libre, pas une séquence interne) |
| | invoiceDate, dueDate | date | requis | — |
| | status | enum {draft, to_pay, partially_paid, paid, disputed, cancelled} | défaut = draft | RG-SINV-04 |
| | purchaseOrder | ref CommandeAchat (Stock)? | optionnel | RG-SINV-03 |
| | goodsReceipt | ref ReceptionAchat (Stock)? | optionnel | RG-SINV-03 |
| | amountExclTax, amountInclTax | decimal | requis | Σ lignes |
| | vatBreakdown[] | (rate, taxBase, vatAmount)[] | requis, ≥ 1 par taux | ventilé, aucun taux moyen (cohérent RG-M6-05) |
| | attachment | fichier? | optionnel | scan/PDF source |
| | ocrExtraction | ref ExtractedDocument (Ocr)? | optionnel | traçabilité de l'assistance OCR |
| | ledgerEntry | ref EcritureComptable (Compta, français)? | requis dès `to_pay` | RG-SINV-06 |
| | disputeReason | string? | requis si `disputed` | RG-SINV-08 |
| | correctedBy | ref SupplierInvoice? (avoir) | requis si nature = avoir | RG-SINV-09 |
| | createdAt, createdBy | datetime, ref Utilisateur | requis | RG-SOCLE-07 |
| **`SupplierInvoiceLine`** | id, supplierInvoice | uuid, ref | PK | — |
| | description | string | requis | — |
| | stockArticle | ref ArticleStock (Stock)? | optionnel | pour rapprochement quantité (§4.3) |
| | quantity | decimal > 0 | requis | — |
| | unitPriceExclTax | decimal | requis | — |
| | vatRate | ref TauxTva (Compta, français) | requis | RG-M6-05 réutilisée |
| | expenseNatureCode | string | requis | clé résolue via `ExpenseAccountMapping` |
| | amountExclTax, vatAmount, amountInclTax | decimal | requis | — |
| **`SupplierPayment`** | id, supplierInvoice | uuid, ref | PK | RG-SINV-07 |
| | date | date | requis | — |
| | amount | decimal > 0 | requis | ≤ solde restant dû |
| | paymentMethod | ref MoyenPaiement (Compta, français) | requis | — |
| | reference | string? | optionnel | n° virement/chèque |
| | reconciliationCode | string? | dérivé du lettrage groupé | RG-M6-14 |
| | createdAt, createdBy | datetime, ref Utilisateur | requis | — |
| *(non persisté)* `PurchaseReconciliationGap` | quantityGap, priceGap | decimal | calculé | §4.3, pour affichage/alerte uniquement |

## 6. Critères d'acceptation

- **CA-1 (US-SINV-01, RG-SINV-01)** — *Étant donné* un fournisseur existant dans `App\Stock`, *quand*
  une facture lui est rattachée, *alors* aucune nouvelle fiche fournisseur n'est créée ; *étant donné*
  un fournisseur **inactif**, *quand* on tente de lui créer une **nouvelle** facture, *alors* c'est
  **refusé**.
- **CA-2 (US-SINV-02, RG-SINV-02)** — *Étant donné* un document soumis à l'OCR, *quand* l'extraction
  réussit, *alors* les champs du brouillon sont **pré-remplis** mais **restent modifiables** et
  **aucune validation automatique** n'a lieu ; *quand* l'OCR échoue ou n'est pas configuré, *alors* la
  saisie **manuelle reste pleinement fonctionnelle** (mode dégradé).
- **CA-3 (US-SINV-03, RG-SINV-03)** — *Étant donné* une facture rattachée à une réception de 100
  unités à 4,00 € et facturant 100 unités à 4,50 €, *quand* le rapprochement est calculé, *alors* un
  **écart de prix** est signalé, sans bloquer la validation.
- **CA-4 (US-SINV-04/05, RG-SINV-05)** — *Étant donné* une facture `draft` complète, *quand* elle est
  **validée**, *alors* elle passe à `to_pay`, une écriture équilibrée est générée et scellée, et une
  **seconde tentative de validation** est **rejetée** (idempotence).
- **CA-5 (US-SINV-05, RG-SINV-06)** — *Étant donné* une ligne dont la nature de charge n'a **aucun**
  `ExpenseAccountMapping` actif, *quand* la validation est tentée, *alors* elle est **bloquée**,
  signalée, la facture reste `draft`.
- **CA-6 (US-SINV-06, RG-SINV-07)** — *Étant donné* une facture `to_pay` de 1 000 €, *quand* un
  règlement de 1 000 € est enregistré, *alors* le statut passe à `paid` et la ligne fournisseur de
  l'écriture est **lettrée** ; *quand* un règlement de 400 € est enregistré, *alors* le statut passe à
  `partially_paid` et le solde dû est **600 €**.
- **CA-7 (US-SINV-07, RG-SINV-08)** — *Étant donné* une facture `to_pay`, *quand* elle est passée en
  **litige** sans motif, *alors* c'est **refusé** ; *quand* un motif est fourni, *alors* elle passe à
  `disputed` et **aucun règlement** ne peut y être ajouté tant qu'elle le reste.
- **CA-8 (US-SINV-08, RG-SINV-09)** — *Étant donné* une facture `paid` comportant une erreur découverte
  a posteriori, *quand* un avoir est créé, *alors* il référence la facture d'origine, **aucune ligne
  d'origine n'est modifiée**, et une **écriture d'extourne symétrique** est générée.
- **CA-9 (US-SINV-09)** — *Étant donné* une facture nouvellement enregistrée, *alors* l'événement
  `supplier_invoice.recorded` est émis avec `supplier`, `amount`, `source` (`ocr`/`manual`) dans le
  payload.

## 7. Cas limites
- **Facture sans commande ni réception** (prestation de service, achat ponctuel) — Valide, aucun
  rapprochement calculé (§4.3).
- **Sur-réception ou sous-réception déjà signalée côté Stock** (`spec-stock.md` §8, sur-réception
  acceptée par défaut) — le rapprochement de cette brique **hérite** cette tolérance, pas de double
  contrôle contradictoire.
- **Facture fournisseur en devise étrangère** — ⚠ HYPOTHÈSE : hors périmètre v1, tous les montants sont
  supposés en euros, cohérent avec l'absence de gestion multi-devises ailleurs dans le dépôt.
- **Deux factures fournisseur portant le même `supplierInvoiceNumber`** (mais fournisseurs
  différents) — Autorisé : le numéro de facture fournisseur n'est **pas** une séquence interne
  garantie unique globalement, seulement un texte de référence ; ⚠ HYPOTHÈSE — une unicité **par
  fournisseur** pourrait être ajoutée pour détecter les doublons de saisie, non retenue v1.
- **Règlement d'un montant supérieur au solde restant dû** — Rejeté (422), cohérent avec l'intégrité du
  lettrage groupé (§4.6).
- **Litige ouvert sur une facture déjà entièrement payée** — ⚠ HYPOTHÈSE : possible (ex. produit
  défectueux découvert après paiement), débouche nécessairement sur un **avoir** plutôt que sur un gel
  de règlement (déjà soldé) — à confirmer que le statut `disputed` reste pertinent post-paiement ou si
  un statut distinct serait préférable.
- **Utilisateur sans affectation sur l'établissement de la facture** — Aucun accès (hérité du socle,
  `RG-SOCLE-05`).

## 8. Dépendances
- **Dépend de : socle L0** — hiérarchie, permissions `module × action` sur `finance`, cloisonnement,
  audit append-only.
- **Dépend de : General ledger extension (FIN-1)** — `DirectLedgerEntryBuilder`,
  `ExpenseAccountMapping`, compte auxiliaire, lettrage groupé (`spec-comptabilite-generale.md`).
- **Dépend de : `App\Stock`** — `Fournisseur`, `CommandeAchat`/`LigneCommandeAchat`,
  `ReceptionAchat`/`LigneReceptionAchat`, `ArticleStock` (`spec-stock.md`), réutilisés en lecture.
- **Dépend de : `App\Compta`** — `MoyenPaiement`, `TauxTva`, `ProfilExploitant` (référencés, français,
  inchangés).
- **Dépend de (optionnel) : `App\Ocr`** — `DocumentExtractor` (`spec-ocr.md`), mode dégradé toujours
  disponible.
- **Interagit avec (sans dépendance stricte) : Treasury (FIN-4)** — consomme les factures `to_pay` pour
  l'échéancier fournisseurs (`spec-treasury.md` §4.5) ; **Autorisation** (câblage optionnel non retenu
  v1, §4.6).

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ HORS BACKLOG** — module entièrement nouveau (en-tête).
2. Blocage strict au-delà d'un seuil d'écart de rapprochement sévère — non retenu v1 (§4.3).
3. Validation graduée des règlements fournisseur (`App\Autorisation`) — non câblée v1 (§4.6).
4. Devises étrangères — hors périmètre v1 (§7).
5. Unicité du `supplierInvoiceNumber` par fournisseur — non retenue v1, risque de doublon de saisie
   (§7).
6. Statut `disputed` sur une facture déjà `paid` — comportement à confirmer (§7).
7. Lien règlement ↔ remise virement SEPA sortante — non couvert, `App\Sepa` actuel ne porte que le
   prélèvement entrant (§2).

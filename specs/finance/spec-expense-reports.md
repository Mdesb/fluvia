# Spec — Expense reports / Notes de frais (`App\Finance\ExpenseReport`, lot `FIN-3`)

- **Lot / module :** module **nouveau**, `finance` (capacité) / feature `expense_reports` — voir
  `spec-finance-suite.md`. Convention de nommage : identifiants techniques en anglais, prose en
  français (`spec-finance-suite.md` §0).
- **Stories couvertes :** **US-EXP-01 à US-EXP-09** — ⚠ **HORS BACKLOG**, comme `App\Personnel`
  (`spec-personnel.md`, même statut) : aucune source ne couvre les notes de frais. À faire
  valider/chiffrer avant développement.
- **Règles de gestion :** **RG-EXP-01 à RG-EXP-11** (nouvelles). Règles **réutilisées, non
  redéfinies** : `RG-SOCLE-01` à `07`, `RG-PERSO-01` (`Employe`, `spec-personnel.md`), `RG-AUTZ-01` à
  `13` (`spec-autorisation.md`), `RG-M6-12/13/14` (`spec-comptabilite-generale.md`).
- **Statut :** brouillon.

## 1. Objectif

Permettre à un salarié de **soumettre une note de frais** (une ou plusieurs dépenses justifiées) avec
son justificatif, la faire **valider selon un workflow gradué par montant** (réutilisant
`App\Autorisation`, pas un second moteur de workflow), déclencher son **remboursement**, et la
**déverser en comptabilité** — sans jamais dupliquer le référentiel salarié, le moteur de validation
graduée, ni le moteur d'écritures déjà construits.

## 2. Périmètre

### Inclus
- **Soumission** d'une note de frais par un salarié (`App\Personnel\Entity\Employe`, réutilisé), une ou
  plusieurs lignes de dépense, justificatif par ligne (US-EXP-01/02, RG-EXP-01/02).
- **Assistance OCR** optionnelle par ligne (montant TTC/TVA, date, fournisseur extraits du
  justificatif) — mode dégradé manuel toujours disponible (US-EXP-03, RG-EXP-03).
- **Workflow de validation gradué**, entièrement délégué à `App\Autorisation`
  (`OperationSensible = finance.expense_report_approve`), plafonds par rôle/montant, escalade
  superviseur (US-EXP-04, RG-EXP-04).
- **Remboursement** (déclaratif, comme le règlement fournisseur de FIN-2) (US-EXP-05, RG-EXP-05).
- **Déversement comptable** à l'approbation, via `DirectLedgerEntryBuilder` +
  `ExpenseAccountMapping` (US-EXP-06, RG-EXP-06).
- **Refus** motivé, avec possibilité de re-soumission corrigée (US-EXP-07, RG-EXP-07).
- Consultation par le salarié de **ses propres** notes (US-EXP-08).
- Émission `expense_report.submitted`, `expense_report.approved`, `expense_report.reimbursed`
  (US-EXP-09).

### Exclu (pour l'instant)
- **La fiche employé, le contrat, le rattachement établissement** → `App\Personnel` (réutilisé,
  `spec-personnel.md`), non redéfini.
- **Le calcul du solde de congés, la paie, l'intégration au bulletin de salaire** → hors périmètre
  logiciel de billetterie, comme déjà exclu par `spec-personnel.md` §2 (SIRH externe). Un
  remboursement de note de frais **n'est pas un élément de paie** ; il est traité comme une **dette
  envers le salarié** (compte 421, symétrique du compte 401 fournisseur), réglée hors paie (ex.
  virement dédié) — cohérent avec la pratique comptable standard.
- **Le moteur de validation graduée lui-même** (plafonds, escalade, séparation des tâches) →
  `App\Autorisation` (réutilisé à l'identique, `spec-autorisation.md`), non redéfini. Cette brique
  **déclare** une nouvelle `OperationSensible`, elle ne réimplémente rien.
- **L'OCR lui-même** → `App\Ocr` (service partagé, `spec-ocr.md`).
- **Le remboursement bancaire réel** (virement effectif) → hors périmètre, comme pour `SupplierPayment`
  (FIN-2, §2) ; Treasury (FIN-4) **consomme** ces remboursements en lecture pour l'échéancier.

## 3. Acteurs & droits

| Acteur | Peut | Ne peut pas | Permission |
|---|---|---|---|
| **Salarié (soi-même)** *(réutilise `Employe`, `spec-personnel.md`)* | Soumettre/éditer sa propre note de frais tant qu'elle est `draft`, la re-soumettre après refus, consulter ses propres notes | Valider/refuser sa propre note (auto-approbation interdite, RG-AUTZ-13 réutilisée), consulter les notes d'un autre salarié | `finance.expense_report_submit`, `finance.expense_report_read_own` |
| **Superviseur / Responsable financier** *(réutilise `autorisation.approuver`)* | Approuver/rejeter une note dans les limites de son habilitation, dans son établissement | Approuver sa propre note | `autorisation.approuver` (réutilisée, `spec-autorisation.md` §3) |
| **Accounts payable clerk / Comptable** | Enregistrer un remboursement, déverser une note approuvée en comptabilité | Approuver une note | `finance.expense_report_post_to_ledger` |
| **Administrateur** | Paramétrer `ExpenseAccountMapping`, les `LimiteAutorisation` de l'opération `finance.expense_report_approve` (réutilise `autorisation.gerer`) | — | `finance.manage`, `autorisation.gerer` (réutilisée) |

⚠ HYPOTHÈSE — noms de permissions proposés par analogie, à arbitrer avec M8.

## 4. Comportements & règles

### 4.1 Soumission (US-EXP-01/02, RG-EXP-01/02)
- **RG-EXP-01** — Une `ExpenseReport` porte un `employee` (ref `Employe`, `App\Personnel`, réutilisé),
  un `establishment`, un statut, et une ou plusieurs `ExpenseLine`. Créée en `draft`, éditable
  librement par le salarié tant qu'elle n'est pas soumise.
- **RG-EXP-02** — Chaque `ExpenseLine` porte une **catégorie de dépense** (`expenseNatureCode`, même
  référentiel ouvert que `ExpenseAccountMapping.expenseNatureCode`, §4.6 de
  `spec-comptabilite-generale.md`), un montant TTC, une date, et un **justificatif obligatoire**
  (`receipt`, fichier) — **aucune ligne sans justificatif** n'est soumissible (RG-EXP-02.1). Une
  ligne **sans catégorie mappée** reste **soumissible** (le blocage n'intervient qu'au déversement
  comptable, §4.6, pas à la soumission — pour ne jamais empêcher un salarié de soumettre à temps).
- **RG-EXP-01.1 (soumission = transition, pas suppression du brouillon)** — La transition
  `draft → submitted` fige les lignes (plus d'ajout/suppression de ligne), émet
  `expense_report.submitted`, et **déclenche l'évaluation `App\Autorisation`** (§4.3).

### 4.2 Assistance OCR (US-EXP-03, RG-EXP-03)
- **RG-EXP-03** — Chaque `ExpenseLine` peut être **pré-remplie** depuis son justificatif via
  `App\Ocr\DocumentExtractor` (montant, TVA, date, fournisseur) — **jamais** de soumission
  automatique ; le salarié **revoit et confirme** avant soumission, cohérent avec `RG-SINV-02` (FIN-2,
  même principe).

### 4.3 Workflow de validation gradué — délégation totale à `App\Autorisation` (US-EXP-04, RG-EXP-04)
- **RG-EXP-04** — La soumission d'une `ExpenseReport` invoque
  `ServiceAutorisation::evaluer(operation: 'finance.expense_report_approve', cible: expenseReport,
  montant: totalAmount, auteur: employee.utilisateur)` (`App\Autorisation`, réutilisé à l'identique,
  `spec-autorisation.md` §4.3) :
  - **AUTORISÉ** (aucune `LimiteAutorisation` applicable, ou montant ≤ plafond du rôle du salarié) →
    la note passe directement à `approved`, déversement comptable déclenché (§4.6).
  - **ESCALADE_REQUISE** (montant > plafond, escalade permise) → la note reste `submitted`, une
    `DemandeEscalade` (`App\Autorisation`) est créée ; une fois **approuvée** par un superviseur
    habilité, la note passe à `approved` ; si **rejetée**, elle passe à `rejected` avec le motif porté
    par la `DemandeEscalade`.
  - **REFUSÉ** (pas de droit binaire, ou dépassement sans escalade permise) → la note est **rejetée
    immédiatement**, motif explicite.
  - ⚠ HYPOTHÈSE — l'employé **soumettant** doit disposer d'un compte `Utilisateur` (socle) pour que
    `ServiceAutorisation` puisse l'évaluer comme `auteur` — cohérent avec `spec-personnel.md` §3
    (« seuls les employés qui opèrent le logiciel ont un `utilisateurRef` renseigné ») : **un salarié
    sans compte Utilisateur ne peut pas soumettre lui-même** une note de frais dans cette version (elle
    devrait être saisie **pour lui** par un tiers habilité) — point ouvert (§7).

### 4.4 Remboursement (US-EXP-05, RG-EXP-05)
- **RG-EXP-05** — Une note `approved` reçoit un **remboursement** déclaratif (date, montant, moyen),
  lettré avec la ligne « compte salarié » (421) de l'écriture générée (§4.6), même patron que
  `SupplierPayment` (FIN-2, §4.6). Statut → `reimbursed` quand le solde atteint zéro ; un
  remboursement **partiel** n'est **pas retenu v1** (⚠ HYPOTHÈSE — une note de frais est réputée
  remboursée **en une fois**, contrairement à une facture fournisseur qui peut s'étaler ; à confirmer
  si des remboursements échelonnés sont un besoin réel).

### 4.5 Refus & re-soumission (US-EXP-07, RG-EXP-07)
- **RG-EXP-07** — Une note `rejected` (refus direct ou escalade rejetée) peut être **corrigée et
  re-soumise** par le salarié (retour à `draft`, puis nouveau cycle complet §4.1-4.3) — cohérent avec
  `spec-autorisation.md` §4.6 (« une demande rejetée ne peut plus être rejouée : une **nouvelle**
  demande devra être créée »).

### 4.6 Déversement comptable (US-EXP-06, RG-EXP-06)
- **RG-EXP-06** — À l'approbation (directe ou après escalade), `DirectLedgerEntryBuilder`
  (`spec-comptabilite-generale.md` §4.5) génère une écriture équilibrée : **crédit compte salarié
  (421)** du montant total TTC, **débit compte(s) de charge** (résolus par `ExpenseAccountMapping` par
  ligne, HT) + **débit compte de TVA déductible** (par taux, quand la dépense y ouvre droit — ⚠
  HYPOTHÈSE : certaines catégories, ex. repas au forfait, peuvent ne pas ouvrir droit à déduction ; le
  `ExpenseAccountMapping` porte un `deductibleVatRate` qui peut référencer le taux **« Hors champ »**
  déjà seedé pour ces cas, §4.1 de `spec-comptabilite-generale.md`). La ligne « compte salarié » porte
  `counterpartyType = 'personnel_employe'`, `counterpartyId` = id de l'`Employe`, `counterpartyLabel` =
  nom/prénom (compte auxiliaire, RG-M6-13).
  - Un **mapping de charge incomplet** sur une ligne **bloque le déversement** (pas l'approbation
    elle-même, qui a déjà eu lieu via `App\Autorisation`) : la note reste `approved`, statut
    intermédiaire « en attente de déversement », signalée au Comptable — ⚠ HYPOTHÈSE : dissociation
    volontaire entre l'**approbation métier** (le manager dit « oui, dépense légitime ») et le
    **déversement comptable** (le comptable dit « le compte de charge existe ») ; à confirmer que
    cette dissociation est acceptable (le salarié voit sa note « approuvée » avant remboursement
    effectif possible).

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **`ExpenseReport`** | id | uuid | PK | RG-EXP-01 |
| | establishment | ref Etablissement (socle) | requis | cloisonnement |
| | businessProfile | ref ProfilExploitant (Compta, français) | requis | — |
| | employee | ref Employe (Personnel, français, réutilisé) | requis | RG-EXP-01 |
| | status | enum {draft, submitted, approved, rejected, reimbursed} | défaut = draft | RG-EXP-01/04/05/07 |
| | totalAmount | decimal | dérivé | Σ lignes |
| | escalationRequest | ref DemandeEscalade (Autorisation, français)? | requis si escalade | RG-EXP-04 |
| | rejectionReason | string? | requis si rejected (hors escalade, motif de `DemandeEscalade` sinon) | RG-EXP-07 |
| | ledgerEntry | ref EcritureComptable (Compta, français)? | requis dès déversement réussi | RG-EXP-06 |
| | submittedAt, approvedAt, reimbursedAt | datetime? | — | horodatage du cycle de vie |
| | createdAt, createdBy | datetime, ref Utilisateur | requis | RG-SOCLE-07 |
| **`ExpenseLine`** | id, expenseReport | uuid, ref | PK | — |
| | expenseNatureCode | string | requis | RG-EXP-02 |
| | expenseDate | date | requis | — |
| | amountInclTax | decimal > 0 | requis | — |
| | vatRate | ref TauxTva (Compta, français)? | optionnel | requis seulement si TVA récupérable identifiable |
| | receipt | fichier | **requis** | RG-EXP-02.1, aucune ligne sans justificatif |
| | ocrExtraction | ref ExtractedDocument (Ocr)? | optionnel | RG-EXP-03 |
| | description | string? | optionnel | — |

## 6. Critères d'acceptation

- **CA-1 (US-EXP-01/02, RG-EXP-02)** — *Étant donné* une ligne de dépense sans justificatif joint,
  *quand* le salarié tente de **soumettre** la note, *alors* c'est **refusé**, avec un message explicite
  identifiant la ligne fautive.
- **CA-2 (US-EXP-04, RG-EXP-04, cas sous plafond)** — *Étant donné* une note de 40 € et une
  `LimiteAutorisation` (opération `finance.expense_report_approve`, plafond 100 €) pour le rôle du
  salarié, *quand* elle est soumise, *alors* elle passe **directement** à `approved` sans escalade,
  et le déversement comptable est déclenché.
- **CA-3 (US-EXP-04, cas escalade)** — *Étant donné* la même limite, *quand* une note de 250 € est
  soumise, *alors* une `DemandeEscalade` est créée (statut `en_attente`), la note reste `submitted` ;
  *quand* un superviseur **approuve**, *alors* la note passe à `approved` et le déversement est
  déclenché ; *quand* il **rejette**, *alors* la note passe à `rejected` avec le motif.
- **CA-4 (US-EXP-05, RG-EXP-05)** — *Étant donné* une note `approved` de 300 €, *quand* un
  remboursement de 300 € est enregistré, *alors* le statut passe à `reimbursed` et la ligne « compte
  salarié » de l'écriture est lettrée.
- **CA-5 (US-EXP-06, RG-EXP-06)** — *Étant donné* une ligne dont la nature de charge n'a **aucun**
  `ExpenseAccountMapping` actif, *quand* le déversement comptable est tenté après approbation,
  *alors* il est **bloqué**, la note reste `approved` sans écriture, signalée au Comptable.
- **CA-6 (US-EXP-07, RG-EXP-07)** — *Étant donné* une note `rejected`, *quand* le salarié la corrige et
  la re-soumet, *alors* elle repasse par un **nouveau cycle complet** de validation (§4.3), sans
  hériter d'une approbation précédente.
- **CA-7 (US-EXP-08)** — *Étant donné* un salarié connecté, *quand* il consulte ses notes de frais,
  *alors* il voit **uniquement les siennes** ; un autre salarié ne peut pas y accéder
  (`finance.expense_report_read_own`, gardée par `EMPLOYE_SOI` — réutilise le voter
  `EmployeSoiVoter` déjà posé par `spec-personnel.md`).
- **CA-8 (US-EXP-09)** — *Étant donné* une note nouvellement soumise, *alors* l'événement
  `expense_report.submitted` est émis avec `employee`, `amount` dans le payload.

## 7. Cas limites
- **Auto-approbation** — Un superviseur ne peut pas approuver sa propre note (réutilise `RG-AUTZ-13`
  à l'identique) — refusé (403).
- **Salarié sans compte `Utilisateur`** — Ne peut pas soumettre lui-même une note (§4.3, point
  ouvert) ; ⚠ HYPOTHÈSE — une saisie **pour le compte d'un tiers** (ex. assistant RH saisissant pour un
  agent d'entretien sans accès logiciel) n'est **pas couverte** par cette version, à confirmer si le
  besoin existe (cas déjà rencontré par `spec-personnel.md` §3 pour les employés sans compte).
- **Remboursement partiel** — Non retenu v1 (§4.4) ; une note de frais se rembourse en une fois.
- **Note de frais multi-établissement** — ⚠ HYPOTHÈSE : une `ExpenseReport` est rattachée à **un seul**
  `establishment` (celui du rattachement actif de l'employé au moment de la soumission) ; un salarié
  multi-site (`RattachementEmploye`, `spec-personnel.md` §4.1) doit choisir explicitement
  l'établissement de rattachement de la note — non détaillé plus avant.
- **Mapping de charge complété après blocage du déversement** — Le déversement est **rejouable**
  (nouvelle tentative `finance.expense_report_post_to_ledger`), sans nouvelle approbation à redemander
  (l'approbation métier reste acquise, seul le déversement était bloqué, §4.6).
- **Délégation temporaire de droits active** — Un bénéficiaire de `DelegationDroit` (L7) hérite des
  `LimiteAutorisation` attachées au rôle délégué pendant la fenêtre active (réutilise `RG-AUTZ-12` à
  l'identique).
- **Utilisateur sans affectation sur l'établissement de la note** — Aucun accès (hérité du socle,
  `RG-SOCLE-05`).

## 8. Dépendances
- **Dépend de : socle L0** — hiérarchie, permissions, cloisonnement, audit append-only.
- **Dépend de : General ledger extension (FIN-1)** — `DirectLedgerEntryBuilder`,
  `ExpenseAccountMapping`, compte auxiliaire, lettrage groupé.
- **Dépend de : `App\Personnel`** — `Employe`, `RattachementEmploye`, `EmployeSoiVoter` réutilisés à
  l'identique (`spec-personnel.md`).
- **Dépend de : `App\Autorisation`** — `ServiceAutorisation`, `LimiteAutorisation`, `DemandeEscalade`,
  réutilisés à l'identique ; cette brique **ajoute une nouvelle** `OperationSensible` (`finance.
  expense_report_approve`) au catalogue existant, sans toucher au moteur (`spec-autorisation.md` §4.1).
- **Dépend de (optionnel) : `App\Ocr`** — `DocumentExtractor` (`spec-ocr.md`).
- **Interagit avec (sans dépendance stricte) : Treasury (FIN-4)** — consomme les remboursements pour
  l'échéancier.

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ HORS BACKLOG** — module entièrement nouveau (en-tête).
2. Salarié sans compte `Utilisateur` ne peut pas soumettre lui-même une note (§4.3, §7).
3. Remboursement partiel non retenu v1 (§4.4, §7).
4. Dissociation approbation métier / déversement comptable en cas de mapping incomplet — comportement
   à confirmer avec le métier (§4.6).
5. Note de frais multi-établissement — rattachement à un établissement unique par défaut (§7).

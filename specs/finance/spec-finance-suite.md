# Spec — Suite Finance & Compta (vue d'ensemble, manifeste de suite)

- **Lot / module :** Suite transverse **hors backlog actuel** — regroupe 5 briques métier + 1 service
  partagé, consommant/étendant l'existant `App\Facturation`, `App\Compta` (L4), `App\Sepa`,
  `App\Autorisation`, `App\Personnel`, `App\Stock`.
- **Stories couvertes :** aucune `US-Lx` existante. Chaque brique introduit ses propres stories
  (numérotation propre, détaillée dans chaque spec de brique), sur le modèle déjà appliqué par
  `spec-stock.md`, `spec-personnel.md`, `spec-autorisation.md` : **⚠ HORS BACKLOG — à faire valider et
  chiffrer avant développement.**
- **Règles de gestion :** chacune des briques introduit ses propres règles ; la brique « Comptabilité
  générale » **étend** la numérotation existante `RG-M6-*`/`US-L4-*` (`specs/L4-compta/spec-compta.md`)
  plutôt que d'en créer une nouvelle, conformément à la consigne « étendre, pas dupliquer ».
- **Statut :** brouillon — ce document est le **manifeste de suite** ; il ne redéfinit aucun
  comportement déjà détaillé dans les 5 fichiers de brique (`spec-supplier-invoices.md`,
  `spec-comptabilite-generale.md`, `spec-treasury.md`, `spec-expense-reports.md`, `spec-ocr.md`), il
  les **articule**.

## 0. Convention de nommage (correction actée)

**Tout identifiant technique introduit par cette suite est en anglais** : noms d'entités, de tables,
de colonnes/propriétés, valeurs d'énumération, champs d'API/DTO, codes de permission, noms
d'événements (ex. `SupplierInvoice`, `supplier_invoice`, `amount_incl_tax`, `finance.read`, événement
`supplier_invoice.recorded`). La **prose explicative** de chaque spec reste en français. Les
**libellés visibles utilisateur ne sont jamais en dur** : ce sont des **clés de traduction** (i18n,
français par défaut) — ex. `finance.supplier_invoice.status.disputed`, jamais la chaîne « En litige »
codée en dur.

- **Portée de la règle** — elle s'applique à **tout ce que cette suite introduit** : les 3 nouvelles
  briques (`App\Finance\*`), le service transverse (`App\Ocr`), et les **nouveaux** artefacts ajoutés
  par l'extension de `App\Compta` (brique 2 — nouvelle entité, nouveaux champs, nouvelle permission).
- **Ce qui n'est PAS renommé** — les entités **déjà livrées** et référencées par cette suite
  (`App\Compta\Entity\EcritureComptable`/`LigneEcriture`/`CompteComptable`/`Journal`/`TauxTva`/
  `ProfilExploitant`/`PeriodeComptable`/`LettrageEcriture`/`MoyenPaiement`, `App\Stock\Entity\
  Fournisseur`/`CommandeAchat`/`ReceptionAchat`, `App\Personnel\Entity\Employe`, `App\Sepa\Entity\*`,
  `App\Facturation\Entity\Facture`, `App\Autorisation\Entity\*`) **gardent leur nom français** :
  les renommer serait une opération de refactor massive, hors périmètre d'une extension additive, et
  romprait « étendre sans casser ». Cette suite les **référence tels quels** par leur nom existant.
  ⚠ **Point porté au client** : le dépôt contiendra donc, de façon assumée et documentée, un
  **vocabulaire mixte** (cœur historique en français, nouvelle suite Finance en anglais) tant qu'un
  renommage global (hors périmètre ici) n'est pas arbitré.
- **Catalogue d'événements** (`COORDINATION/CONTRACT/catalogue-evenements.md`) — ce fichier marquait
  la langue des noms d'événements comme **« ⚠ à trancher (D-ouverte) »** et proposait en v0 des noms
  français avec alias anglais. Cette correction **tranche cette question pour les événements introduits
  par cette suite** (anglais canonique) ; les **deux entrées déjà cataloguées** en français
  (`facture_fournisseur.enregistree`, `note_de_frais.soumise`) sont **remplacées** par leurs équivalents
  anglais (`supplier_invoice.recorded`, `expense_report.submitted`) — **à répercuter dans le catalogue
  partagé**, cf. §8. Les événements déjà émis par d'autres modules **déjà livrés** en français
  (`facture.emise`, `facture.payee`, `devis.expire`, `paiement.echoue`…) ne sont **pas renommés** par
  cette suite ; ils sont consommés tels quels (§6).

## 1. Objectif

Compléter le cœur Compta & Régie existant (facturation client, comptabilité générale, régie) par le
**volet dépenses de l'entreprise** : ce que l'établissement doit payer (factures fournisseurs, notes
de frais des salariés) et le pilotage de sa **trésorerie** (position, rapprochement bancaire,
échéancier), avec un **service d'extraction documentaire (OCR) partagé** qui accélère la saisie sans
jamais la rendre obligatoire (mode dégradé manuel systématique). Le tout **sans dupliquer** le moteur
d'écritures NF525, le plan de comptes, le lettrage, l'export FEC ni la clôture d'exercice déjà
construits par `App\Compta`.

## 2. Cartographie — 5 briques + 1 service transverse

| # | Brique | Namespace proposé | Statut | Spec |
|---|---|---|---|---|
| 1 | Supplier invoices (factures fournisseur) | `App\Finance\SupplierInvoice` | **nouveau** | `spec-supplier-invoices.md` |
| 2 | General ledger (comptabilité générale) | `App\Compta` (existant, **étendu**) | **extension** | `spec-comptabilite-generale.md` |
| 3 | Treasury (trésorerie) | `App\Finance\Treasury` | **nouveau** | `spec-treasury.md` |
| 4 | Expense reports (notes de frais) | `App\Finance\ExpenseReport` | **nouveau** | `spec-expense-reports.md` |
| 5 | Service transverse OCR | `App\Ocr` | **nouveau**, service partagé (pas un module métier) | `spec-ocr.md` |

Réutilisé **tel quel**, non redéfini par cette suite (noms français existants conservés, §0) :
- **`App\Compta`** (L4) : `ProfilExploitant`, `Journal`, `CompteComptable`, `TauxTva`,
  `EcritureComptable`/`LigneEcriture` + chaînage NF525 (`ScellementEcritureHandler`), `LettrageEcriture`/
  `LettrageHandler`, `PeriodeComptable`/`ClotureHandler`, `ExportComptable` + `ExportFecAdapter`
  (export FEC **déjà réel**, 18 champs).
- **`App\Facturation`** : `Facture` (client), `EmettreFactureDirecteHandler` (patron repris pour la
  génération d'écriture directe côté achats/notes de frais).
- **`App\Sepa`** : `ConfigCreancierSepa`, `MandatSepa`, `RemiseSepa`/`LigneRemiseSepa`, `RejetSepa`,
  `Pain008Generator` (prélèvements SEPA sortants côté créances) — la Treasury **consomme** ces objets
  en lecture pour l'échéancier, elle ne réémet aucune remise.
- **`App\Autorisation`** : `OperationSensible`, `LimiteAutorisation`, `ServiceAutorisation`,
  `DemandeEscalade` — porte le **workflow de validation graduée** des Expense reports (et, en option,
  des Supplier invoices au-delà d'un plafond), sans nouveau moteur de validation.
- **`App\Personnel`** : `Employe`, `RattachementEmploye` — porte l'identité du salarié qui soumet un
  expense report.
- **`App\Stock`** : `Fournisseur`, `CommandeAchat`/`LigneCommandeAchat`, `ReceptionAchat`/
  `LigneReceptionAchat` — porte le **fournisseur** (réutilisé tel quel, aucun second référentiel
  fournisseur créé) et le **rapprochement 3 voies** (commande / réception / facture).
- **Socle L0** : hiérarchie Groupe/Région/Établissement/Espace, permissions `module × action`,
  cloisonnement serveur, UUID, audit append-only — non redéfinis (`specs/L0-socle/spec-socle.md`).

## 3. Alignement avec le contrat de plateforme (`COORDINATION/CONTRACT/`)

- **Noyau commun** (`noyau-commun.md`) — la suite ne réimplémente ni l'Identité, ni les Permissions, ni
  l'Audit, ni le Bus d'événements ; elle les **consomme**. Le service OCR (brique 5) est candidat à
  rejoindre le tableau du noyau commun au même titre que « Communication » ou « Automation » — **⚠ point
  à faire remonter** au propriétaire de `noyau-commun.md` (non tranché ici, cf. `spec-ocr.md` §8).
- **Manifeste de module** (`manifeste-module.md`) — cette suite introduit **un seul module métier
  nouveau, `finance`**, qui porte 3 des 5 briques (Supplier invoices, Treasury, Expense reports) comme
  **features indépendamment activables** (`hasModule('finance') && hasFeature('treasury')`, double
  test SmartFlow déjà documenté). La 4ᵉ brique (General ledger) est une **extension du module `compta`
  existant** (nouvelles features dans son manifeste, pas de nouveau module). La 5ᵉ (OCR) est un
  **service technique partagé**, pas une capacité activable par tenant — il est **consommé** par les
  features `ocr_supplier_invoices`/`ocr_expense_reports` du module `finance`.
- **Catalogue d'événements** (`catalogue-evenements.md`) — voir §0 (tranchage de la langue) et §6 (liste
  complète, dont `supplier_invoice.recorded` et `expense_report.submitted` qui **remplacent** les deux
  entrées françaises déjà présentes en v0).

### 3.1 Manifeste — module `finance` (nouveau)

```php
final class FinanceModule implements ModuleManifest
{
    public function id(): string { return 'finance'; }
    public function version(): string { return '0.1.0'; }
    public function capacite(): string { return 'finance'; }
    public function dependencies(): array { return ['compta', 'stock', 'sepa', 'personnel', 'autorisation']; }
    public function permissions(): array { return [
        'finance.read',
        'finance.supplier_invoice_create', 'finance.supplier_invoice_approve',
        'finance.supplier_invoice_pay', 'finance.supplier_invoice_dispute',
        'finance.treasury_manage_account', 'finance.treasury_import_statement', 'finance.treasury_reconcile',
        'finance.expense_report_submit', 'finance.expense_report_read_own', 'finance.expense_report_post_to_ledger',
        'finance.manage',
    ]; }
    public function eventsEmitted(): array { return [
        'supplier_invoice.recorded', 'supplier_invoice.approved', 'supplier_invoice.paid',
        'supplier_invoice.disputed', 'expense_report.submitted', 'expense_report.approved',
        'expense_report.reimbursed', 'treasury.reconciliation_completed', 'treasury.discrepancy_detected',
    ]; }
    public function eventsConsumed(): array { return ['facture.emise', 'facture.payee', 'devis.expire']; }
    public function features(): array { return [
        'supplier_invoices', 'treasury', 'bank_reconciliation',
        'expense_reports', 'ocr_supplier_invoices', 'ocr_expense_reports',
    ]; }
}
```

⚠ HYPOTHÈSE — `dependencies()` liste `stock`/`sepa`/`personnel`/`autorisation` comme **dépendances
dures du module**, alors que certaines ne sont requises que par une **feature** particulière (ex.
`stock` seulement si `supplier_invoices` actif). Le registre de modules (à construire, tâche C5 du
contrat) devra arbitrer entre dépendance de **module** et dépendance de **feature** — non tranché par
ce contrat v0 lui-même (`manifeste-module.md` ne distingue pas les deux niveaux de dépendance) ; cette
suite retient par prudence une dépendance de module la plus large possible, à affiner à l'implémentation.

### 3.2 Manifeste — extension du module `compta` (existant)

Ajouts au manifeste (non encore formalisé en code, cf. `spec-comptabilite-generale.md`) :
`features` += `manual_journal_entry`, `counterparty_ledger`, `grouped_reconciliation` ;
`permissions` += `compta.record_manual_entry` *(seule nouvelle permission — en anglais malgré le
préfixe `compta` français préexistant, §0)* ; `events_emitted` inchangés (aucun nouvel événement
propre, la brique 2 est un **prérequis technique** consommé en interne par la brique 1 et la brique 4,
pas un émetteur de fait métier nouveau).

## 4. Lots livrables (indépendants, testables séparément)

Aucun big-bang : chaque lot est une **PR autonome**, avec ses propres tests, sans casser le
comportement observable des lots déjà livrés (Definition of Done, constitution §8).

| Lot | Contenu | Dépend de (lots déjà livrés) | Testable seul ? |
|---|---|---|---|
| **FIN-0** | Service OCR (brique 5) : interface `DocumentExtractor`, DTO `ExtractedDocument`, adaptateur `ManualExtractorAdapter` (mode dégradé, toujours disponible), adaptateur `AnthropicDocumentExtractorAdapter` (branchable) | Aucun (service autonome) | **Oui** — testable isolément avec des fixtures image/PDF, sans aucune autre brique |
| **FIN-1** | Extension `App\Compta` (brique 2) : écriture manuelle libre (`POST /compta/journal-entries/manual`), `ExpenseAccountMapping` (mapping charges), champs `counterparty*` sur `LigneEcriture` (compte auxiliaire), `reconciliationCode` groupé sur `LettrageEcriture`, export FEC alimenté du compte auxiliaire | `App\Compta` (L4, déjà livré) | **Oui** — n'introduit aucun nouveau module, extension pure du module existant, testable avec les fixtures Compta existantes |
| **FIN-2** | Supplier invoices (brique 1) : cycle de vie, rapprochement `CommandeAchat`/`ReceptionAchat`, comptabilisation via FIN-1, émission `supplier_invoice.recorded` | FIN-1 (écriture directe + mapping charge), `App\Stock` (déjà livré) | **Oui**, avec FIN-1 posé ; l'OCR (FIN-0) est un **confort optionnel**, pas un blocage (mode dégradé) |
| **FIN-3** | Expense reports (brique 4) : soumission salarié, workflow `App\Autorisation`, remboursement, déversement comptable via FIN-1 | FIN-1, `App\Personnel`, `App\Autorisation` (déjà livrés) | **Oui**, indépendant de FIN-2 ; l'OCR (FIN-0) optionnel |
| **FIN-4** | Treasury (brique 3) : comptes bancaires, import relevé, rapprochement bancaire (via `reconciliationCode` de FIN-1), échéancier consolidé (agrège FIN-2 + `App\Facturation` + `App\Sepa`), prévisionnel simple | FIN-1, FIN-2 (échéances fournisseurs), `App\Facturation`, `App\Sepa` (déjà livrés) | **Partiellement** — la position de trésorerie et le rapprochement bancaire sont testables sans FIN-2, mais l'**échéancier complet** perd la moitié de ses sources tant que FIN-2 n'est pas livré (dégradation propre : la source manquante est simplement absente de l'agrégat, jamais une erreur) |

**Ordre recommandé : FIN-0 (OCR) et FIN-1 (extension Compta) en parallèle → FIN-2 (Supplier invoices)
→ FIN-3 (Expense reports) → FIN-4 (Treasury).** FIN-2 et FIN-3 peuvent aussi être inversés ou
parallélisés entre deux équipes une fois FIN-1 posé, car ils ne dépendent pas l'un de l'autre. FIN-4
est volontairement dernier : c'est le seul lot qui **agrège** les autres.

## 5. Permissions — tableau récapitulatif

| Permission | Brique | Portée |
|---|---|---|
| `finance.read` | toutes | lecture transverse (tableau de bord finance) |
| `finance.supplier_invoice_create` | 1 | créer/éditer une facture fournisseur en brouillon |
| `finance.supplier_invoice_approve` | 1 | passer en « à payer » (engage la dépense, génère l'écriture) |
| `finance.supplier_invoice_pay` | 1 | enregistrer un règlement fournisseur |
| `finance.supplier_invoice_dispute` | 1 | ouvrir/clore un litige |
| `finance.treasury_manage_account` | 3 | créer/éditer un compte bancaire |
| `finance.treasury_import_statement` | 3 | importer un relevé bancaire |
| `finance.treasury_reconcile` | 3 | rapprocher une ligne de relevé |
| `finance.expense_report_submit` | 4 | soumettre/éditer sa propre note de frais |
| `finance.expense_report_read_own` | 4 | consulter ses propres notes de frais |
| `finance.expense_report_post_to_ledger` | 4 | déverser une note de frais validée en comptabilité |
| `finance.manage` | toutes | surensemble admin (paramétrage) |
| `compta.record_manual_entry` | 2 (extension) | saisir une écriture manuelle libre (OD) |
| `autorisation.approuver` | 4 (réutilisée, nom français préexistant, §0) | approuver une escalade de validation de note de frais hors plafond |
| `ocr.configure` | 5 | choisir/configurer le fournisseur d'extraction (admin) |

⚠ HYPOTHÈSE — comme pour tous les modules déjà livrés (Stock, Personnel, Autorisation, Facturation),
ces noms sont **proposés** par analogie avec le modèle `module × action` du socle, à **arbitrer avec
M8** avant figement. La **validation** d'une note de frais n'a volontairement **pas** de permission
`finance.expense_report_approve` dédiée : elle passe entièrement par `App\Autorisation`
(`OperationSensible` = `finance.expense_report_approve`, gardée par une `LimiteAutorisation` par
plafond — cf. `spec-expense-reports.md` §4.3), cohérent avec le principe déjà posé par
`spec-autorisation.md` §0 (« la couche de décision s'intercale avant l'exécution, elle ne remplace
jamais le contrôle binaire »).

## 6. Événements — catalogue

| Événement | Émis par | Charge utile clé | Consommateurs probables |
|---|---|---|---|
| `supplier_invoice.recorded` *(remplace `facture_fournisseur.enregistree`, §0)* | FIN-2 | supplier, amount, source (ocr/manual) | Compta (FIN-1), Treasury (FIN-4) |
| `supplier_invoice.approved` | FIN-2 | amount, dueDate | Treasury (échéancier) |
| `supplier_invoice.paid` | FIN-2 | amount, date, paymentMethod | Treasury |
| `supplier_invoice.disputed` | FIN-2 | reason | Reporting, Treasury (retrait de l'échéancier) |
| `expense_report.submitted` *(remplace `note_de_frais.soumise`, §0)* | FIN-3 | employee, amount | Autorisation, Compta (FIN-1) |
| `expense_report.approved` | FIN-3 | amount, approver | Compta (FIN-1) |
| `expense_report.reimbursed` | FIN-3 | amount, date | Treasury |
| `treasury.reconciliation_completed` | FIN-4 | statementLine, ledgerEntry | Reporting |
| `treasury.discrepancy_detected` | FIN-4 | amount, cause | Supervision, Reporting |

Consommés par la suite : `facture.emise`/`facture.payee` (`App\Facturation`, noms **existants,
français, non renommés**, pour l'échéancier client de Treasury), `devis.expire` (`App\Devis`, signal
faible pour le prévisionnel — **optionnel, non bloquant**).

## 7. Invariants transverses (rappel constitution — non renégociables)

1. **Multi-tenant cloisonné** — tout objet des 5 briques est rattaché à un **Établissement**/
   `ProfilExploitant` via le socle ; le périmètre est **dérivé serveur** (contexte `X-Etablissement`),
   **jamais** d'un id fourni par le client (`RG-SOCLE-05`, invariant noyau commun #1). Échec fermé
   (403/404), jamais de repli « premier trouvé ».
2. **RBAC module × action** — permissions `finance.*`/`compta.*` (§5), UI qui **masque**, pas
   seulement désactive.
3. **UUID** partout, `strict_types=1`. Entités **nouvelles** de cette suite en **anglais technique**
   (§0) ; entités **historiques référencées** conservent leur nom français ; namespaces
   `App\<Module>\...`.
4. **NF525 — périmètre clarifié** : le chaînage NF525 (inaltérabilité/scellement) s'applique aux
   **écritures comptables** (`EcritureComptable`, déjà scellées par `App\Compta`) quelle que soit leur
   origine (vente, facture directe, **supplier invoice, expense report** — brique 1/4 via FIN-1) —
   c'est le moteur d'écritures qui est scellé, pas chaque document source. Un `SupplierInvoice` ou un
   `ExpenseReport` **n'est pas lui-même chaîné NF525** (ce n'est pas un document de **recette** soumis
   à l'obligation légale visée par NF525), mais il est **non supprimable** une fois validé (correction
   par avoir/contre-passation uniquement), cohérent avec l'esprit d'inaltérabilité du socle
   (`RG-SOCLE-07`, audit append-only).
5. **Chiffrement au repos des secrets** — un IBAN de `BankAccount` (Treasury) réutilise le **coffre
   IBAN réversible** déjà construit pour SEPA (`App\Sepa\Service\ChiffreurIban`, libsodium
   `crypto_secretbox`), **pas un second mécanisme** (§10 de `specs/sepa/plan-sepa.md`).
6. **Dégradation propre** — l'OCR non configuré (ou en échec) = **mode dégradé manuel**, jamais un
   blocage de la saisie (`spec-ocr.md` §4.4).
7. **Aucune logique pays/métier codée en dur** — les catégories de charge (`ExpenseAccountMapping`),
   les comptes fournisseurs/clients, les taux de TVA déductible sont **paramétrables par profil
   exploitant**, jamais figés en `enum` PHP fermé côté comptable.
8. **Libellés i18n** — aucun libellé utilisateur en dur ; toute chaîne affichable (statut, message
   d'erreur métier, nom de colonne d'export lisible) est une **clé de traduction** résolue côté i18n,
   français par défaut (§0).

## 8. Dépendances externes / points de coordination

- **`COORDINATION/CONTRACT/noyau-commun.md`** — proposition d'ajouter une ligne « Document extraction
  (OCR) » au tableau des services du noyau commun (§3, `spec-ocr.md` §8) — à valider avec le
  propriétaire du contrat.
- **`COORDINATION/CONTRACT/catalogue-evenements.md`** — (a) **tranchage de la langue** des noms
  d'événements en anglais pour tout nouveau développement (§0) — à faire acter formellement dans ce
  fichier partagé, au-delà du périmètre de cette suite ; (b) **renommage** des deux entrées déjà
  cataloguées (`facture_fournisseur.enregistree` → `supplier_invoice.recorded`,
  `note_de_frais.soumise` → `expense_report.submitted`) ; (c) ajout des 7 événements complémentaires
  listés §6.
- **Registre de modules** (`App\Platform\Module`, tâche C5, non encore implémenté) — cette suite
  suppose son existence pour l'activation par tenant (§3) ; en son absence, `finance` peut être livré
  comme un module « toujours actif » (comportement provisoire, cohérent avec l'état actuel des autres
  modules du dépôt qui n'ont pas non plus de registre formel à ce jour).

## 9. Points ouverts / hypothèses (récapitulatif de suite)

1. **Vocabulaire mixte assumé** (français historique / anglais nouveau) — cf. §0, point à porter
   explicitement au client.
2. **Dépendances de module vs de feature** dans le manifeste `finance` (§3.1) — non distingué par le
   contrat v0.
3. **OCR au noyau commun** — proposition non tranchée avec le propriétaire du contrat (§8).
4. **Renommage du catalogue d'événements partagé** — décision de langue prise ici pour cette suite,
   à répercuter formellement dans `catalogue-evenements.md` (§8) — impact potentiel sur d'autres
   agents/modules qui liraient ce catalogue.
5. **Validation graduée des supplier invoices au-delà d'un plafond** — cette suite retient un câblage
   optionnel sur `App\Autorisation` (comme les expense reports), **non détaillé** dans
   `spec-supplier-invoices.md` au-delà d'un point ouvert signalé (§4.6 de cette brique) — à confirmer
   avec le métier si le besoin est aussi fort côté achats que côté notes de frais.
6. Chacune des 5 specs de brique porte ses propres points ouverts, listés en fin de fichier
   respectif — non dupliqués ici.

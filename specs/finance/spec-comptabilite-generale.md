# Spec — General ledger, extension (`App\Compta`, lot `FIN-1`)

- **Lot / module :** **extension** du module existant **`App\Compta`** (L4 · M6 Compta & Régie,
  `specs/L4-compta/spec-compta.md`) — aucun nouveau module, le module reste `compta`. Convention de
  nommage : voir `spec-finance-suite.md` §0 — les entités **déjà livrées** gardent leur nom français
  (`EcritureComptable`, `LigneEcriture`, `CompteComptable`, `Journal`, `TauxTva`, `ProfilExploitant`,
  `PeriodeComptable`, `LettrageEcriture`, `MoyenPaiement` — **non renommées**) ; les artefacts
  **nouveaux** introduits par cette extension sont nommés en **anglais**.
- **Stories couvertes :** **US-L4-11 à US-L4-14** — ⚠ **HORS BACKLOG**, dans la continuité du RAD/
  redevances DSP et de la consolidation groupe déjà signalés hors-US par `spec-compta.md` §4.8/§4.11 :
  le backlog `L4` actuel (10 US, 63 pts, scope « profil régie ») ne couvre pas la saisie manuelle
  d'écriture, le compte auxiliaire tiers ni le lettrage groupé. À faire valider/chiffrer avant
  développement.
- **Règles de gestion :** **RG-M6-11 à RG-M6-14** (nouvelles, continuent la numérotation de
  `spec-compta.md`). Règles **réutilisées, non redéfinies** : `RG-M6-01` à `RG-M6-10` intégralement,
  `RG-COMPTA-01/04`, `RG-CLOTURE-10`.
- **Statut :** brouillon — cette spec **ne remplace pas** `spec-compta.md`, elle en est un **addendum**
  à lire après elle ; en cas de silence ici, `spec-compta.md` fait foi.

## 0. Pourquoi cette extension — ce qui manque, constaté dans le code existant

`App\Compta` (`app/src/Compta/`) porte déjà, **intégralement fonctionnel** : le plan de comptes
paramétrable (`CompteComptable`), les journaux (`Journal`), les écritures scellées NF525
(`EcritureComptable`/`LigneEcriture`, `ScellementEcritureHandler`), le lettrage
(`LettrageEcriture`/`LettrageHandler`), les périodes et la clôture (`PeriodeComptable`/
`ClotureHandler`), et l'**export FEC réel** (`ExportFecAdapter`, 18 champs). **Rien de tout cela n'est
recréé.**

Ce qui manque, constaté à la lecture du code (`GenererEcrituresProcessor`/
`GenerateurEcrituresHandler`) : la **seule** source d'écriture automatique est la **vente** (port
`ProjectionVenteInterface`). Il n'existe :
1. **aucune écriture manuelle libre** (opération diverse, OD) saisissable par un Comptable ;
2. **aucun mapping comptable pour les charges** (l'équivalent, côté achats, de `MappingComptable` qui
   ne couvre aujourd'hui que les **produits** de vente, `RG-M6-01`) ;
3. **aucune notion de tiers (compte auxiliaire)** sur une ligne d'écriture — les colonnes `CompAuxNum`/
   `CompAuxLib` de l'export FEC (`ExportFecAdapter::CHAMPS`) sont **actuellement toujours vides** ;
4. **aucun lettrage groupé** — `LettrageEcriture` ne marque qu'**une seule** ligne « lettrée », sans
   lien vers la ou les lignes en contrepartie (facture ↔ règlement, écriture ↔ ligne de relevé
   bancaire).

Ces 4 manques bloquent directement les briques **Supplier invoices** (FIN-2), **Expense reports**
(FIN-3) et **Treasury** (FIN-4), qui ont toutes besoin de comptabiliser une dépense et de savoir « qui
a été payé, pour quoi, est-ce soldé ». Cette spec les comble, **par extension additive** de
`App\Compta`, sans toucher au comportement déjà spécifié/testé de M2→M6 (ventes).

## 1. Objectif

Donner au Comptable les **deux capacités génériques** qui manquent au moteur comptable existant pour
absorber des faits générateurs **autres que la vente** (achat fournisseur, remboursement de note de
frais, écriture d'ouverture, régularisation) : (a) **saisir une écriture équilibrée directement**, (b)
**router automatiquement une dépense vers le bon compte de charge** par nature, et **tracer le tiers**
concerné pour produire un grand livre auxiliaire et un export FEC complets.

## 2. Périmètre

### Inclus
- **Écriture manuelle libre (OD)** : un Comptable saisit lui-même une `EcritureComptable` équilibrée
  hors de tout automatisme (US-L4-11, RG-M6-11).
- **`ExpenseAccountMapping`** *(nouvelle entité)* : mapping paramétrable « nature de charge → compte de
  charge + taux de TVA déductible », symétrique de `MappingComptable` côté produits (US-L4-12,
  RG-M6-12).
- **Compte auxiliaire (tiers)** sur `LigneEcriture` : extension additive, 3 nouvelles colonnes nullable
  (`counterpartyType`, `counterpartyId`, `counterpartyLabel`) portant l'identité du tiers (fournisseur,
  salarié, client) concerné par une ligne 401/421/411 (US-L4-13, RG-M6-13). Alimente l'export FEC
  (`CompAuxNum`/`CompAuxLib`) et le futur grand livre auxiliaire.
- **Lettrage groupé** : extension de `LettrageEcriture` (nouvelle colonne `reconciliationCode`) +
  nouvelle méthode `LettrageHandler::lettrerGroupe()` pour rapprocher **plusieurs** lignes entre elles
  en un seul geste (facture fournisseur + son règlement ; écriture bancaire + ligne de relevé importé)
  (US-L4-14, RG-M6-14).
- **Service interne réutilisable** `DirectLedgerEntryBuilder` *(nouveau)* : factorise le patron déjà
  utilisé par `App\Facturation\Service\EmettreFactureDirecteHandler` (résolution de comptes →
  construction des lignes → `ScellementEcritureHandler::sceller()` → persist) pour que **FIN-2** et
  **FIN-3** ne dupliquent pas cette mécanique.

### Exclu (pour l'instant)
- Toute modification du **moteur de génération à partir des ventes** (`ProjectionVenteInterface`,
  `GenerateurEcrituresHandler`) — inchangé.
- Toute modification du **format FEC** lui-même (18 champs, séparateur tabulation) — seules les
  colonnes déjà prévues mais vides (`CompAuxNum`/`CompAuxLib`) sont **peuplées**, structure inchangée.
- Un **plan comptable importable** depuis un fichier externe (CSV/XML expert-comptable) — hors
  périmètre de ce lot, `CompteComptable` reste saisi/édité un par un via l'API déjà existante.
- La **consolidation groupe** et le **RAD/redevances DSP** — points déjà ouverts par `spec-compta.md`
  §4.8/§4.11, non traités ici.

## 3. Acteurs & droits

Réutilise le module `compta` existant (`RG-SOCLE-02/03/04/05`), aucun nouvel acteur.

| Acteur | Peut (nouveau) | Permission (module × action) |
|---|---|---|
| **Comptable** (déjà `compta.lire`/`compta.valider`, noms préexistants inchangés) | Saisir une écriture manuelle libre ; lettrer un groupe de lignes | `compta.record_manual_entry` *(nouvelle action, en anglais — §0)*, `compta.lettrer` *(déjà existante, réutilisée)* |
| **Administrateur** (déjà `compta.gerer`) | Paramétrer `ExpenseAccountMapping` | `compta.gerer` *(déjà existante, réutilisée — aucune nouvelle action de paramétrage)* |
| **Système** (autres modules : FIN-2, FIN-3) | Créer une écriture directe **pour leur propre compte**, via le service interne `DirectLedgerEntryBuilder`, gardée par **leur propre** permission (`finance.supplier_invoice_approve`, `finance.expense_report_post_to_ledger`) — **pas** `compta.record_manual_entry`, qui reste réservée à la saisie **manuelle** par un humain du module Compta lui-même | *(appel interne, pas d'API `compta` exposée)* |

⚠ HYPOTHÈSE — `compta.record_manual_entry` est **une nouvelle action**, à ajouter au référentiel
`Permission` existant, même mécanique que les ajouts déjà proposés par `spec-facturation.md`
(`facturation.*`) et `spec-autorisation.md` (`autorisation.*`) — à arbitrer avec M8.

## 4. Comportements & règles

### 4.1 Écriture manuelle libre — OD (US-L4-11, RG-M6-11)
- **RG-M6-11** — Un Comptable peut créer directement une `EcritureComptable` **équilibrée**
  (Σdébit = Σcrédit, réutilise `EcritureComptable::estEquilibree()`), rattachée à un **journal dédié
  « opérations diverses »** (code paramétrable, ex. `OD`, réutilise `Journal` existant — aucune
  contrainte de code fermé), sur une **période ouverte** (`PeriodeComptable::estOuverte()`).
  - Chaque ligne exige, **comme aujourd'hui**, un `CompteComptable` et un `TauxTva` (contrainte déjà
    posée par `LigneEcriture`, non modifiée) : une ligne **non commerciale** (ex. frais bancaires,
    virement interne) référence le taux **« Hors champ »** déjà seedé (`TauxTva::LIBELLE_HORS_CHAMP`),
    **réutilisé tel quel**, pas de nouveau taux « néant » créé.
  - Une fois créée, l'écriture suit **exactement** le même cycle de vie et les mêmes gardes que toute
    autre écriture (`provisoire → contrôlée/lettrée → validée → exportée`, scellement NF525,
    inaltérabilité, seule correction par extourne, `RG-M6-04`) — **aucune branche spécifique**.
- **API observable** : `POST /compta/journal-entries/manual` (corps : `businessProfile`, `journal`,
  `date`, `label`, `lines[{account, debit|credit, vatRate, counterparty?}]`) —
  `security: compta.record_manual_entry`. Rejet 422 si déséquilibrée ou si une ligne référence un
  compte inactif.

### 4.2 `ExpenseAccountMapping` — mapping charges (US-L4-12, RG-M6-12)
- **RG-M6-12** — Un `ExpenseAccountMapping` associe, par `ProfilExploitant`, une **clé de nature de
  charge** (`expenseNatureCode`, chaîne libre paramétrable, ex. `default_supplier`, `travel`, `lodging`,
  `meals`, `supplies`, `other` — **liste ouverte**, jamais un `enum` PHP fermé, cohérent constitution
  §4.4) à un **compte de charge** (`expenseAccount`, `CompteComptable`, classe 6 par convention, non
  contrainte techniquement) et un **taux de TVA déductible** (`deductibleVatRate`, `TauxTva`).
  - Symétrique de `MappingComptable` (produits, RG-M6-01) : un mapping **incomplet ou inactif** bloque
    la génération d'écriture pour la ligne concernée (même garde que `MappingComptableGuard`, réutilisée
    par analogie — nouveau garde `ExpenseAccountMappingGuard`, même patron).
  - Un `ExpenseAccountMapping` par défaut (`default_supplier`) est **recommandé** à la configuration
    initiale d'un profil exploitant activant `supplier_invoices` — non imposé techniquement.

### 4.3 Compte auxiliaire (tiers) sur `LigneEcriture` (US-L4-13, RG-M6-13)
- **RG-M6-13** — `LigneEcriture` porte, en **extension additive nullable** (aucune migration
  destructrice, cohérent `manifeste-module.md` « activer/désactiver n'entraîne aucune migration
  destructrice ») :
  - `counterpartyType` (chaîne, ex. `stock_fournisseur`, `personnel_employe`, `crm_client`) ;
  - `counterpartyId` (uuid, référence logique — pas de FK dure, même patron que
    `MappingComptable.categorie`, pour ne pas coupler durement `App\Compta` à `App\Stock`/
    `App\Personnel`/`App\Crm`) ;
  - `counterpartyLabel` (chaîne, snapshot du nom au moment de l'écriture — cohérent avec l'« instantané
    figé » déjà retenu pour `DestinataireFacturation`, `spec-facturation.md` §4.4).
  - Une ligne portant un compte de tiers (fournisseur 401, personnel 421, client 411) **devrait**
    renseigner ces 3 champs — **non bloquant techniquement** (une écriture sans tiers reste valide,
    ex. contrepartie 512 banque) pour ne pas casser les écritures déjà générées par le moteur ventes.
- **Export FEC** (`ExportFecAdapter`) — les colonnes `CompAuxNum`/`CompAuxLib`, **actuellement vides**,
  sont désormais **peuplées** depuis `counterpartyId`/`counterpartyLabel` quand présents ; **aucun
  changement de structure du fichier** (toujours 18 colonnes, même ordre).

### 4.4 Lettrage groupé (US-L4-14, RG-M6-14)
- **RG-M6-14** — `LettrageEcriture` porte un **`reconciliationCode`** (chaîne, généré, partagé entre
  plusieurs lignes lettrées ensemble). `LettrageHandler::lettrerGroupe(lignes[], auteur, date?)` :
  - exige **au moins 2 lignes**, toutes **scellées** (même garde que `lettrer()` existant) ;
  - vérifie que la **somme des débits** des lignes du groupe **égale la somme des crédits** (équilibre
    du rapprochement — ex. une ligne 401 « facture fournisseur » au crédit de 1 200 € et une ligne 512
    « paiement » au débit de 1 200 € du même montant) ; rejet 422 sinon ;
  - crée une `LettrageEcriture` par ligne, **toutes** avec le **même** `reconciliationCode` ;
  - le lettrage simple existant (`lettrer()`, une seule ligne, `reconciliationCode = null`) **reste
    disponible et inchangé** — pas de régression sur son usage actuel (rapprochement recette/versement
    de régie, `spec-compta.md` §4.2).

### 4.5 `DirectLedgerEntryBuilder` — service interne réutilisable
- Factorise, sans changer le comportement observable, le patron déjà éprouvé par
  `App\Facturation\Service\EmettreFactureDirecteHandler` : verrou pessimiste sur l'objet appelant,
  résolution de tous les comptes **avant** toute écriture partielle, construction d'une
  `EcritureComptable` + `LigneEcriture[]` équilibrée dans **une seule transaction**, appel à
  `ScellementEcritureHandler::sceller()` (réutilisé strictement), flush. Consommé par **FIN-2** et
  **FIN-3** pour comptabiliser respectivement un supplier invoice validé et un expense report validé —
  **aucun second moteur d'écritures**, seulement un **assemblage** factorisé du même patron.

## 5. Objets de données

Extensions **additives** des entités M6 existantes (`app/src/Compta/Entity/`, noms français inchangés),
plus un objet nouveau (nom anglais, §0).

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **`LigneEcriture`** *(extension, entité française inchangée)* | counterpartyType | string, nullable | — | RG-M6-13 |
| | counterpartyId | uuid, nullable | référence logique, pas de FK dure | RG-M6-13 |
| | counterpartyLabel | string, nullable | snapshot au moment de l'écriture | RG-M6-13 |
| **`LettrageEcriture`** *(extension, entité française inchangée)* | reconciliationCode | string, nullable | partagé entre les lignes d'un même groupe | RG-M6-14 |
| **`ExpenseAccountMapping`** *(nouveau, nom anglais)* | id | uuid | PK | RG-M6-12 |
| | businessProfile | ref ProfilExploitant | requis | référence l'entité française existante |
| | expenseNatureCode | string | requis, unique par profil | clé libre paramétrable |
| | expenseAccount | ref CompteComptable | requis | classe 6 par convention |
| | deductibleVatRate | ref TauxTva | requis | — |
| | active | bool | défaut = true | même garde que `MappingComptable::estValide()` |

Table Doctrine proposée : `compta_expense_account_mapping` (préfixe `compta_`, cohérent avec les
autres tables du module hôte, même si le nom d'entité est anglais).

## 6. Critères d'acceptation

- **CA-1 (US-L4-11, RG-M6-11)** — *Étant donné* un Comptable habilité `compta.record_manual_entry`,
  *quand* il saisit une écriture manuelle équilibrée sur une période ouverte, *alors* elle est créée,
  scellée NF525 comme toute autre écriture, et **traçable** au même titre qu'une écriture générée
  automatiquement (mêmes filtres, même export).
- **CA-2 (US-L4-11)** — *Étant donné* une écriture manuelle **déséquilibrée**, *quand* elle est
  soumise, *alors* la création est **rejetée (422)**, aucune écriture partielle n'est persistée.
- **CA-3 (US-L4-12, RG-M6-12)** — *Étant donné* une nature de charge sans `ExpenseAccountMapping`
  actif, *quand* une brique consommatrice (FIN-2/FIN-3) tente de comptabiliser une dépense de cette
  nature, *alors* la génération d'écriture est **bloquée**, signalée (mapping incomplet), sans bloquer
  la saisie du supplier invoice/expense report lui-même (il reste en brouillon/à traiter).
- **CA-4 (US-L4-13, RG-M6-13)** — *Étant donné* une ligne d'écriture portant un tiers renseigné,
  *quand* un export FEC est généré sur la période, *alors* `CompAuxNum`/`CompAuxLib` sont **peuplés**
  pour cette ligne, vides pour les lignes sans tiers (rétrocompatibilité stricte des écritures
  historiques).
- **CA-5 (US-L4-14, RG-M6-14)** — *Étant donné* une ligne « facture fournisseur » au crédit de 500 €
  et une ligne « paiement » au débit de 500 €, toutes deux scellées, *quand* le Comptable les lettre
  ensemble, *alors* les deux `LettrageEcriture` créées portent le **même** `reconciliationCode`.
- **CA-6 (US-L4-14)** — *Étant donné* un groupe de lignes dont la somme des débits **diffère** de la
  somme des crédits, *quand* un lettrage groupé est tenté, *alors* il est **rejeté (422)**.
- **CA-7 (rétrocompatibilité)** — *Étant donné* le comportement M2→M6 déjà spécifié (génération
  automatique depuis une vente, `RG-COMPTA-04`), *quand* une vente est validée après activation de
  cette extension, *alors* le comportement **ne change pas** (aucune régression, `counterparty*`/
  `reconciliationCode` restent `null` sur les écritures générées par le moteur ventes, sauf évolution
  ultérieure explicite).

## 7. Cas limites
- **Ligne manuelle sans tiers sur un compte 401/411/421** — Acceptée (champ non bloquant, §4.3) ; un
  contrôle de cohérence (« ligne sur compte de tiers sans tiers renseigné ») pourrait être ajouté comme
  **alerte non bloquante** dans un lot ultérieur — non retenu ici.
- **`ExpenseAccountMapping` désactivé après utilisation** — Les écritures déjà générées restent
  inchangées (append-only) ; seules les **futures** générations sont bloquées tant qu'aucun mapping
  actif n'existe (même garde que `MappingComptable`).
- **Lettrage groupé portant une ligne déjà lettrée individuellement** — ⚠ HYPOTHÈSE : rejeté (une ligne
  ne peut être lettrée qu'**une seule fois**, qu'elle porte un `reconciliationCode` ou non) — à
  confirmer, aucune règle explicite du cahier ne couvre le re-lettrage.
- **Écriture manuelle sur une période clôturée** — Refusée, cohérent `RG-CLOTURE-10` (aucune écriture
  nouvelle sur période clôturée, seule une extourne sur période **ouverte** est possible).
- **Deux natures de charge pointant vers le même compte** — Autorisé (plusieurs `ExpenseAccountMapping`
  peuvent partager un même `expenseAccount`, ex. `travel` et `lodging` vers un même compte 625
  générique) — pas de contrainte d'unicité sur `expenseAccount`.

## 8. Dépendances
- **Dépend de : L4 · Compta & Régie** (`specs/L4-compta/spec-compta.md`) — extension pure, tout le
  socle (`ProfilExploitant`, `Journal`, `CompteComptable`, `TauxTva`, `EcritureComptable`,
  `ScellementEcritureHandler`, `LettrageHandler`, `PeriodeComptable`, `ExportFecAdapter`) réutilisé
  sans modification structurelle, seulement enrichi.
- **Consommé par : Supplier invoices (FIN-2)** et **Expense reports (FIN-3)** — via
  `DirectLedgerEntryBuilder` (§4.5) et `ExpenseAccountMapping` (§4.2).
- **Consommé par : Treasury (FIN-4)** — via le lettrage groupé (§4.4) pour le rapprochement bancaire.
- **Référence le patron de : `App\Facturation`** (`EmettreFactureDirecteHandler`) — dont le service
  interne §4.5 s'inspire directement, sans dépendance de code (juste un patron répliqué).

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ HORS BACKLOG** — `US-L4-11` à `14` proposées, à valider/chiffrer (en-tête).
2. Nouvelle action `compta.record_manual_entry` à ajouter au référentiel `Permission` (§3).
3. Alerte non bloquante « ligne sur compte de tiers sans tiers renseigné » — non retenue v1 (§7).
4. Re-lettrage d'une ligne déjà lettrée — comportement rejeté par défaut, non confirmé par une règle
   explicite du cahier (§7).
5. **Vocabulaire mixte** français (entités historiques) / anglais (nouveaux artefacts) au sein du même
   module `App\Compta` — assumé, cf. `spec-finance-suite.md` §0.

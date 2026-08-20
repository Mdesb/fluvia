# Plan technique — General ledger, extension additive (`App\Compta`, lot `FIN-1`)

- **Spec source :** specs/finance/spec-comptabilite-generale.md (addendum à specs/L4-compta/spec-compta.md)
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Contrat de plateforme :** COORDINATION/CONTRACT/manifeste-module.md (extension du manifeste `compta`
  existant, §3.2 de `spec-finance-suite.md`), COORDINATION/CONTRACT/i18n-traduction.md, aucun événement
  du catalogue introduit par ce lot (`events_emitted` inchangés — brique 2 = prérequis technique interne,
  pas un émetteur de fait métier nouveau, cf. `spec-finance-suite.md` §3.2)
- **Dépend de :** `App\Compta` L4 **déjà livré et non modifié structurellement** — `ProfilExploitant`,
  `Journal`, `CompteComptable`, `TauxTva`, `EcritureComptable`/`LigneEcriture` + chaînage NF525
  (`ScellementEcritureHandler`), `PeriodeComptable`, `ExportFecAdapter` (18 champs, réel), patron
  d'écriture directe déjà éprouvé par `App\Facturation\Service\EmettreFactureDirecteHandler`
- **Couvre :** US-L4-11 à US-L4-14 · RG-M6-11 à RG-M6-14 (étend RG-M6-01→10, RG-COMPTA-01/04,
  RG-CLOTURE-10, réutilisés sans modification) · CA-1 à CA-7

> **Ce lot est une extension pure.** Aucune entité existante n'est renommée, aucun comportement déjà
> testé n'est modifié. Toutes les migrations sont **additives** (colonnes `NULL`, nouvelle table),
> conformément à `manifeste-module.md` : « activer/désactiver une capacité ou une feature n'entraîne
> aucune migration destructive ; les données restent, seule l'exposition change. »

---

## 0. Décisions d'architecture

### 0.1 Où s'arrête « additif », précisément

| Fichier existant | Modification | Nature |
|---|---|---|
| `App\Compta\Entity\LigneEcriture` | +3 propriétés nullable (`counterpartyType`, `counterpartyId`, `counterpartyLabel`) + getters/setters | additive, aucun champ existant touché |
| `App\Compta\Entity\LettrageEcriture` | +1 propriété nullable (`reconciliationCode`) + getter/setter ; **+ ajout d'un `#[ApiResource]`** (l'entité n'en a aucun aujourd'hui — elle n'était accessible qu'en interne via `LettrageHandler`, jamais exposée en HTTP) | additive (nouvelle colonne) + nouvelle exposition API (aucune opération existante retirée, il n'y en avait pas) |
| `App\Compta\Export\ExportFecAdapter::generer()` | Les 2 chaînes littérales `''`/`''` (colonnes `CompAuxNum`/`CompAuxLib`, lignes 45-46 du fichier actuel) sont remplacées par `(string) $ligne->getCounterpartyId() ?: ''` / `$ligne->getCounterpartyLabel() ?? ''` | comportemental mais **strictement rétrocompatible** : une ligne sans tiers produit exactement la même sortie qu'aujourd'hui (chaîne vide) — testé en non-régression (§5, CA-7) |
| `App\Compta\Service\LettrageHandler` | +1 méthode publique `lettrerGroupe()` ; `lettrer()` existant **non touché** | additive |
| `App\Compta\Service\GenerateurEcrituresHandler` | **aucune modification** — la méthode privée `periodePour()` est **dupliquée logiquement** dans un nouveau service partagé plutôt que refactorée in-place, pour garantir un risque de régression nul sur le moteur ventes déjà testé (voir §0.3 — arbitrage documenté) | non touché |

Aucun autre fichier `App\Compta` existant n'est modifié.

### 0.2 Nouveaux artefacts (namespace `App\Compta`, noms anglais — §0 de `spec-finance-suite.md`)

- `App\Compta\Entity\ExpenseAccountMapping` (entité)
- `App\Compta\Service\ExpenseAccountMappingGuard` (service, symétrique de `MappingComptableGuard`)
- `App\Compta\Service\PeriodeComptableResolver` (service, factorisation légère — §0.3)
- `App\Compta\Service\DirectLedgerEntryBuilder` + `App\Compta\Dto\DirectLedgerEntryLine` (service interne
  réutilisable, §0.4)
- `App\Compta\Service\SaisirEcritureManuelleHandler` + `App\Compta\State\SaisirEcritureManuelleProcessor`
  (endpoint `POST /compta/journal-entries/manual`)
- `App\Compta\State\LettrerGroupeProcessor` (endpoint `POST /compta/lettrages/groupe`)

### 0.3 Résolution de période — factorisation **sans risque** plutôt qu'un refactor in-place

`GenerateurEcrituresHandler::periodePour()` (privée) résout/crée la `PeriodeComptable` couvrant une
date. `DirectLedgerEntryBuilder` a besoin exactement du même service. Deux options :
(a) extraire cette méthode en service partagé et faire pointer `GenerateurEcrituresHandler` dessus
(refactor du moteur ventes déjà testé et scellé NF525 en production) ;
(b) créer `App\Compta\Service\PeriodeComptableResolver` **neuf**, avec la même logique, utilisé
uniquement par les nouveaux artefacts FIN-1, sans toucher au moteur ventes.

**Décision : (b)**, par prudence maximale — le risque de régression sur le moteur ventes (déjà en
production, chaînage NF525 déjà scellé pour des écritures réelles) l'emporte sur la petite duplication
de ~15 lignes. Un refactor ultérieur (option a) reste possible sans casser l'API du nouveau service
(même signature `pour(ProfilExploitant, \DateTimeImmutable): PeriodeComptable`), à envisager dans un
lot de nettoyage dédié, hors périmètre FIN-1.

**Différence volontaire avec `periodePour()` pour la saisie manuelle** : contrairement au moteur ventes
qui **crée** silencieusement la période manquante (flux automatique, aucune interaction humaine),
`SaisirEcritureManuelleProcessor` **exige une période existante** couvrant la date et rejette (422) si
aucune n'existe — un Comptable qui saisit une OD doit d'abord avoir ouvert son exercice/mois via
`POST /compta/periodes` (déjà existant). `PeriodeComptableResolver` expose donc deux méthodes :
`resoudreOuCreer()` (usage interne différé pour un futur refactor du moteur ventes) et `resoudre()`
(lecture seule, utilisée par la saisie manuelle) — seule `resoudre()` est appelée par ce lot.

### 0.4 `DirectLedgerEntryBuilder` — le contrat exact

```php
namespace App\Compta\Service;

final class DirectLedgerEntryBuilder
{
    public function __construct(
        private readonly ScellementEcritureHandler $scellement,
    ) {}

    /**
     * Construit et scelle une écriture équilibrée. Ne flush PAS (le flush reste sous la responsabilité
     * de l'appelant, dans SA transaction — même patron que EmettreFactureDirecteHandler). Précondition :
     * l'appelant a déjà verrouillé (LockMode::PESSIMISTIC_WRITE) SON PROPRE objet métier (SupplierInvoice,
     * ExpenseReport…) et résolu tous les comptes AVANT d'appeler cette méthode.
     *
     * @param list<DirectLedgerEntryLine> $lignes
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException si la période n'est pas ouverte (RG-CLOTURE-10)
     * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException si la somme débit ≠ crédit
     */
    public function construire(
        ProfilExploitant $profil,
        Journal $journal,
        PeriodeComptable $periode,
        \DateTimeImmutable $date,
        string $libelle,
        array $lignes,
        ?Uuid $venteOrigine = null,
    ): EcritureComptable;
}
```

- `DirectLedgerEntryLine` (`App\Compta\Dto`, `final readonly`) : `compte: CompteComptable`,
  `debitCentimes: int`, `creditCentimes: int`, `tauxTva: TauxTva`, `libelle: ?string`,
  `counterpartyType: ?string`, `counterpartyId: ?Uuid`, `counterpartyLabel: ?string`.
- Comportement : vérifie `periode->estOuverte()` (sinon 409, RG-CLOTURE-10) ; construit
  `EcritureComptable` (statut `StatutEcriture::Controlee`, **même valeur que
  `GenerateurEcrituresHandler`/`ExtourneEcritureProcessor`** — la constante `Provisoire` du constructeur
  d'entité n'est en pratique jamais utilisée telle quelle par aucun flux de création réel du dépôt, la
  saisie manuelle **suit ce précédent**, RG-M6-11 « aucune branche spécifique ») ; ajoute une
  `LigneEcriture` par `DirectLedgerEntryLine` (report des 3 champs `counterparty*`) ; vérifie
  `estEquilibree()` **après** construction (défense en profondeur — l'appelant doit déjà avoir validé
  l'équilibre, §1 SaisirEcritureManuelleHandler) ; appelle
  `ScellementEcritureHandler::sceller()` (réutilisé **strictement tel quel**, aucun second moteur de
  chaînage) ; `persist()` sans `flush()`.
- Consommé dès ce lot par `SaisirEcritureManuelleHandler` (§1) ; **prêt** pour FIN-2/FIN-3 (non modifié
  par ces lots futurs, contrat stable).

### 0.5 Cloisonnement du nouvel endpoint — durcissement volontaire (rappel explicite anti-IDOR)

**Constat sur le code existant** (`GenererEcrituresProcessor`, déjà en place, non modifié par ce lot) :
le `profilExploitant` est résolu **uniquement** depuis l'id fourni dans le corps de la requête, **sans
aucune vérification** qu'il appartient à l'établissement actif (`ContexteEtablissement`). C'est
exactement le patron d'IDOR cross-tenant déjà corrigé ailleurs dans le dépôt — **ce lot ne le reproduit
pas** pour son propre endpoint, et documente le constat existant comme risque à traiter séparément
(§7, point 1).

**Design retenu pour `SaisirEcritureManuelleProcessor` (nouveau)** :
1. Résout `businessProfile` depuis le corps (référence IRI/UUID, comme les autres processors du module).
2. **Vérifie explicitement** `$profil->couvre($etablissementActif)` où `$etablissementActif` est résolu
   **côté serveur** via `ContexteEtablissement::idActif()` (jamais depuis un champ du corps) — cf.
   `ProfilExploitant::couvre()`, méthode déjà existante et déjà testée, réutilisée telle quelle.
3. **Échec fermé** : si le profil n'existe pas OU n'est pas couvert par l'établissement actif → **404**
   (pas 403 : ne pas révéler l'existence d'un profil hors périmètre — cohérent avec l'invariant #2 du
   noyau commun, « échec fermé, jamais de repli premier trouvé »).
4. `journal`/comptes/`vatRate` référencés dans les lignes sont ensuite vérifiés comme appartenant **au
   même** `profilExploitant` (déjà une contrainte naturelle via `#[ORM\ManyToOne]` + comparaison d'id),
   sinon 422 — évite qu'un utilisateur autorisé sur son propre profil injecte un compte d'un **autre**
   profil (IDOR inter-profils, distinct de l'IDOR inter-établissements couvert au point 2).

Ce garde est **le patron de référence** à répliquer par FIN-2/FIN-3 quand ils appelleront
`DirectLedgerEntryBuilder` avec leurs propres `SupplierInvoice`/`ExpenseReport` (déjà vérifiés dans leur
propre périmètre par leurs propres processors — hors code de ce lot, mais le patron est documenté ici
pour éviter toute divergence).

---

## 1. Entités & schéma

| Entité (`App\Compta\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **`LigneEcriture`** *(extension, entité française inchangée par ailleurs)* | `counterpartyType` | `string(32)` | **oui** | — | référence logique (chaîne libre, ex. `stock_fournisseur`/`personnel_employe`/`crm_client`) |
| | `counterpartyId` | `uuid` | **oui** | index (nouveau, `idx_ligne_ecriture_counterparty`) | référence logique, **pas de FK dure** (même patron que `MappingComptable.categorie` — ne couple pas durement `App\Compta` à `App\Stock`/`App\Personnel`/`App\Crm`) |
| | `counterpartyLabel` | `string(255)` | **oui** | — | snapshot figé au moment de l'écriture (même patron que `DestinataireFacturation`) |
| **`LettrageEcriture`** *(extension)* | `reconciliationCode` | `string(36)` | **oui** | index (`idx_lettrage_reconciliation_code`) | partagé entre toutes les `LettrageEcriture` d'un même groupe (RG-M6-14) ; `null` pour un lettrage simple existant (`lettrer()`, inchangé) |
| **`ExpenseAccountMapping`** *(nouveau, nom anglais, table `compta_expense_account_mapping`)* | `id` | `uuid` | non | PK | — |
| | `businessProfile` | `uuid` (FK) | non | — | `ProfilExploitant` (entité française existante, référencée telle quelle) |
| | `expenseNatureCode` | `string(64)` | non | **unique** avec `businessProfile` | chaîne libre paramétrable (`default_supplier`, `travel`, `lodging`, `meals`, `supplies`, `other`, …) — liste **ouverte**, jamais un `enum` PHP fermé (constitution §4.4, RG-M6-12) |
| | `expenseAccount` | `uuid` (FK) | non | — | `CompteComptable` (classe 6 par convention, non contrainte techniquement) |
| | `deductibleVatRate` | `uuid` (FK) | non | — | `TauxTva` |
| | `active` | `bool` | non | défaut `true` | même garde que `MappingComptable::estValide()` |

> id = UUID (`symfony/uid`). Rattachement multi-entités : via `ProfilExploitant.etablissementPrincipal`
> (même patron que `MappingComptable`/`CompteComptable`/`Journal`/`TauxTva`). Aucune nouvelle stratégie
> de cloisonnement introduite : ce lot **réutilise** le patron déjà en place pour `App\Compta` (permission
> `compta.*` + périmètre porté par le profil exploitant, vérifié explicitement dans les processors —
> §0.5), il n'ajoute **pas** de `PerimetreComptaExtension` Doctrine (il n'en existait aucune avant ce
> lot pour le module Compta, et ce lot ne modifie pas cette architecture existante).

---

## 2. API (API Platform)

| Ressource | Opérations | `security:` | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| `EcritureComptable` *(existant, +1 opération)* | `POST /compta/journal-entries/manual` — corps `{ businessProfile, journal, date, label, lines: [{ account, debit\|credit, vatRate, counterparty?: { type, id, label } }] }`, `read: false`, `input: false` (lu via `LecteurCorps`, même patron que `GenererEcrituresProcessor`), `processor: SaisirEcritureManuelleProcessor::class` | `is_granted('PERM', 'compta.record_manual_entry')` | sortie : `ecriture:read` (même groupe que les écritures générées automatiquement — **CA-1**, traçable au même titre) | — |
| `ExpenseAccountMapping` *(nouveau)* | `GetCollection`, `Get` · `Post`, `Patch` | Lecture : `is_granted('PERM', 'compta.lire')` · Écriture : `is_granted('PERM', 'compta.gerer')` (**aucune nouvelle permission de paramétrage**, réutilise l'action existante — spec §3) | `expense_mapping:read` / `expense_mapping:write` | `businessProfile` exact, `expenseNatureCode` exact, `active` exact |
| `LettrageEcriture` *(nouveau — l'entité n'était pas exposée avant ce lot)* | `GetCollection` (lecture des lettrages, utile UI rapprochement) · `POST /compta/lettrages/groupe` — corps `{ lines: [iri, ...] }`, `read: false`, `input: false`, `processor: LettrerGroupeProcessor::class` | `is_granted('PERM', 'compta.lire')` sur `GetCollection` · `is_granted('PERM', 'compta.lettrer')` sur le groupé (permission déjà existante, réutilisée — spec §3) | `ecriture:lettrage` | `ligne` exact, `reconciliationCode` exact |

**Non exposé par ce lot** : le lettrage simple (`LettrageHandler::lettrer()`) reste **strictement
interne**, appelé uniquement par `ReglementFactureHandler` (M4/Facturation) — pas de nouvelle route
publique pour lui, conforme à « reste disponible et inchangé » (RG-M6-14).

---

## 3. Sécurité & droits

- **Permission nouvelle** : `compta.record_manual_entry` (module `compta`, action nouvelle en anglais —
  seule nouvelle permission de ce lot, cf. spec §3/§9 point 2). À créer via l'API `Permission` existante
  (`securite.gerer`) ou fixtures de test ; **pas** de migration de données (patron déjà en place pour
  toutes les permissions du dépôt).
- **Permissions réutilisées, non redéfinies** : `compta.lire`, `compta.gerer`, `compta.lettrer`,
  `compta.valider`, `compta.cloturer` — toutes déjà seedées par `ComptaFixtures` (action `lettrer`
  notamment déjà présente).
- **Voters** : aucun voter dédié — `PermissionVoter` existant (`is_granted('PERM', 'module.action')`)
  suffit ; le filtrage fin (périmètre établissement) est fait **explicitement dans les processors**
  (§0.5), pas par un voter générique (cohérent avec l'absence de `PerimetreComptaExtension` constatée
  dans le module hôte).
- **Cloisonnement — 3 gardes explicites, tous échec fermé** :
  1. `businessProfile` doit être couvert par l'établissement actif (`ContexteEtablissement`) → 404 sinon
     (§0.5).
  2. `journal`/comptes/`vatRate` référencés doivent appartenir **au même** `businessProfile` que celui
     résolu au point 1 → 422 sinon.
  3. `ExpenseAccountMapping` (lecture/écriture) : filtré par `businessProfile` exact dans les requêtes
     (filtre `SearchFilter` déjà déclaratif) — **mais** un utilisateur ne doit pouvoir créer/modifier un
     mapping que pour un profil qu'il couvre : `ExpenseAccountMappingProcessor` (ou une simple règle
     dans un `EventListener`/`ProcessorInterface` dédié, même patron que ci-dessus) revérifie
     `couvre($etablissementActif)` avant tout `persist`/`flush` — **pas seulement** un filtre de lecture,
     cohérent avec l'invariant « écriture bornée, périmètre dans la requête » (noyau commun #3).
- **Aucun secret** manipulé par ce lot (contrairement à FIN-0) — pas de chiffrement à prévoir ici.

---

## 4. Migrations

Deux migrations additives, timestamps après la dernière migration existante du dépôt
(`Version20260819120500`) :

- **`Version20260819140000`** — `ALTER TABLE compta_ligne_ecriture ADD counterparty_type VARCHAR(32)
  DEFAULT NULL, ADD counterparty_id BINARY(16) DEFAULT NULL, ADD counterparty_label VARCHAR(255) DEFAULT
  NULL`, `ADD INDEX idx_ligne_ecriture_counterparty (counterparty_id)` ; `ALTER TABLE
  compta_lettrage_ecriture ADD reconciliation_code VARCHAR(36) DEFAULT NULL`, `ADD INDEX
  idx_lettrage_reconciliation_code (reconciliation_code)`. **Aucune contrainte `NOT NULL`, aucune
  suppression, aucune donnée existante affectée** (les lignes/lettrages déjà en base héritent de `NULL`
  sur les 4 colonnes — CA-7).
- **`Version20260819140100`** — `CREATE TABLE compta_expense_account_mapping` (id BINARY(16) PK,
  business_profile_id BINARY(16) NOT NULL FK → `compta_profil_exploitant`, expense_nature_code
  VARCHAR(64) NOT NULL, expense_account_id BINARY(16) NOT NULL FK → `compta_compte_comptable`,
  deductible_vat_rate_id BINARY(16) NOT NULL FK → `compta_taux_tva`, active TINYINT(1) DEFAULT 1 NOT
  NULL) + `UNIQUE INDEX uniq_expense_mapping_profil_nature (business_profile_id, expense_nature_code)`
  + index sur les 2 autres FK.

**Down** : les deux migrations sont réversibles (`DROP COLUMN`/`DROP TABLE`), rejouables (constitution
§7).

---

## 5. Tests

| Test | Type | Couvre |
|---|---|---|
| `SaisirEcritureManuelleTest::testCreationEquilibreeScelleeCommeToutAutreEcriture` | Fonctionnel API | CA-1 : écriture manuelle créée, `empreinte`/`signature` non vides (scellée), visible dans `GET /api/ecriture_comptables` et par `VerifierChaineEcritureProcessor` comme n'importe quelle autre écriture |
| `SaisirEcritureManuelleTest::testEcritureDesequilibreeRejetee422` | Fonctionnel API | CA-2 : aucune `EcritureComptable`/`LigneEcriture` persistée après un 422 (vérifié par comptage avant/après) |
| `SaisirEcritureManuelleTest::testPeriodeClotureeRejetee409` | Fonctionnel API | RG-CLOTURE-10, cas limite §7 spec |
| `SaisirEcritureManuelleTest::testLigneCompteInactifRejetee422` | Fonctionnel API | §4.1 spec (« rejet 422 si une ligne référence un compte inactif ») |
| `SaisirEcritureManuelleTest::testLigneTauxTvaHorsChampReutilise` | Fonctionnel API | §4.1 : une ligne non commerciale référence `TauxTva::LIBELLE_HORS_CHAMP` déjà seedé, pas de nouveau taux créé |
| **`CloisonnementSaisieManuelleTest::testProfilHorsPerimetreRefuse404`** | Fonctionnel API | §0.5 — un utilisateur affecté uniquement à l'établissement B, appelant `POST /compta/journal-entries/manual` avec le `businessProfile` de l'établissement A → **404**, aucune écriture créée (test de cloisonnement explicitement demandé par la mission) |
| `CloisonnementSaisieManuelleTest::testCompteAutreProfilRefuse422` | Fonctionnel API | §0.5 point 2 — compte d'un autre profil injecté dans `lines[].account` → 422 |
| `ExpenseAccountMappingGuardTest::testMappingIncompletBloqueSansBloquerLaSaisieAppelante` | Unit | CA-3 : `anomalies()` non vide si mapping absent/inactif — **le test vérifie explicitement qu'aucune exception n'est levée**, seule une liste d'anomalies est retournée (dégradation propre, cohérent `MappingComptableGuardTest` existant) |
| `ExpenseAccountMappingApiTest` | Fonctionnel API | CRUD, contrainte unique `(businessProfile, expenseNatureCode)`, filtre `active` |
| `CounterpartyLigneEcritureTest` | Unit | RG-M6-13 : une ligne sans tiers reste valide (pas de contrainte bloquante), une ligne avec tiers porte bien les 3 champs |
| **`ExportFecCompAuxNonRegressionTest`** | Unit (étend `ExportFecAdapterTest` existant) | **CA-4 et CA-7** : (a) une ligne **avec** `counterparty*` renseigné produit `CompAuxNum`/`CompAuxLib` peuplés dans la sortie FEC ; (b) une ligne **sans** tiers (y compris une écriture historique déjà scellée avant ce lot, rejouée en fixture) produit exactement `''`/`''` — **octet à octet identique** à la sortie d'avant ce lot pour ce cas, 18 colonnes toujours, même séparateur tabulation, mêmes en-têtes `ExportFecAdapter::CHAMPS` |
| `LettrerGroupeTest::testDeuxLignesMemeMontantMemeReconciliationCode` | Fonctionnel API | CA-5 : ligne facture fournisseur crédit 500 € + ligne paiement débit 500 € → 2 `LettrageEcriture` créées, même `reconciliationCode` non nul |
| `LettrerGroupeTest::testGroupeDesequilibreRejete422` | Fonctionnel API | CA-6 |
| `LettrerGroupeTest::testMoinsDeDeuxLignesRejete422` | Unit/Fonctionnel | §4.4 « exige au moins 2 lignes » |
| `LettrerGroupeTest::testLigneNonScelleeRejetee409` | Unit/Fonctionnel | même garde que `lettrer()` existant, réutilisée |
| `LettrerGroupeTest::testLigneDejaLettreeRejetee409` | Unit/Fonctionnel | §7 spec, hypothèse ⚠ retenue par ce plan : une ligne déjà lettrée (simple ou groupée) ne peut pas re-rentrer dans un nouveau groupe |
| `LettrageHandlerTest::testLettrerSimpleInchangeNonRegression` | Unit | RG-M6-14 : `lettrer()` produit toujours `reconciliationCode: null`, aucun changement de signature ni de comportement — non-régression explicite sur `ReglementFactureHandler` (M4) |
| `DirectLedgerEntryBuilderTest::testConstruitEtScelleSansFlush` | Unit | §0.4 : l'écriture retournée est scellée (`estScellee() === true`) mais l'appelant doit encore `flush()` (vérifié en interrogeant l'EM : l'entité est `MANAGED` mais pas encore visible par une requête SQL directe hors UoW) |
| `DirectLedgerEntryBuilderTest::testPeriodeClotureeRefusee` | Unit | RG-CLOTURE-10 |
| `DirectLedgerEntryBuilderTest::testDesequilibreRejeteMemeDefensifApresConstruction` | Unit | défense en profondeur §0.4 |
| `RegressionMoteurVentesTest` (exécution des tests `App\Tests\Compta\Api\GenerationEcritureTest`/`ImmuabiliteTest`/`Nf525ChainTest` existants, sans modification) | Non-régression | CA-7 : suite de tests **déjà existante** repassée telle quelle après ce lot — aucune modification attendue de leur résultat |

---

## 6. Tâches (voir tasks-comptabilite-generale.md)

- **T1** — Migration `Version20260819140000` (colonnes `counterparty*`/`reconciliationCode`) + extension
  des entités `LigneEcriture`/`LettrageEcriture` (getters/setters, aucune méthode existante modifiée) +
  tests unitaires d'entité.
- **T2** — `ExportFecAdapter` : peuplement `CompAuxNum`/`CompAuxLib` + `ExportFecCompAuxNonRegressionTest`
  (**écrit avant la modification**, doit passer avant et après pour le cas « sans tiers »).
- **T3** — `PeriodeComptableResolver` (méthode `resoudre()` seule utilisée ce lot) + tests.
- **T4** — `DirectLedgerEntryBuilder` + `DirectLedgerEntryLine` + tests (dépend de T1/T3).
- **T5** — Entité `ExpenseAccountMapping` + migration `Version20260819140100` + `ExpenseAccountMappingGuard`
  + API Platform CRUD + tests.
- **T6** — Permission `compta.record_manual_entry` (fixtures de test) + `SaisirEcritureManuelleHandler`
  + `SaisirEcritureManuelleProcessor` (garde §0.5) + opération API sur `EcritureComptable` + tests
  (dépend de T1, T3, T4).
- **T7** — `LettrageHandler::lettrerGroupe()` + `#[ApiResource]` sur `LettrageEcriture` (GetCollection +
  opération groupée) + `LettrerGroupeProcessor` + tests (dépend de T1).
- **T8** — Revue de cohérence (constitution §8) : rejouer l'intégralité de `App\Tests\Compta\*` existant
  (non-régression), vérifier `GET /health`, vérifier qu'aucun libellé utilisateur n'est en dur dans les
  nouveaux messages d'erreur (i18n — clés `compta.error.*`, cohérent `i18n-traduction.md`).

---

## 7. Risques / à valider

1. **`GenererEcrituresProcessor` (code existant, non modifié par ce lot) résout `profilExploitant`
   depuis le corps de requête sans vérifier qu'il appartient à l'établissement actif** — constat fait en
   préparant ce plan (§0.5). C'est le même patron d'IDOR cross-tenant déjà corrigé ailleurs dans le
   dépôt. **Recommandation forte** : ouvrir un correctif dédié (hors périmètre strict de FIN-1, même
   fichier/module) avant ou en parallèle de ce lot, pour ne pas laisser cette brèche pendant que le
   module Compta reçoit une attention accrue via FIN-1/FIN-2/FIN-3.
2. **`compta.record_manual_entry` — nom de permission** proposé par analogie, à arbitrer avec M8 avant
   figement (spec §9 point 2).
3. **Re-lettrage d'une ligne déjà lettrée** — comportement **rejeté** retenu par ce plan (§5,
   `testLigneDejaLettreeRejetee409`), mais **non confirmé par une règle explicite du cahier** (spec §7,
   hypothèse ⚠) — à valider avec le métier avant implémentation définitive ; si le métier veut au
   contraire autoriser un re-lettrage (ex. correction d'un rapprochement erroné), la garde serait à
   assouplir sans impact sur le reste du design.
4. **Génération du `reconciliationCode`** — ce plan propose un UUID v4 (`Uuid::v4()->toRfc4122()`,
   colonne `VARCHAR(36)`) plutôt qu'un code métier lisible (ex. `LET-2026-08-0001`) : plus simple, aucune
   séquence à gérer, mais moins lisible pour un Comptable qui consulterait la base directement. À
   confirmer/trancher avec le métier — changement mineur si un format différent est préféré (pas
   d'impact structurel).
5. **Journal « OD » non seedé** — `ComptaFixtures` actuel ne crée que `VTE`/`ENC`/`REG`/`PCA`/`EXT`
   (§ligne 80-84 du fichier). RG-M6-11 attend un « journal dédié opérations diverses, code paramétrable,
   ex. `OD` » — **aucune contrainte technique ne l'impose** (n'importe quel `Journal` existant du profil
   peut être utilisé par `SaisirEcritureManuelleProcessor`), mais il est recommandé d'ajouter un journal
   `OD` aux fixtures de test **et** de le documenter comme configuration initiale recommandée pour un
   profil exploitant qui active la saisie manuelle (point de configuration produit, pas un blocage
   technique).
6. **Table de tests demandée par la mission** — couverte : cloisonnement
   (`CloisonnementSaisieManuelleTest::testProfilHorsPerimetreRefuse404`) et non-régression FEC/NF525
   (`ExportFecCompAuxNonRegressionTest` + rejeu de la suite `App\Tests\Compta\*` existante, §5/§6-T8).

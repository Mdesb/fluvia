# Plan technique — Comptabilité & Régie (`M6` / lot `L4`, module bi-régime)

- **Spec source :** specs/L4-compta/spec-compta.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Dépend de :** socle L0 (specs/L0-socle/plan-socle.md), M1 offre (specs/L1-offre/plan-offre.md), M2
  vente/caisse (specs/L2-vente/plan-vente.md), module Accès L3 (`app/src/Acces/*`) — **réutilisés, non
  redéfinis**
- **Couvre :** US-L4-01 à US-L4-10 · RG-M6-01 à RG-M6-10 (+ alias RG-COMPTA/REGIE/PAYFIP/PCA/TVA/EXPORT/
  EREPORT/NF525/CLOTURE) · CA-1 à CA-15

> **Réutilisation socle L0 (à ne pas dupliquer)** — hiérarchie `App\Organisation\Entity\{Groupe,Region,
> Etablissement,Espace}` (RG-SOCLE-01) ; `PermissionVoter` (attribut `PERM`, sujet `"module.action"`,
> RG-SOCLE-04) ; `ContexteEtablissement` (en-tête `X-Etablissement`, RG-SOCLE-05) ; `App\Audit\Entity\
> EntreeAudit` append-only (RG-SOCLE-07) que le chaînage NF525 **prolonge**, sans le remplacer.
>
> **Réutilisation M1 offre** — `App\Offre\Entity\{Produit,Categorie}` : M6 **exécute** la règle PCA
> (`reglePca`, RG-M1-08) et le mapping comptable (axe `comptable` de `Categorie`, RG-M1-05) **portés**
> par M1, sans les redéfinir. Lecture par référence logique (UUID), pas de FK dure (M1 reste
> autoritaire).
>
> **Réutilisation M2 vente/caisse** — `App\Vente\Entity\{Vente,LigneVente,Paiement,Avoir}` et
> `App\Caisse\Entity\ClotureZ` sont la **source** des écritures (RG-COMPTA-04) ; M6 les **lit** via un
> port de projection dédié (§4), **ne les modifie jamais**. Le port `App\Vente\Port\
> ReferentielReglementInterface` (stub L2) est **implémenté réellement ici** (§3) par un adaptateur
> Doctrine adossé au référentiel `MoyenPaiement` porté par M6. Le chaînage NF525 caisse
> (`App\Vente\Nf525\SignataireOperation`, `HashChainSignataire`) est **réutilisé tel quel** (même port,
> même implémentation par défaut) pour sceller les écritures comptables (§6).
>
> **Réutilisation module Accès (L3)** — `App\Acces\Entity\Passage` (append-only, cumul de passages,
> RG-ACC-04 « FMI ≠ cumul ») est la **source** du fait générateur « reprise PCA au passage » (RG-M6-03)
> et de la fréquentation cumulée du RAD (§4.8 spec). M6 **lit** via un port dédié (§7.3), ne modifie pas
> Accès.

---

## 0. Décision d'architecture — le moteur bi-régime **enfichable** (clé du module)

**Principe non négociable (constitution §4)** : aucune branche `if profil === 'regie_directe'` /
`match ($type) { ... }` éparpillée dans les services métier. Toute variation de régime passe par une
**interface + implémentations enregistrées**, résolues **une seule fois**, à un **seul endroit**.

### 0.1 Port `App\Compta\Regime\RegimeComptableInterface`

```php
interface RegimeComptableInterface
{
    public function cle(): TypeExploitant; // discriminant d'enregistrement (regie_directe|dsp|groupe_prive)

    /** RG-COMPTA-04 — vente validée M2 → lignes d'écriture équilibrées (produit + TVA ventilée) */
    public function genererEcritureVente(VenteProjectionDto $vente, MappingComptable $mapping): EcritureADto;

    /** RG-M2-07 pendant côté avoir — extourne équilibrée, jamais de suppression */
    public function genererEcritureExtourne(EcritureComptable $origine, AvoirProjectionDto $avoir): EcritureADto;

    /** RG-M6-02 — dotation 487 à l'encaissement d'avance */
    public function genererDotationPca(EtalementPca $etalement, int $montantCentimes): EcritureADto;

    /** RG-M6-03 — reprise prorata temporis ou au passage */
    public function genererRepriseP ca(EtalementPca $etalement, int $montantCentimes, string $fait): EcritureADto;

    /** RG-M6-10 — encaissement/versement de régie */
    public function genererEcritureRegie(BordereauVersement $bordereau): EcritureADto;

    /** RG-EXPORT-07 — formats proposés par ce régime (masque les non pertinents) */
    public function formatsExportDisponibles(): array; // FormatExport[]

    /** journal cible par nature d'opération (ventes/encaissements/régie/PCA-OD/extourne) */
    public function journalPour(string $natureOperation): Journal;

    /** point EXPERT #1 (§8) — compte(s) de rattachement 487, paramétrable par régime */
    public function compteAttente487(?QualificationEquipement $qualif): CompteComptable;
}
```

- **Enregistrement** : chaque implémentation est taguée `#[AutoconfigureTag('compta.regime_comptable')]`
  et expose `cle(): TypeExploitant`. `App\Compta\Regime\RegimeComptableResolver` construit une
  **map `[cle => service]`** au démarrage (itérateur taggé Symfony, **pas de `switch`**) et expose
  `pour(ProfilExploitant $profil): RegimeComptableInterface`. **C'est le seul point de lecture du
  discriminant `ProfilExploitant::type`** dans tout le module — toute la logique métier en aval reçoit
  déjà l'implémentation résolue (injection par service, jamais par condition).

### 0.2 Implémentations

| Implémentation | `cle()` | Référentiel | Spécificités enfichables |
|---|---|---|---|
| `RegimeRegieDirecte` | `regie_directe` | M57 **ou** M4 SPIC | délègue le choix M57/M4 **ligne à ligne** à `SelecteurReferentielPublic` (sous-stratégie injectée, lit `QualificationEquipement` de l'Espace concerné — **seul** endroit qui teste SPIC/SPA, point EXPERT #1) ; formats = `{PES_V2_Helios, EtatRegie}` ; `compteAttente487()` actif seulement si `ProfilExploitant.parametres.pcaActif = true` (point EXPERT #3) |
| `RegimeDspPcg` | `dsp` | PCG | formats = `{FEC, CIEL, EBP, Sage, Cegid}` ; PCA actif par défaut ; expose un point d'extension `calculerRedevance()` **non implémenté** (RAD, §7.6, hors périmètre L4) |
| `RegimeGroupePrive` | `groupe_prive` | PCG | même base que `RegimeDspPcg` (composition, pas héritage dupliqué : `RegimeGroupePrive` **décore** `RegimeDspPcg` et **rend obligatoires** les axes analytiques site/activité/financeur sur chaque ligne, RG-M6-04/§4.11) ; expose un point d'extension `consoliderAgregats()` **non implémenté** (hors périmètre L4) |

- **Aucune de ces classes ne connaît le stockage** (Doctrine) ni la génération de séquence NF525 : elles
  ne produisent que des **DTO** (`EcritureADto`, lignes avec compte/sens/montant/tauxTva/axes) — la
  persistance, l'équilibrage et le scellement sont **communs** (`GenerateurEcrituresHandler`, §5).
- **Les 6 points ⚠ EXPERT (§8)** sont tous des **paramètres lus par ces classes**, jamais des
  branches nouvelles : `ProfilExploitant.parametres` (value object embarqué, voir §1.1) porte
  `pcaActif`, `qualificationParDefaut`, `tauxReduitTvaActif`, `nf525PerimetreRegie`,
  `genereTitreRegularisationPes`. Changer un arbitrage = changer une valeur de configuration, jamais le
  code des `Regime*`.

---

## 1. Entités & schéma

Namespace : **`App\Compta\Entity\*`**. `id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`).
`declare(strict_types=1)` partout. Noms métier en français.

> **Montants = entiers en centimes** (`integer`, colonnes suffixées `Centimes`), pour éviter les
> flottants et les erreurs d'arrondi sur des cumuls d'écritures (contrainte de l'énoncé). **Frontière de
> conversion** : M1/M2 exposent des `decimal(10,2)` (chaîne) ; la conversion `decimal → centimes` se
> fait **exclusivement** dans l'adaptateur de projection (§4, `bcmul(*100)` + arrondi bancaire), jamais
> dans le domaine Compta lui-même.

### 1.1 Profil & référentiel comptable

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **ProfilExploitant** | id | `uuid` | non | PK | RG-M6-01 |
| | type | `string(16)` enum `TypeExploitant` {regie_directe, dsp, groupe_prive} | non | pilote tout le module (§0) | — |
| | referentielComptable | `string(8)` enum `ReferentielComptable` {M57, M4_SPIC, PCG} | non | **verrouillé** après 1ʳᵉ clôture (garde applicative, CA-3) | RG-COMPTA-01 |
| | siren | `string(9)` | non | format contrôlé (Assert) | RG-M6-07/08 — clé de regroupement multi-établissements (§1.1 note) |
| | etablissementPrincipal | — | non | FK, **unique** (`OneToOne`) | `ManyToOne`/`OneToOne` → `Etablissement` (socle) — établissement porteur |
| | etablissementsRattaches | — | — | table jointure, ⊇ {principal} | `ManyToMany` → `Etablissement` — périmètre du plan de comptes/journal partagés |
| | verrouille | `boolean` | non | défaut false, passe à true à la 1ʳᵉ clôture | RG-COMPTA-01 |
| | parametres | `json` (value object embarqué `ParametresRegime`) | non | voir §8 (6 points EXPERT) | `{pcaActif:bool, qualificationParDefaut:enum, tauxReduitTvaActif:bool, nf525PerimetreRegie:bool, genereTitreRegularisationPes:bool}` |
| | creeLe / modifieLe | `datetime_immutable` | non | timestampable | — |
| **QualificationEquipement** | id | `uuid` | non | PK | décision actée « par équipement » |
| | espace | — | non | FK, **unique** (1 par Espace éligible) | `OneToOne` → `Espace` (socle) |
| | qualification | `string(4)` enum `Qualification` {SPIC, SPA} | non | requis si régie directe | ⚠ point EXPERT #1 |
| **CompteComptable** | id | `uuid` | non | PK | US-L4-01 |
| | profilExploitant | — | non | FK | `ManyToOne` → `ProfilExploitant` |
| | numero | `string(16)` | non | **unique `(profilExploitant, numero)`** | plan de comptes M57/M4/PCG |
| | libelle | `string(160)` | non | — | — |
| | sens | `string(8)` enum `SensCompte` {debit, credit} | non | — | — |
| | actif | `boolean` | non | défaut true | désactivable, non supprimable si référencé |
| **Journal** | id | `uuid` | non | PK | RG-COMPTA-04 |
| | profilExploitant | — | non | FK | `ManyToOne` → `ProfilExploitant` |
| | code | `string(8)` | non | **unique `(profilExploitant, code)`** | `VTE, ENC, REG, PCA, EXT` (par défaut, seedés) |
| | libelle | `string(120)` | non | — | ventes / encaissements / régie / PCA-OD / extourne |
| **TauxTva** | id | `uuid` | non | PK | RG-TVA-06 |
| | profilExploitant | — | non | FK | `ManyToOne` → `ProfilExploitant` |
| | taux | `decimal(5,2)` | non | ex. 20.00, 10.00, 5.50, 2.10 | — |
| | libelle | `string(80)` | non | — | — |
| | actif | `boolean` | non | défaut true sauf **taux réduit 2025** = **false** par défaut | ⚠ point EXPERT #2, gate `parametres.tauxReduitTvaActif` |
| **MappingComptable** | id | `uuid` | non | PK | RG-M6-01 |
| | profilExploitant | — | non | FK | `ManyToOne` → `ProfilExploitant` |
| | categorie | `uuid` | non | ref logique **Categorie(axe=comptable)** M1 | **unique `(profilExploitant, categorie)`** |
| | compteProduit | — | non | FK | `ManyToOne` → `CompteComptable` (sens=credit) |
| | tauxTva | — | non | FK | `ManyToOne` → `TauxTva` (doit être `actif`) |
| | *(garde)* | — | — | — | mapping incomplet (catégorie vendue sans ligne ici) **bloque** la génération d'écriture (CA-2) |

> **Note rattachement** — `ProfilExploitant` est porté par un `Etablissement` **principal** (cohérent
> avec le pattern M1/M2, RG-SOCLE-01) mais son périmètre comptable réel (plan de comptes, journaux,
> TVA, e-reporting SIREN unique RG-M6-07) couvre `etablissementsRattaches`. Cela évite d'ajouter un
> niveau hiérarchique « Exploitant » au socle tout en respectant « un seul flux agrégé au SIREN ».
> **⚠ HYPOTHÈSE architecture** (non explicite dans les sources) — voir §9.

### 1.2 Journal & écritures (partie double)

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **PeriodeComptable** | id | `uuid` | non | PK | cycle de vie §4.10 spec |
| | profilExploitant | — | non | FK | `ManyToOne` |
| | dateDebut / dateFin | `date_immutable` | non | contiguës sans trou (garde applicative) | — |
| | statut | `string(10)` enum `StatutPeriode` {ouverte, cloturee} | non | défaut `ouverte`, irréversible | RG-CLOTURE-10 |
| | etatCloture | `json` | oui | produits/TVA/encaissements/PCA (archivé) | rempli à la clôture |
| **EcritureComptable** | id | `uuid` | non | PK, append-only | RG-M6-04 |
| | profilExploitant | — | non | FK | `ManyToOne` |
| | journal | — | non | FK | `ManyToOne` → `Journal` |
| | periode | — | non | FK | `ManyToOne` → `PeriodeComptable`, bornée à l'exercice |
| | dateEcriture | `date_immutable` | non | — | — |
| | statut | `string(12)` enum `StatutEcriture` {provisoire, controlee, validee, exportee} | non | défaut `provisoire` | cycle de vie §4.10 |
| | venteOrigine | `uuid` | oui | ref logique **Vente (M2)**, requis si générée par vente | traçabilité NF525 (CA-7) |
| | pieceExtourneDe | — | oui | FK self | `ManyToOne` → `EcritureComptable` — seule voie de correction |
| | numeroSequence | `bigint` | non | **strictement croissant sans trou**, unique `(profilExploitant, journal, numeroSequence)` | chaînage NF525 (§6) |
| | empreinte / empreintePrecedente | `string(128)` | non/oui | maillon N←N-1, null = génésis | réutilise `HashChainSignataire` (M2) |
| | signature | `string(512)` | non | — | idem |
| | lignes | — | — | cascade persist, orphanRemoval | `OneToMany` → `LigneEcriture` |
| | **Immuabilité** | — | — | — | append-only : listener `preUpdate`/`preRemove` → exception (réutilise le pattern M2 §2) |
| **LigneEcriture** | id | `uuid` | non | PK | RG-M6-04 |
| | ecriture | — | non | FK | `ManyToOne` → `EcritureComptable` (inversedBy `lignes`) |
| | compte | — | non | FK | `ManyToOne` → `CompteComptable` |
| | debitCentimes | `integer` | non | `≥ 0`, exclusif de credit (check applicatif) | — |
| | creditCentimes | `integer` | non | `≥ 0`, exclusif de debit | équilibre écriture = Σdébit = Σcrédit (CA-7) |
| | tauxTva | — | non | FK | `ManyToOne` → `TauxTva` — **aucune ligne sans taux** (RG-M6-04) |
| | axeSite | `uuid` | oui | ref logique Etablissement/Espace | requis si `profilExploitant.type = groupe_prive` (RG-M6-04/§4.11) |
| | axeActivite | `string(64)` | oui | — | — |
| | axeFinanceur | `string(64)` | oui | — | — |
| **LettrageEcriture** | id, ligne, dateLettrage, auteur | `uuid`,FK,`date_immutable`,FK | non | — | rapprochement recette/mode/versement (§4.2 spec) |

### 1.3 PCA — compte 487

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **EtalementPca** | id | `uuid` | non | PK | RG-M6-02/03 |
| | profilExploitant | — | non | FK | `ManyToOne` |
| | produit | `uuid` | non | ref logique **Produit (M1)** | — |
| | venteOrigine | `uuid` | non | ref logique **Vente (M2)** | traçabilité |
| | nature | `string(16)` enum `NaturePca` {a_etaler, a_la_consommation} | non | hérité de `Produit.reglePca` (M1) | RG-PCA-05 |
| | methode | `string(16)` enum `MethodePca` {prorata_temporis, au_passage} | non | paramétrable par type produit | ⚠ HYPOTHÈSE (§9) |
| | periodeServiceDebut / Fin | `date_immutable` | oui | requis si prorata | abonnement |
| | compteReport | — | non | FK | `ManyToOne` → `CompteComptable` (487, résolu via `compteAttente487()`, §0) |
| | montantReporteCentimes | `integer` | non | `≥ 0` | montant initial crédité au 487 |
| | resteAServirCentimes | `integer` | non | `≥ 0`, décroît à chaque reprise | rapprochable à tout instant (US-L4-05) |
| | soldeResiduelTraite | `boolean` | non | défaut false | ⚠ point ouvert traitement solde résiduel expiration (§9) |
| **MouvementPca** | id | `uuid` | non | PK, append-only | dotation/reprise |
| | etalement | — | non | FK | `ManyToOne` → `EtalementPca` |
| | type | `string(10)` enum {dotation, reprise} | non | — | — |
| | dateMouvement | `date_immutable` | non | — | — |
| | montantCentimes | `integer` | non | `> 0` | — |
| | faitGenerateur | `string(24)` enum {encaissement, periode, passage} | non | — | RG-M6-03 |
| | passageOrigine | `uuid` | oui | ref logique **Passage (Accès L3)**, requis si `faitGenerateur=passage` | §7.3 |
| | ecritureLiee | — | non | FK | `ManyToOne` → `EcritureComptable` |

### 1.4 Régie de recettes & PayFiP

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **MoyenPaiement** | id | `uuid` | non | PK | **référentiel réel M6** (RG-M2-02) |
| | code | `string(32)` | non | **unique** | consommé par `App\Vente\Port\ReferentielReglementInterface` (§3) |
| | libelle | `string(80)` | non | — | — |
| | autoriseRendu | `boolean` | non | défaut false, true pour espèces | RG-M2-05 |
| | exigeReference | `boolean` | non | défaut false, true pour CB | — |
| | autoriseDiffere | `boolean` | non | défaut false | — |
| | actif | `boolean` | non | défaut true | désactivable |
| **RegieRecettes** | id | `uuid` | non | PK | RG-REGIE-02 |
| | profilExploitant | — | non | FK | `ManyToOne` |
| | libelle, acteNomination | `string(160)`, `string(255)` | non/oui | référence de l'arrêté | — |
| | modesAutorises | `json` set de codes `MoyenPaiement` | non | ⊆ référentiel actif | RG-M6-10 |
| | plafondEncaisseCentimes | `integer` | non | `> 0` | déclenche alerte au dépassement |
| | soldeEncaisseCentimes | `integer` | non | maj temps réel, `≥ 0` | bloque tant que non versé au-delà du plafond |
| | periodiciteVersement | `string(16)` enum {quotidien,hebdo,mensuel} | non | — | — |
| **BordereauVersement** | id | `uuid` | non | PK | RG-REGIE-02 |
| | regie | — | non | FK | `ManyToOne` → `RegieRecettes` |
| | dateVersement | `date_immutable` | non | — | remise au comptable public |
| | montantCentimes | `integer` | non | `> 0` | décrémente `soldeEncaisseCentimes` |
| | justificatifs | `json` (liste de références de fichiers) | oui | — | pièces à l'appui |
| | ecritureGeneree | — | oui | FK | `ManyToOne` → `EcritureComptable` (RG-M6-10 → `genererEcritureRegie`) |
| **BordereauPayFiP** | id | `uuid` | non | PK | RG-PAYFIP-03 |
| | venteOrigine | `uuid` | non | ref logique Vente (M2) | — |
| | referenceTransaction | `string(64)` | non | requis | stockée côté Vente (via port, §7.4) |
| | statutRetour | `string(12)` enum `StatutPayFiP` {ok, echec, annule, en_attente} | non | défaut `en_attente` | maj statut Vente |
| | venteRapprochee | `boolean` | non | défaut false | rapprochement auto |
| | nbTentativesRejeu | `integer` | non | défaut 0 | ⚠ HYPOTHÈSE délai/plafond de rejeu (§9) |
| | dateHeure | `datetime_immutable` | non | — | journalisation, contrôle |

### 1.5 Exports, e-reporting, RAD

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **ExportComptable** | id | `uuid` | non | PK | RG-EXPORT-07 |
| | profilExploitant | — | non | FK | `ManyToOne` |
| | format | `string(20)` enum `FormatExport` {PES_V2_Helios, EtatRegie, FEC, CIEL, EBP, Sage, Cegid} | non | **doit ∈ `regime.formatsExportDisponibles()`** (garde) | RG-EXPORT-07 |
| | periodeDebut / Fin | `date_immutable` | non | bornée exercice | écritures **validées** uniquement |
| | planifie | `boolean` | non | défaut false | + `frequence` (`string`, nullable) |
| | destinataire | `string(160)` | oui | comptable public / délégant / expert-comptable | — |
| | statut | `string(16)` enum `StatutExport` {genere, bloque_anomalies, transmis} | non | défaut `genere` | — |
| | anomalies | `json` | oui | liste si `bloque_anomalies` | — |
| | genereTitreRegularisation | `boolean` | non | défaut = `parametres.genereTitreRegularisationPes` | ⚠ point EXPERT #6 |
| | fichierRef | `string(255)` | oui | chemin/objet stocké | téléchargement |
| **DeclarationEReporting** | id | `uuid` | non | PK | RG-EREPORT-08 |
| | profilExploitant | — | non | FK | `ManyToOne` (regroupe tous les `etablissementsRattaches` du SIREN) |
| | periodeDebut / Fin | `date_immutable` | non | agrégat jour × taux | — |
| | siren | `string(9)` | non | RG-M6-07 | recopié (immuable si transmis) |
| | agregatParJourTaux | `json` (liste `{jour, tauxTvaId, baseHTCentimes, tvaCentimes}`) | non | RG-M6-08 | — |
| | statutEnvoi | `string(10)` enum `StatutEnvoi` {prepare, transmis, rejete} | non | défaut `prepare` | tracé |
| **VenteImpayeeRegie** | id | `uuid` | non | PK | RG-M6-09 |
| | venteOrigine | `uuid` | non | ref logique Vente (M2), **unique** | anti-double-comptabilisation |
| | motif | `string(255)` | non | — | — |
| | dateMarquage | `date_immutable` | non | — | exclusion/signalement dans l'agrégat |
| **FactureB2G** | id | `uuid` | non | PK | Chorus Pro |
| | clientRef | `uuid` | non | ref logique Client (M4) | — |
| | numeroEngagement, serviceExecutant | `string(64)`, `string(120)` | oui | ⚠ HYPOTHÈSE format exact (§9) | port `ChorusProInterface` (stub) |
| | statutEnvoi | `string(10)` enum `StatutEnvoi` | non | défaut `prepare` | — |
| **RAD** *(point d'extension, hors périmètre L4)* | id, profilExploitant, exercice | `uuid`,FK,FK `PeriodeComptable` | non | 1 par exercice | écran M6-08, **squelette non implémenté** |
| **Redevance** *(point d'extension)* | id, rad, formuleContractuelle, assiette, montantCentimes | `uuid`,FK,`text`,`integer`,`integer` | non | reproductible | **squelette non implémenté** |

> Rattachement multi-entités : toute entité racine porte, directement ou via `ProfilExploitant`, un lien
> vers `Etablissement` du socle (RG-SOCLE-01) et suit `ContexteEtablissement` (RG-SOCLE-05). Les lignes
> d'écriture portent en plus les axes analytiques site/activité/financeur pour la remontée
> site→région→direction générale (§4.11 spec).

---

## 2. Génération des écritures depuis M2 (RG-COMPTA-04)

`App\Compta\Service\GenerateurEcrituresHandler` — service **idempotent et rejouable** (pas d'événement
requis côté M2, conformément à la consigne « ne pas modifier M2 de façon risquée ») :

1. `App\Compta\Port\ProjectionVenteInterface::ventesValideesNonComptabilisees(ProfilExploitant $profil): iterable<VenteProjectionDto>`
   — interroge en lecture seule `App\Vente\Entity\Vente` (statut `validee`, `etablissement ∈
   etablissementsRattaches`) **non déjà rattachée** à une `EcritureComptable.venteOrigine`. Implémenté
   par `App\Compta\Adapter\ProjectionVenteDoctrineAdapter` (seul point de contact avec les entités M2 ;
   convertit les `decimal` M2 en centimes ; ventile la TVA **ligne à ligne** par `LigneVente` via le
   `MappingComptable` de la `Categorie` (M1) du produit — RG-M6-05, CA-9).
2. Pour chaque vente : `MappingComptableGuard` vérifie que **chaque** catégorie vendue a un mapping
   `actif` avec un taux TVA `actif` → sinon **anomalie bloquante** (CA-2), la vente reste marquée
   « en attente » (retry au prochain passage), **jamais silencieusement ignorée**.
3. `RegimeComptableResolver::pour($profil)->genererEcritureVente($venteDto, $mapping)` produit le DTO
   d'écriture équilibrée (débit compte d'encaissement par moyen de paiement / crédit compte(s) produit +
   TVA collectée par taux).
4. Si une ligne porte un produit `reglePca ≠ aucune` (M1) : au lieu de créditer directement le compte
   produit, le montant crédite le **487** (`EtalementPca` créé/alimenté, `genererDotationPca`) — RG-M6-02.
5. `EcritureComptable` persistée avec `statut=provisoire`, puis **scellée** (§6) → `controlee` après
   contrôle d'équilibre automatique, `validee` sur action Comptable (`compta.valider`, lettrage inclus).
6. Les **avoirs M2** (`Avoir`) suivent le même chemin (`ProjectionVenteInterface::avoirsNonComptabilises`)
   → `genererEcritureExtourne` (contre-passation, jamais de suppression, RG-M2-07/§4.2).
7. Déclenchement : commande CLI planifiée `compta:ecritures:generer` (idempotente, batch) **et** action
   API `POST /compta/ecritures/generer` (Comptable, `compta.valider`) pour un déclenchement à la demande
   — les deux appellent le même handler.

---

## 3. `MoyenPaiement` — référentiel réel consommé par M2

`App\Compta\Adapter\ReferentielReglementDoctrineAdapter implements
App\Vente\Port\ReferentielReglementInterface` (namespace M2, **implémentation** fournie par M6) :

```php
final class ReferentielReglementDoctrineAdapter implements ReferentielReglementInterface
{
    public function moyensDisponibles(): array { /* SELECT MoyenPaiement WHERE actif = true, mappe vers App\Vente\Port\MoyenPaiement */ }
    public function moyen(string $code): ?MoyenPaiement { /* idem, un seul code */ }
}
```

- Wiring : `config/services.yaml` **remplace l'alias** `App\Vente\Port\ReferentielReglementInterface`
  (pointant vers `ReferentielReglementStub` en L2) par `ReferentielReglementDoctrineAdapter` (L4) — seul
  changement de configuration, **aucune modification du code M2**.
- Migration de données `VersionM6_moyens_paiement` : seed le **même jeu par défaut** que le stub L2
  (Espèces, CB, Chèque, Virement, Chèques Vacances/Culture/Loisirs, PMV, Avoir, Différé) pour non-
  régression, puis administrable via `CompteComptable`-like CRUD (`compta.gerer`).
- Le **filtrage** par acte de régie reste porté par `PointDeVente.moyensAutorises` (champ M2 existant,
  administré manuellement en cohérence avec `RegieRecettes.modesAutorises` de M6 — pas de synchronisation
  automatique dans ce lot, noté en risque §9).

---

## 4. Assistant PCA — détail des reprises

- **Prorata temporis (abonnement)** — commande planifiée `compta:pca:reprise-mensuelle` : pour chaque
  `EtalementPca` actif avec `methode=prorata_temporis`, calcule la quote-part de la période écoulée
  (jours calendaires / durée totale × `montantReporteCentimes`), plafonnée à `resteAServirCentimes`,
  génère `MouvementPca(type=reprise, faitGenerateur=periode)` + écriture via `genererRepriseP ca`.
- **Au passage (carte multi-entrées)** — `App\Compta\Port\ProjectionPassageInterface::passagesNonReconcilies(...)`
  implémenté par `App\Compta\Adapter\ProjectionPassageDoctrineAdapter` (lecture seule de `App\Acces\
  Entity\Passage`, résultat `Autorise`, support lié à un produit `carte`) → une reprise **au passage**
  par entrée consommée (montant = `montantReporteCentimes / nbCredite` de la carte, M1) tant que
  `resteAServirCentimes > 0`.
- **Rapprochement** (US-L4-05) : `GET /compta/pca/{id}/rapprochement` restitue
  `resteAServirCentimes` vs somme des `MouvementPca` liés — cohérence contrôlable à tout instant.
- **Activation** : entièrement gouvernée par `ProfilExploitant.parametres.pcaActif` (point EXPERT #3,
  §8) ; si `false`, `GenerateurEcrituresHandler` reconnaît le produit **directement** (pas de 487),
  hypothèse dégradée documentée en cas limite spec §7.

---

## 5. Exports commutés — port et adaptateurs

`App\Compta\Port\ExportComptableInterface` :

```php
interface ExportComptableInterface
{
    public function format(): FormatExport;
    /** @return string contenu du fichier généré, prêt à écrire/télécharger */
    public function generer(ExportComptable $export, iterable $ecrituresValidees): string;
}
```

- **Sélection** : `App\Compta\Export\ExportComptableResolver` (même pattern §0 — itérateur taggé
  `#[AutoconfigureTag('compta.export_adapter')]`, clé = `FormatExport`), **aucun `switch`** dans le
  handler d'export.
- **Contrôle pré-export** (RG-EXPORT-07) : `ControleExportGuard` vérifie équilibre + statut `validee`
  uniquement sur la période bornée ; échec → `statut=bloque_anomalies` + liste (CA-10).
- **Adaptateurs livrés** :
  - `ExportFecAdapter` (**réellement implémenté**) — format FEC légal 18 champs (JournalCode,
    JournalLib, EcritureNum, EcritureDate, CompteNum, CompteLib, CompAuxNum, CompAuxLib, PieceRef,
    PieceDate, EcritureLib, Debit, Credit, EcritureLet, DateLet, ValidDate, Montantdevise, Idevise),
    séparateur tabulation, encodage requis, montants reconvertis centimes → `decimal` texte à la sortie
    uniquement (CA-11). **Testable de bout en bout** (fixture → fichier → assertions colonnes).
  - `ExportEtatRegieAdapter` (réellement implémenté, structuré) — encaissements par mode, versements,
    restes, à partir de `RegieRecettes`/`BordereauVersement`.
  - `ExportPesV2HeliosAdapter` — **squelette** : structure XML PES V2 minimale (en-tête, bloc écritures),
    contenu simplifié documenté « à compléter avec le protocole Trésor exact » (point EXPERT #6 pour le
    titre de régularisation).
  - `ExportCielAdapter`, `ExportEbpAdapter`, `ExportSageAdapter`, `ExportCegidAdapter` — **squelettes**
    (implémentent le port, produisent un CSV minimal documenté « format éditeur à finaliser »),
    suffisants pour que le **masquage par profil** (RG-EXPORT-07, CA-10) et le pipeline de contrôle
    soient testables sans figer un format propriétaire non documenté dans les sources.
- **Masquage par profil** : `RegimeComptableInterface::formatsExportDisponibles()` (§0) filtre les
  formats proposés par l'API (CA-1, CA-10).

---

## 6. NF525 — prolongement du chaînage côté écritures (US-L4-09, CA-13)

- **Réutilisation stricte** du port `App\Vente\Nf525\SignataireOperation` et de l'implémentation
  `HashChainSignataire` (M2, §2 de `plan-vente.md`) — **aucune ré-implémentation cryptographique**.
- `App\Compta\Nf525\ScellementEcritureHandler` appelle `SignataireOperation::scelle()` avec un DTO
  `OperationAScellerDto` construit depuis l'`EcritureComptable` (payload canonique = lignes + montants +
  comptes) ; la **chaîne** est scopée par `(profilExploitant, journal)` (au lieu de `pointDeVente` côté
  caisse) — champs `numeroSequence`/`empreinte`/`empreintePrecedente`/`signature` embarqués directement
  sur `EcritureComptable` (pas de table `OperationScellee` dupliquée, l'écriture est déjà l'unité
  scellée 1:1).
- `verifieChaine()` exposé en lecture `GET /compta/ecritures/verifier-chaine?journal=...` (CA-13, rupture
  détectable).
- **Périmètre régie** — gouverné par `parametres.nf525PerimetreRegie` (point EXPERT #4/#5, §8) : si
  `true` (défaut, décision actée « viser la certification complète »), les écritures de régie sont
  scellées comme les autres ; paramétrable à `false` en attendant l'arbitrage juridique **sans retirer
  le code de scellement**.
- Immuabilité : listener Doctrine `preUpdate`/`preRemove` sur `EcritureComptable`/`LigneEcriture`
  (même pattern que M2) → `OperationInalterableException` réutilisée.

---

## 7. Autres services métier

### 7.1 Régie de recettes (US-L4-02)
`RegieHandler` : `enregistrerEncaissement()` incrémente `soldeEncaisseCentimes` ; garde
`PlafondEncaisseGuard` déclenche une alerte **bloquante** (⚠ HYPOTHÈSE conduite exacte, §9) dès
dépassement tant qu'aucun versement n'est enregistré (CA-4). `enregistrerVersement()` crée un
`BordereauVersement`, décrémente le solde, génère l'écriture via `genererEcritureRegie` (CA-5).

### 7.2 PayFiP (US-L4-03)
Port `App\Compta\Port\PayFipInterface` (`initierPaiement`, `traiterRetour`, `rejouer`) avec adaptateur
`PayFipStubAdapter` (⚠ protocole exact non détaillé dans les sources, point ouvert §9). Webhook
`POST /compta/payfip/retour` (signature à vérifier — placeholder) → `TraiterRetourPayFipHandler` met à
jour `BordereauPayFiP.statutRetour`, rapproche la vente (via `ProjectionVenteInterface` en écriture
**indirecte** : M6 ne modifie pas `Vente` directement — expose plutôt un événement/notification que M2
consomme via un **nouveau** port `App\Vente\Port\NotificationPaiementLigneInterface` implémenté ici,
symétrique de `ReferentielReglementInterface`) — statut vente mis à jour **côté M2** par son propre
processor, M6 ne fait qu'émettre la notification. Rejeu : `POST /compta/payfip/{id}/rejouer`
(`compta.valider`) si `statutRetour=en_attente`.

### 7.3 Fréquentation cumulée (RAD, point d'extension)
`ProjectionPassageDoctrineAdapter` (§4) est le **seul** point de lecture d'Accès ; il n'expose que le
**cumul de passages** (jamais `JaugeFmi`, cf. RG-ACC-04) — garde de nommage explicite dans le DTO
(`CumulPassageDto`, pas `JaugeDto`) pour rendre l'erreur impossible à l'usage.

### 7.4 Marquage « ImpayeRegie » (US-L4-08)
`POST /compta/ventes/{id}/marquer-impayee-regie` crée `VenteImpayeeRegie` ; le générateur e-reporting
(§7.5) **exclut** ces ventes de l'agrégat. ⚠ Pas de contrôle croisé automatique régie↔e-reporting dans
ce lot (point ouvert §9) — recommandé en évolution (rapport d'écart périodique).

### 7.5 e-reporting (US-L4-08)
`GenerateurEReportingHandler::preparer(ProfilExploitant, periode)` agrège les `LigneEcriture` (journal
ventes) des ventes B2C **hors `VenteImpayeeRegie`**, groupées par jour × `TauxTva`, un seul enregistrement
par SIREN (RG-M6-07). Port `App\Compta\Port\PdpInterface::deposer(DeclarationEReporting): StatutEnvoi`
avec `PdpStubAdapter` (⚠ canal PDP non nommé dans les sources, point ouvert §9).

### 7.6 RAD & consolidation groupe — points d'extension seuls
Conformément à l'arbitrage (hors périmètre L4, aucune US) : `RAD`/`Redevance` sont **modélisées** (§1.5)
et `RegimeComptableInterface` prévoit `calculerRedevance()`/`consoliderAgregats()` en **commentaire
d'extension** dans `RegimeDspPcg`/`RegimeGroupePrive`, **non implémentées** (pas d'endpoint API actif au-
delà d'un `GET` retournant « non disponible dans ce lot »). Documenté en risque §9.

---

## 8. Les 6 points ⚠ EXPERT — implémentation paramétrable (rappel de conception)

| # | Point EXPERT | Paramètre porteur | Valeur par défaut | Effet si modifié |
|---|---|---|---|---|
| 1 | Qualification SPIC/SPA par équipement | `QualificationEquipement.qualification` + `ProfilExploitant.parametres.qualificationParDefaut` | `SPA` (M57) | change le compte/référentiel choisi par `SelecteurReferentielPublic`, aucune ligne de code métier modifiée |
| 2 | Taux réduit TVA 2025 | `TauxTva.actif` (ligne dédiée créée mais inactive) | `false` | `MappingComptable` ne peut pointer vers un taux inactif ; activer = mettre `actif=true` |
| 3 | Admissibilité PCA en M57/M4 | `ProfilExploitant.parametres.pcaActif` | `false` en régie directe, `true` en DSP/groupe | bascule dotation 487 vs reconnaissance directe (§4) |
| 4/5 | Procédé cryptographique & périmètre certification NF525 (dont régies) | `SignataireOperation` (port réutilisé M2) + `parametres.nf525PerimetreRegie` | `HashChainSignataire` (HMAC placeholder) ; `nf525PerimetreRegie=true` | changer d'implémentation du port = aucun changement domaine ; régies désactivables sans retirer le code |
| 6 | Titre de recette / PES V2 | `ExportComptable.genereTitreRegularisation` (init depuis `parametres.genereTitreRegularisationPes`) | `false` | active un bloc supplémentaire dans `ExportPesV2HeliosAdapter` |

---

## 9. API (API Platform)

Toutes ressources : `security` via **`PermissionVoter` du socle** → `is_granted('PERM',
'compta.<action>')` (ou `caisse.versement` pour le versement de régie, cf. spec §3). Lecture cadrée par
`ContexteEtablissement` étendu à `ProfilExploitant.etablissementsRattaches`.

| Ressource | Opérations | `security:` | Groupes sérialisation | Type |
|---|---|---|---|---|
| **ProfilExploitant** | GET, POST, PATCH | `compta.lire` / `compta.gerer` | `profil:read/write` | `referentielComptable` **non modifiable** si `verrouille` (custom guard, CA-3) |
| **QualificationEquipement** | GET, POST, PATCH | `compta.lire` / `compta.gerer` | `qualif:read/write` | CRUD |
| **CompteComptable / Journal / TauxTva** | GET, POST, PATCH ; DELETE refusé si référencé | `compta.lire` / `compta.gerer` | `plan:read/write` | CRUD |
| **MappingComptable** | GET, POST, PATCH | `compta.lire` / `compta.gerer` | `mapping:read/write` | garde taux actif |
| **MoyenPaiement** | GET, POST, PATCH | `compta.lire` / `compta.gerer` | `moyen:read/write` | référentiel réel (§3) |
| **Journal/EcritureComptable** | GET coll/item (filtres date/journal/compte) | `compta.lire` | `ecriture:read` | lecture (§4.2 spec) |
| | `POST /compta/ecritures/generer` | `compta.valider` | — | **custom**, déclenche §2 |
| | `POST /compta/ecritures/{id}/lettrer` | `compta.lettrer` | `ecriture:lettrage` | custom |
| | `POST /compta/ecritures/{id}/valider` | `compta.valider` | — | transition provisoire→validee |
| | `POST /compta/ecritures/{id}/extourne` | `compta.valider` | `ecriture:extourne` | seule voie de correction (CA-7) |
| | `GET /compta/ecritures/verifier-chaine` | `compta.lire` | `nf525:read` | custom (CA-13) |
| **EtalementPca / MouvementPca** | GET coll/item | `compta.lire` | `pca:read` | lecture + rapprochement |
| | `POST /compta/pca/{id}/reprise-manuelle` | `compta.gerer` | — | override admin |
| **RegieRecettes** | GET, PATCH (paramétrage) | `compta.lire` / `compta.gerer` | `regie:read/write` | CA-4 |
| | `POST /compta/regies/{id}/versements` | `caisse.versement` | `versement:write` | custom (CA-5) |
| **BordereauPayFiP** | GET coll/item | `compta.lire` | `payfip:read` | — |
| | `POST /compta/payfip/retour` | (webhook, signature dédiée) | — | custom (CA-6) |
| | `POST /compta/payfip/{id}/rejouer` | `compta.valider` | — | custom |
| **ExportComptable** | GET coll/item ; POST (générer) | `compta.lire` / `compta.exporter` | `export:read/write` | formats filtrés par régime (CA-10/CA-11) |
| | `GET /compta/exports/{id}/telecharger` | `compta.exporter` | — | fichier |
| | `POST /compta/exports/{id}/planifier` | `compta.gerer` | — | récurrence |
| **DeclarationEReporting** | GET coll/item ; POST (préparer) | `compta.lire` / `compta.exporter` | `ereport:read/write` | CA-12 |
| | `POST /compta/e-reporting/{id}/transmettre` | `compta.exporter` | — | port PDP |
| **VenteImpayeeRegie** | GET ; POST (marquer) | `compta.lire` / `compta.gerer` | `impaye:read/write` | §7.4 |
| **FactureB2G** | GET, POST, PATCH | `compta.lire` / `compta.gerer` | `b2g:read/write` | Chorus Pro (stub) |
| **RAD / Redevance** | GET seulement | `compta.lire_rad` ⚠ HYPOTHÈSE | `rad:read` | **non implémenté** (§7.6) |
| **PeriodeComptable** | GET coll/item | `compta.lire` | `periode:read` | — |
| | `POST /compta/periodes/{id}/cloturer` | `compta.cloturer` | `periode:cloture` | **custom**, contrôles bloquants (CA-14) |

- **Consolidation multi-niveaux** — `compta.lire_consolide` (⚠ HYPOTHÈSE spec §3) donne accès à
  `GET /compta/consolide?perimetre=region|groupe` — **agrégats déjà exportés/validés uniquement**
  (jamais le journal détaillé), filtré par `ContexteEtablissement` élargi au périmètre demandé.

---

## 10. Sécurité & droits

- **Permissions requises (module `compta`)** : `compta.lire`, `compta.lettrer`, `compta.valider`,
  `compta.exporter`, `compta.cloturer`, `compta.gerer` (surensemble admin), `compta.lire_rad` ⚠
  HYPOTHÈSE, `compta.lire_consolide` ⚠ HYPOTHÈSE. Réutilise `caisse.versement` (module `caisse`, déjà
  entrevu par le tableau Acteurs & droits de la spec — action ajoutée au référentiel `caisse` existant
  de M2, pas un nouveau module).
- **Voter** : **aucun voter nouveau** — réutilise `PermissionVoter` du socle. Séparation des devoirs
  explicite (spec §3) : Comptable ≠ Administrateur (paramétrage plan de comptes/profil) ; Autorité
  délégante limitée à `compta.lire_rad` (jamais le journal) ; DG/Région limitées à
  `compta.lire_consolide` (jamais le journal détaillé hors périmètre).
- **Cadrage établissement** : `ContexteEtablissement` étendu — les entités rattachées à
  `ProfilExploitant` sont visibles si l'établissement actif ∈ `etablissementsRattaches`.
- ⚠ **HYPOTHÈSE** — noms `compta.*` non littéraux dans les sources (comme L1/L2), à figer avec **M8**.

---

## 11. Migrations

- **Migration structurelle** `VersionM6_compta` : tables `compta_profil_exploitant`,
  `compta_qualification_equipement`, `compta_compte_comptable`, `compta_journal`, `compta_taux_tva`,
  `compta_mapping_comptable`, `compta_periode_comptable`, `compta_ecriture_comptable`,
  `compta_ligne_ecriture`, `compta_lettrage_ecriture`, `compta_etalement_pca`, `compta_mouvement_pca`,
  `compta_moyen_paiement`, `compta_regie_recettes`, `compta_bordereau_versement`,
  `compta_bordereau_payfip`, `compta_export_comptable`, `compta_declaration_ereporting`,
  `compta_vente_impayee_regie`, `compta_facture_b2g`, `compta_rad`, `compta_redevance`.
  - Index/contraintes : unique `(profilExploitant, numero)` sur `CompteComptable`, `(profilExploitant,
    code)` sur `Journal`, `(profilExploitant, categorie)` sur `MappingComptable`, `(profilExploitant,
    journal, numeroSequence)` sur `EcritureComptable` (chaînage), `venteOrigine` unique sur
    `VenteImpayeeRegie` ; checks `plafondEncaisseCentimes > 0`, `resteAServirCentimes ≥ 0`,
    `montantCentimes > 0`. FK vers `etablissement`/`espace` (socle) — **suppose migrations socle L0 + M1
    + M2 + Accès L3 jouées d'abord**.
- **Migration de données** `VersionM6_permissions` : `Permission(module='compta', action ∈
  {lire,lettrer,valider,exporter,cloturer,gerer,lire_rad,lire_consolide})` + `Permission(module='caisse',
  action='versement')` (complète le référentiel `caisse` de M2).
- **Migration de données** `VersionM6_moyens_paiement` : seed identique au stub L2 (§3).
- **Migration de données** `VersionM6_plan_comptes_base` : jeu minimal de comptes M57 (411 Redevables,
  4457 TVA collectée, 706x Produits, 487 Produits constatés d'avance…), M4 SPIC (mêmes racines,
  nomenclature M4) et PCG (411, 44571, 706x, 487) — **plans de comptes de démarrage**, à compléter par
  l'Administrateur (`compta.gerer`) ; Journaux par défaut `VTE/ENC/REG/PCA/EXT` ; `TauxTva` {20, 10, 5.5,
  2.1 (taux réduit, `actif=false`)}.
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force` (constitution §7).

---

## 12. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| Sélection profil régie/M57 → plan de comptes, régie, exports publics disponibles, formats privés masqués | API | CA-1, RG-M6-01 |
| Mapping incomplet → génération d'écriture **bloquée**, vente reste possible côté M2 | API + Unit | CA-2, RG-M6-01 |
| Changement référentiel refusé après 1ʳᵉ clôture | API | CA-3, RG-COMPTA-01 |
| Régie {numéraire,CB,PayFiP} : chèque refusé côté M2 (via `ReferentielReglementDoctrineAdapter` + PDV filtré) ; dépassement plafond → alerte bloquante | API + Unit | CA-4, RG-REGIE-02/M6-10 |
| Versement : bordereau daté + justificatifs, solde décroît | API | CA-5 |
| PayFiP OK → réf. stockée, statut payé, rapproché ; retour manquant → rejouable | API (adaptateur mocké) | CA-6, RG-PAYFIP-03 |
| Vente validée → écriture équilibrée, taux+axes par ligne, bon journal/période ; modif après génération impossible, seule extourne possible | API + Unit | CA-7, RG-M6-04/COMPTA-04 |
| Abonnement encaissé d'avance → crédite 487 ; mois écoulé → reprise prorata ; carte → reprise au passage (Accès mocké) ; solde 487 = reste-à-servir | Unit (`RegimeRegieDirecte`/`RegimeDspPcg`) + API | CA-8, RG-M6-02/03 |
| Panier mixte 20%+10% → ventilation ligne à ligne, total TTC = Σ lignes, état TVA par taux sans « indéterminé » | Unit + API | CA-9, RG-M6-05/TVA-06 |
| Profil régie : export ne propose que PES/Hélios+EtatRegie ; écritures déséquilibrées → export bloqué + anomalies | API | CA-10, RG-EXPORT-07 |
| Profil DSP : export FEC 18 champs, écritures validées uniquement, contrôle équilibre avant transmission | API + Unit (`ExportFecAdapter`) | CA-11, RG-EXPORT-07 |
| e-reporting : agrégat unique par SIREN, jour×taux, exclut ventes `ImpayeRegie`, statut tracé | API + Unit | CA-12, RG-M6-07/08/09 |
| Chaînage écritures : chaque écriture signée+chaînée ; rupture simulée détectée ; jeu d'archives vérifiable | Unit (`HashChainSignataire` réutilisé) + API | CA-13, RG-M6-06 |
| Clôture : refusée si journal déséquilibré/versements non soldés ; validée → gel écritures, état récap, tracée | API | CA-14, RG-CLOTURE-10 |
| RAD (hors périmètre) : endpoint retourne "non disponible dans ce lot", n'expose jamais le journal détaillé à l'Autorité délégante | API | CA-15 (documentation du hors-périmètre) |
| **Moteur bi-régime** : `RegimeComptableResolver` retourne la bonne implémentation par `ProfilExploitant.type`, aucune classe domaine ne teste le type directement (test d'architecture — grep négatif `profil->getType() ===` hors `Resolver`/`SelecteurReferentielPublic`) | Unit | §0 (architecture) |
| Qualification SPIC/SPA : `SelecteurReferentielPublic` choisit M4 vs M57 selon `QualificationEquipement`, défaut SPA si non qualifié | Unit | point EXPERT #1 |
| PCA désactivé (`pcaActif=false`) : vente reconnue directement en produit, aucune écriture 487 | Unit | point EXPERT #3, cas limite spec §7 |
| Conversion decimal M2 → centimes sans dérive d'arrondi sur cumul de N lignes | Unit (`ProjectionVenteDoctrineAdapter`) | contrainte montants centimes |
| Cloisonnement : établissement hors `etablissementsRattaches` du profil → 403/absent | API | RG-SOCLE-05 |
| Immuabilité ORM : `preUpdate`/`preRemove` sur `EcritureComptable`/`LigneEcriture` → exception | Unit | §6 |
| Wiring `ReferentielReglementDoctrineAdapter` : moyens M6 visibles depuis M2 sans modification du code M2 | API (intégration L2↔L4) | §3 |

---

## 13. Tâches (voir tasks-compta.md)

T1 enums+ports (RegimeComptableInterface, ExportComptableInterface, ProjectionVenteInterface,
ProjectionPassageInterface, PayFipInterface, PdpInterface) → T2 ProfilExploitant + QualificationEquipement
→ T3 plan de comptes (CompteComptable/Journal/TauxTva/MappingComptable) + seed → T4 MoyenPaiement réel +
wiring adaptateur M2 → T5 moteur bi-régime (3 implémentations + resolver + SelecteurReferentielPublic) →
T6 projection M2 (adaptateur lecture Vente/Avoir/ClotureZ + conversion centimes) → T7 génération
d'écritures + ventilation TVA → T8 chaînage NF525 écritures (réutilise SignataireOperation) → T9
lettrage/contrôle → T10 moteur PCA (dotation + reprises prorata/passage, projection Accès) → T11 régie de
recettes + bordereau versement → T12 PayFiP (port+webhook+rapprochement+rejeu) → T13 exports (port + FEC
réel + PES/Hélios squelette + éditeurs squelettes) → T14 e-reporting + marquage ImpayeRegie + port PDP →
T15 clôture de période (contrôles bloquants, gel, état récap) → T16 RAD/Redevance (points d'extension,
squelettes) → T17 API Platform (ressources, security, sérialisation) → T18 permissions + migrations
données → T19 tests. (ordonnées, cf. fichier tasks.)

---

## 14. Risques / à valider

### ⚠ À VALIDER PAR EXPERT (comptable public / fiscaliste — repris de la spec, structure prête)
1. **Qualification SPIC vs SPA par équipement** — structure prête (`QualificationEquipement` +
   `SelecteurReferentielPublic`), défaut `SPA`/M57, à confirmer équipement par équipement.
2. **Taux réduit TVA 2025** — moteur multi-taux prêt, ligne `TauxTva` créée `actif=false` par défaut.
3. **Admissibilité/schéma du PCA (487) en comptabilité publique** — implémenté, gouverné par
   `parametres.pcaActif` (défaut `false` en régie directe).
4. **Périmètre NF525 pour les régies** — `parametres.nf525PerimetreRegie` (défaut `true`, décision
   actée « viser la certification complète », désactivable sans retirer le code).
5. **Procédé cryptographique NF525** — réutilise le port `SignataireOperation`/`HashChainSignataire` de
   M2 (HMAC placeholder), changement d'implémentation = simple substitution DI.
6. **Articulation titres de recettes / PES V2** — champ `ExportComptable.genereTitreRegularisation`
   paramétrable, `false` par défaut, à activer après arbitrage.

### ⚠ HYPOTHÈSE (fonctionnel/technique, à trancher avec le métier / M7 / M8)
1. **`compta.lire_rad` / `compta.lire_consolide`** — permissions proposées, non nommées dans les
   sources, à figer avec M8.
2. **Rattachement de `ProfilExploitant` à un `etablissementPrincipal` + `etablissementsRattaches`** —
   décision d'architecture non explicite dans les sources, retenue pour satisfaire « un seul flux
   e-reporting agrégé au SIREN » (RG-M6-07) sans ajouter de niveau au socle. À valider si un exploitant
   réel peut effectivement regrouper plusieurs établissements du socle sous un même SIREN.
3. **Grille exhaustive type de produit → méthode PCA** (prorata vs consommation) au-delà des deux cas
   cités — portée par `EtalementPca.methode`, valeurs par défaut posées, grille à compléter.
4. **Solde de 487 résiduel à l'expiration** — champ `soldeResiduelTraite` posé, traitement (perte en
   produit vs reprise en charge) non tranché — partagé avec M1.
5. **Protocole technique PayFiP** (rejeu, doublons) — port + stub posés, `nbTentativesRejeu` sans règle
   de plafond/délai figée.
6. **Canal technique e-reporting (PDP)** — port `PdpInterface` + stub, choix du PDP non fait.
7. **Format exact des factures B2G Chorus Pro** — champs `numeroEngagement`/`serviceExecutant` posés
   sans validation de format.
8. **RAD/redevances DSP et consolidation groupe** — **hors périmètre L4** (aucune US) ; seuls des points
   d'extension (`RAD`, `Redevance`, méthodes `calculerRedevance()`/`consoliderAgregats()` non
   implémentées) sont posés dans le moteur bi-régime pour ne pas bloquer un futur lot dédié.
9. **Comportement de la vente M2 pendant qu'une écriture est bloquée** (mapping incomplet) — la vente
   reste possible côté M2 (non modifié), l'écriture reste « en attente » côté M6 jusqu'à correction du
   mapping ; pas de blocage rétroactif de la vente.
10. **Conduite exacte au dépassement du plafond d'encaisse** — alerte bloquante retenue au niveau du
    versement (`PlafondEncaisseGuard`) ; blocage strict des nouveaux encaissements en régie vs alerte
    non bloquante pour la vente elle-même **non tranché**, à confirmer côté M2 (déclenchement UI).
11. **Contrôle croisé automatique `ImpayeRegie` ↔ e-reporting** — non implémenté dans ce lot (marquage
    manuel/applicatif uniquement), recommandé en évolution (rapport d'écart périodique).
12. **Synchronisation `RegieRecettes.modesAutorises` → `PointDeVente.moyensAutorises` (M2)** — reste un
    paramétrage manuel cohérent, pas de synchronisation automatique dans ce lot (aucune modification de
    M2 au-delà du wiring d'adaptateur, §3).
13. **Notification de rapprochement PayFiP vers M2** — port symétrique
    `NotificationPaiementLigneInterface` proposé côté M2 pour que M6 ne modifie jamais directement une
    `Vente` ; **nécessite une petite extension de M2** (ajout d'un port, pas de logique existante
    touchée) — à confirmer que cela reste dans l'esprit « M6 ne modifie pas M2 » de la consigne.

---

## 15. Divergences d'implémentation (build réel, à date)

Le build livré respecte l'architecture bi-régime enfichable et l'ensemble des invariants (partie
double, chaînage NF525, cloisonnement, permissions) mais simplifie pragmatiquement certains points
de détail du plan pour tenir l'effort du lot. Aucune de ces simplifications ne remet en cause les
principes non négociables (§0) ; elles sont documentées ici pour un prochain incrément.

1. **NF525 côté écritures non littéralement partagé avec M2** — `App\Compta\Nf525\
   ScellementEcritureHandler` réimplémente le même procédé (sha256 chaîné + HMAC placeholder,
   canonicalisation déterministe) que `HashChainSignataire` (M2), mais **sans réutiliser** le port
   `App\Vente\Nf525\SignataireOperation` ni l'entité `OperationScellee` : ceux-ci sont structurellement
   couplés à `App\Caisse\Entity\PointDeVente`, incompatible avec un scellement scopé
   `(profilExploitant, journal)` — et modifier M2 pour généraliser le port était hors consigne. Les
   champs `numeroSequence/empreinte/empreintePrecedente/signature` sont bien embarqués directement sur
   `EcritureComptable`, conformément au §6.
2. **Interface `RegimeComptableInterface` simplifiée** — les méthodes `genererDotationPca()` /
   `genererRepriseP ca()` du §0.1 n'ont pas été reprises telles quelles ; la dotation/reprise PCA est
   orchestrée par `GenerateurEcrituresHandler`/`RepriseMensuellePcaHandler`/`RepriseAuPassageHandler` à
   partir des primitives du régime (`compteAttente487`, `journalPour`). L'invariant « seul le Resolver
   lit le discriminant » est préservé (test d'architecture dédié).
3. **Compte de contrepartie encaissement unique par vente** — le débit de l'écriture de vente porte sur
   un compte d'encaissement unique par profil (511/411 selon régime), sans ventilation par moyen de
   paiement (pas de sous-compte par `MoyenPaiement`). La TVA reste ventilée ligne à ligne par taux
   (RG-M6-05 respecté) ; seule la contrepartie débitrice est simplifiée.
4. **Compte TVA collectée unique par profil** — un seul compte 4457* porte toute la TVA collectée,
   quel que soit le taux ; la ventilation par taux (RG-TVA-06/§4.4) est garantie par
   `LigneEcriture::tauxTva`, pas par un plan de sous-comptes 44571/44572 par taux.
5. **Compte 487 unique par profil** — `compteAttente487()` retourne un compte 487 unique (non
   ventilé par `QualificationEquipement`) ; `SelecteurReferentielPublic` reste le seul point testé pour
   le choix M4/M57 (point EXPERT #1) mais n'influence pas encore le numéro de compte 487 lui-même.
6. **Reprise PCA prorata temporis** — quote-part mensuelle simplifiée (montant total / nombre de mois
   arrondis, plafonnée au reste-à-servir), sans gestion fine des jours calendaires exacts par appel.
7. **PayFiP / notification vers M2** — `App\Compta\Service\TraiterRetourPayFipHandler` trace le
   rapprochement côté M6 (`BordereauPayFiP.venteRapprochee`) mais **ne modifie jamais** `App\Vente\
   Entity\Vente` : le port symétrique `NotificationPaiementLigneInterface` évoqué en risque §14.13
   n'a pas été ajouté à M2 dans ce lot (aucune extension de M2, même minime).
8. **Extourne = contre-passation totale** — `POST /compta/ecritures/{id}/extourne` inverse
   intégralement l'écriture d'origine (pas d'extourne partielle proportionnelle à un avoir partiel) ;
   suffisant pour les avoirs génériques et testé, mais à affiner si des avoirs partiels ligne-à-ligne
   doivent être comptabilisés différemment.
9. **Migrations de données** — seules les permissions `compta.*`/`caisse.versement` et le référentiel
   `MoyenPaiement` (identique au stub L2) sont seedés par migration (`Version20260814231500/231600`,
   idempotentes). Le plan de comptes/journaux/taux TVA de base sont créés par
   `App\Compta\DataFixtures\ComptaFixtures` (dev/démo/tests) et par l'administrateur via l'API
   (`compta.gerer`) une fois le `ProfilExploitant` créé — cohérent avec RG-M6-01 (« rien n'est saisi
   indépendamment du profil ») : il n'existe pas de plan de comptes générique hors profil à seeder en
   migration structurelle.
10. **FactureB2G / Chorus Pro** — entité et port stub posés (§7 du plan), sans processor API dédié
    au-delà du CRUD générique ; pas de test spécifique (hors périmètre prioritaire des 10 US-L4).

### Bugs Doctrine/API Platform réels rencontrés et corrigés pendant l'implémentation

Ces points ne sont pas des choix de conception mais des comportements du framework découverts en
testant de bout en bout (API réelle + MariaDB), documentés ici car ils affectent la façon d'écrire du
DQL/des processors fiables ailleurs dans le module (et potentiellement dans de futurs lots) :

11. **`IN (:tableau)` DQL peu fiable avec un tableau d'entités/UUID** — `WHERE assoc IN (:liste)` avec
    une liste d'entités ou de `Symfony\Component\Uid\Uuid` liée via `setParameter()` ne s'est **pas**
    étendue correctement à l'exécution (un seul `?` généré, zéro résultat malgré des lignes
    correspondantes). Contournement retenu dans `ProjectionVenteDoctrineAdapter` : filtrage de
    l'établissement **en PHP** après un chargement simple (`WHERE statut = :statut` sans filtre
    d'établissement en DQL), le volume par profil restant modeste. Le motif classique `IDENTITY(x) =
    :param` avec un **scalaire unique** (pas de tableau) reste fiable et largement utilisé ailleurs
    dans le module.
12. **`getScalarResult()` ne convertit pas les types Doctrine personnalisés** — `select('e.champUuid')`
    suivi de `getScalarResult()` restitue la valeur **binaire brute** de la colonne `uuid` (pas
    d'appel à `UuidType::convertToPHPValue()`), contrairement à l'hydratation d'entité ou tableau
    complet. Ce comportement a cassé silencieusement l'idempotence de `GenerateurEcrituresHandler`
    (comparaison de chaînes jamais égales → doublons d'écritures à chaque rejeu). Corrigé en
    remplaçant `getScalarResult()` par une hydratation d'**entités** (`->getResult()`) partout où une
    colonne UUID scalaire (non association) est comparée en PHP après lecture.
13. **`numeroSequence` du chaînage NF525 exige un flush par écriture, pas un flush groupé** — scelller
    plusieurs écritures du même `(profilExploitant, journal)` dans une seule transaction non flushée
    fait que `dernierMaillon()` (une requête SQL) ne voit pas les écritures précédentes de la même
    boucle, provoquant une collision d'unicité `(profil, journal, numeroSequence)`. Corrigé par un
    `flush()` immédiat après chaque écriture scellée (`GenerateurEcrituresHandler::persisterEcriture`,
    `RepriseMensuellePcaHandler`, `RepriseAuPassageHandler`).
14. **L'empreinte NF525 doit être calculée sur un ordre déterministe des lignes** — `EcritureComptable
    ::$lignes` (OneToMany sans `#[ORM\OrderBy]`) peut être itérée dans un ordre différent entre le
    scellement (juste après construction en mémoire) et une vérification ultérieure (rechargée depuis
    la base) ; sans tri explicite par identifiant de ligne dans `payloadCanonique()`, l'empreinte
    recalculée ne correspondait pas à l'empreinte stockée (fausse rupture de chaîne, CA-13).
15. **Champs `#[ORM\Column]` non redimensionnés selon les valeurs enum réelles** — `EtalementPca
    ::$nature` déclaré `VARCHAR(16)` tronquait `NaturePca::ALaConsommation` (18 caractères),
    provoquant une erreur d'intégrité MySQL (1406) à l'insertion. Corrigé (`VARCHAR(24)`,
    migration `Version20260815013758`) — leçon : vérifier la longueur du **plus long** cas d'un enum
    PHP `string`-backed lors du dimensionnement d'une colonne, pas seulement un cas représentatif.
16. **Écriture scellée ≠ écriture figée pour toujours** — le listener d'inaltérabilité bloquait
    initialement **toute** modification post-scellement, y compris la transition légitime de `statut`
    (provisoire/contrôlée → validée → exportée, §4.10 spec) portée par le même flux d'API que le
    scellement initial. Corrigé : seul le champ `statut` reste modifiable après scellement
    (`EcritureInalterableListener::CHAMPS_AUTORISES_APRES_SCELLEMENT`) ; toute autre colonne
    (montants, comptes, taux, dates) reste bloquée, conforme à CA-7.
17. **`$uriVariables['id']` peut être livré déjà typé (`Uuid`) plutôt qu'en chaîne** — pour un
    `uriTemplate` avec `{id}` référençant une entité **différente** de la ressource API Platform
    porteuse de l'opération (ex. `{id}` = une `Vente` M2 sur une opération de la ressource
    `VenteImpayeeRegie`), API Platform peut résoudre la variable d'URI en objet `Uuid` (typé sur
    l'identifiant *de la ressource porteuse*, pas de la cible métier) plutôt qu'en chaîne brute. Les
    processors/providers concernés (`MarquerImpayeeRegieProcessor`, `RapprochementPcaProvider`)
    acceptent désormais les deux formes.
